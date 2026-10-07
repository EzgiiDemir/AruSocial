import 'dart:async';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/config/place_catalog.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/academic_year.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/chat_realtime_service.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';
import 'package:arucad_campus_prototype/features/home/create_own_activity_screen.dart';
import 'package:arucad_campus_prototype/features/explore/explore_calendar_models.dart';
import 'package:arucad_campus_prototype/features/explore/explore_calendar_screen.dart';
import 'package:arucad_campus_prototype/features/explore/explore_category_card.dart';
import 'package:arucad_campus_prototype/features/explore/explore_clubs_screen.dart';
import 'package:arucad_campus_prototype/features/explore/explore_events_screen.dart';
import 'package:arucad_campus_prototype/features/explore/explore_food_screen.dart';
import 'package:arucad_campus_prototype/features/explore/explore_places_screen.dart';
import 'package:arucad_campus_prototype/features/explore/explore_sports_screen.dart';
import 'package:arucad_campus_prototype/features/home/campus_live_map.dart';
import 'package:arucad_campus_prototype/features/services/career_hub_screen.dart';
import 'package:arucad_campus_prototype/features/social/notifications_screen.dart';
import 'package:arucad_campus_prototype/features/services/need_help_screen.dart';

/// Hub icon accents cycle: red → blue → yellow → green.
const _hubAccents = [
  ArucadColors.red,
  ArucadColors.blue,
  ArucadColors.yellow,
  ArucadColors.campusGreen,
];

class ExploreScreen extends StatefulWidget {
  final CampusRepository repository;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;
  final VoidCallback onAI;
  final CampusVisibility initialVisibility;

  const ExploreScreen({
    super.key,
    required this.repository,
    required this.mapProvider,
    required this.analyticsTracker,
    required this.onAI,
    this.initialVisibility = CampusVisibility.friends,
  });

  @override
  State<ExploreScreen> createState() => _ExploreScreenState();
}

class _ExploreScreenState extends State<ExploreScreen> {
  bool _loading = true;
  List<CampusPlace> _places = const [];
  List<CampusEvent> _events = const [];
  List<CampusClub> _clubs = const [];
  List<CampusSport> _sports = const [];
  int _placesCount = 0;
  int _eventsCount = 0;
  int _clubsCount = 0;
  int _sportsCount = 0;
  int _foodCount = 0;
  int _calendarBadge = 0;
  ChatRealtimeService? _realtime;
  StreamSubscription<List<String>>? _campusChanges;
  bool _refreshing = false;
  final _searchC = TextEditingController();
  String _query = '';
  int _visibleEvents = kPageSize;
  List<AcademicYear> _academicYears = const [];
  String? _selectedYearId;

  /// Forward-looking, and undated events stay in so seeded content is not
  /// silently dropped — the same rule Home used when this list lived there.
  List<CampusEvent> get _upcomingEvents {
    final now = DateTime.now();
    final start = DateTime(now.year, now.month, now.day);
    final upcoming = _events.where((e) {
      final date = e.eventDate;
      if (date == null) return true;

      return !date.isBefore(start);
    }).toList();
    upcoming.sort((a, b) {
      final ad = a.eventDate;
      final bd = b.eventDate;
      if (ad == null && bd == null) return 0;
      if (ad == null) return 1;
      if (bd == null) return -1;

      return ad.compareTo(bd);
    });

    return upcoming.isNotEmpty ? upcoming : _events;
  }

  /// Matches across everything the hub can open, so one box answers
  /// "where is X" whether X is a building, an event, a club or a team.
  ///
  /// Each hit carries the screen it belongs to, because a result is only
  /// useful if tapping it lands somewhere — a list of names that does
  /// nothing is worse than no search at all.
  List<(IconData, String, String, VoidCallback)> get _matches {
    final q = _query.toLowerCase();
    if (q.isEmpty) return const [];

    final out = <(IconData, String, String, VoidCallback)>[];
    for (final place in _places) {
      if (place.name.toLowerCase().contains(q) ||
          place.category.toLowerCase().contains(q)) {
        out.add((
          Icons.location_on_rounded,
          place.name,
          normalizeCategory(place.category),
          _openPlaces
        ));
      }
    }
    for (final event in _events) {
      if (event.title.toLowerCase().contains(q) ||
          event.placeName.toLowerCase().contains(q)) {
        out.add((
          Icons.local_activity_rounded,
          event.title,
          '${event.time} · ${event.placeName}',
          _openEvents
        ));
      }
    }
    for (final club in _clubs) {
      if (club.name.toLowerCase().contains(q)) {
        out.add(
            (Icons.diversity_3_rounded, club.name, club.category, _openClubs));
      }
    }
    for (final sport in _sports) {
      if (sport.name.toLowerCase().contains(q)) {
        out.add((
          Icons.sports_soccer_rounded,
          sport.name,
          sport.facility,
          _openSports
        ));
      }
    }

    return out.take(30).toList();
  }

  List<Widget> _searchSlivers(AppStrings strings) {
    final results = _matches;
    if (results.isEmpty) {
      return [
        SliverToBoxAdapter(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 40, 20, 40),
            child: Column(children: [
              const Icon(Icons.search_off_rounded,
                  size: 40, color: ArucadColors.muted),
              const SizedBox(height: 12),
              Text(strings.t('clm_no_results'),
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: ArucadColors.muted)),
            ]),
          ),
        ),
      ];
    }

    return [
      SliverList.builder(
        itemCount: results.length,
        itemBuilder: (_, i) {
          final (icon, title, subtitle, onTap) = results[i];

          return ListTile(
            leading: CircleAvatar(
              radius: 18,
              backgroundColor: ArucadColors.primary.withValues(alpha: .09),
              child: Icon(icon, size: 18, color: ArucadColors.primary),
            ),
            title: Text(title,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontWeight: FontWeight.w700)),
            subtitle: subtitle.isEmpty
                ? null
                : Text(subtitle, maxLines: 1, overflow: TextOverflow.ellipsis),
            trailing: const Icon(Icons.chevron_right_rounded),
            onTap: onTap,
          );
        },
      ),
      const SliverToBoxAdapter(child: SizedBox(height: 24)),
    ];
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  /// Refetches only the events; the rest of the hub does not depend on the
  /// selected year, so a full `_load()` would flicker every other section.
  Future<void> _changeYear(String? yearId) async {
    setState(() {
      _selectedYearId = yearId;
      _visibleEvents = kPageSize;
    });
    try {
      final events = await widget.repository.getEvents(academicYearId: yearId);
      if (mounted) setState(() => _events = events);
    } catch (_) {}
  }

  Future<void> _load() async {
    Future<T> one<T>(Future<T> future, T fallback) async {
      try {
        return await future;
      } catch (_) {
        return fallback;
      }
    }

    final places =
        await one(widget.repository.getPlaces(), const <CampusPlace>[]);
    final events = await one(
        widget.repository.getEvents(academicYearId: _selectedYearId),
        const <CampusEvent>[]);
    final years = await one(
        widget.repository.getAcademicYears(), const <AcademicYear>[]);
    final clubs = await one(widget.repository.getClubs(), const <CampusClub>[]);
    final sports =
        await one(widget.repository.getSports(), const <CampusSport>[]);
    final food =
        await one(widget.repository.getFoodVenues(), const <CampusFoodVenue>[]);
    final calendar = await loadExploreCalendarItems(widget.repository);

    CampusUser? me;
    try {
      me = await widget.repository.getMe();
    } catch (_) {}

    if (!mounted) return;
    setState(() {
      _places = places;
      _events = events;
      _academicYears = years;
      _clubs = clubs;
      _sports = sports;
      _placesCount = places.length;
      _eventsCount = events.length;
      _clubsCount = clubs.length;
      _sportsCount = sports.length;
      _foodCount = food.length;
      _calendarBadge = upcomingExploreItems(calendar, limit: 99).length;
      _loading = false;
    });
    if (me != null) unawaited(_startRealtime(me));
  }

  Future<void> _startRealtime(CampusUser me) async {
    if (_realtime != null) return;
    final realtime = ChatRealtimeService.forRepository(widget.repository);
    _realtime = realtime;
    _campusChanges = realtime.campusChanged.listen((resources) {
      if (resources.contains('events') ||
          resources.contains('places') ||
          resources.contains('appointments')) {
        unawaited(_softRefresh());
      }
    });
    await realtime.start(userId: me.id, userName: me.name);
  }

  Future<void> _softRefresh() async {
    if (_refreshing) return;
    _refreshing = true;
    try {
      await _load();
    } finally {
      _refreshing = false;
    }
  }

  @override
  void dispose() {
    unawaited(_campusChanges?.cancel() ?? Future.value());
    unawaited(_realtime?.dispose() ?? Future.value());
    super.dispose();
  }

  /// One search box over everything the hub already loaded — places,
  /// events, clubs and sports — so a student who knows the name of a thing
  /// does not have to guess which of the eight cards it lives behind.
  void _openPlaces() => Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => ExplorePlacesScreen(
          repository: widget.repository,
          mapProvider: widget.mapProvider,
          analyticsTracker: widget.analyticsTracker,
        ),
      ));

  void _openEvents() => Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => ExploreEventsScreen(
          repository: widget.repository,
          mapProvider: widget.mapProvider,
          analyticsTracker: widget.analyticsTracker,
        ),
      ));

  void _openClubs() => Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => ExploreClubsScreen(repository: widget.repository),
      ));

  void _openSports() => Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => ExploreSportsScreen(repository: widget.repository),
      ));

  void _openCalendar({DateTime? day}) {
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => ExploreCalendarScreen(
        repository: widget.repository,
        mapProvider: widget.mapProvider,
        analyticsTracker: widget.analyticsTracker,
        initialDay: day,
      ),
    ));
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    final strings = AppLocale.of(context);
    Color accentAt(int i) => _hubAccents[i % _hubAccents.length];

    return Column(
      children: [
        CampusPageHeader(
          title: strings.t('nav_explore'),
          actions: [
            IconButton(
              tooltip: strings.t('social_notifications'),
              icon: const Icon(Icons.notifications_none_rounded),
              onPressed: () => Navigator.of(context).push(MaterialPageRoute(
                  builder: (_) => NotificationsScreen(
                      repository: widget.repository))),
            ),
          ],
        ),
        Expanded(
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 760),
              child: RefreshIndicator(
                onRefresh: _load,
                child: CustomScrollView(
            slivers: [
              // Search is a bar, not an icon that opens a sheet. Someone
              // looking for a room does not first have to discover that the
              // magnifier is where searching lives, and results appear under
              // what they typed instead of in a panel that covers the page.
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(20, 4, 20, 8),
                  child: TextField(
                    controller: _searchC,
                    textInputAction: TextInputAction.search,
                    onChanged: (value) => setState(() => _query = value.trim()),
                    decoration: InputDecoration(
                      hintText: strings.t('explore_search_hint'),
                      prefixIcon: const Icon(Icons.search_rounded, size: 20),
                      suffixIcon: _query.isEmpty
                          ? null
                          : IconButton(
                              tooltip: strings.t('sf_clear_search'),
                              icon: const Icon(Icons.close_rounded, size: 18),
                              onPressed: () {
                                _searchC.clear();
                                setState(() => _query = '');
                              },
                            ),
                      isDense: true,
                      filled: true,
                      fillColor: Theme.of(context).colorScheme.surface,
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(999),
                        borderSide: BorderSide.none,
                      ),
                      contentPadding: const EdgeInsets.symmetric(
                          vertical: 14, horizontal: 8),
                    ),
                  ),
                ),
              ),

              // While a query is active the suggestions replace the category
              // grid: showing both would mean scrolling past the grid to
              // reach the answer to what you just typed.
              if (_query.isNotEmpty) ..._searchSlivers(strings),
              if (_query.isEmpty) ...[
                SliverPadding(
                  padding: const EdgeInsets.fromLTRB(20, 12, 20, 28),
                  sliver: SliverGrid(
                    gridDelegate:
                        const SliverGridDelegateWithFixedCrossAxisCount(
                      crossAxisCount: 2,
                      mainAxisSpacing: 12,
                      crossAxisSpacing: 12,
                      mainAxisExtent: 140,
                    ),
                    delegate: SliverChildListDelegate([
                      ExploreCategoryCard(
                        title: strings.t('discover_calendar'),
                        subtitle: strings.t('explore_cat_calendar_sub'),
                        icon: Icons.calendar_month_rounded,
                        accent: accentAt(0),
                        badge: _calendarBadge > 0 ? '$_calendarBadge' : null,
                        onTap: () => _openCalendar(),
                      ),
                      ExploreCategoryCard(
                        title: strings.t('discover_creative'),
                        subtitle: strings.t('explore_cat_events_sub'),
                        icon: Icons.local_activity_rounded,
                        accent: accentAt(1),
                        badge: _eventsCount > 0 ? '$_eventsCount' : null,
                        onTap: () => Navigator.of(context).push(
                          MaterialPageRoute(
                            builder: (_) => ExploreEventsScreen(
                              repository: widget.repository,
                              mapProvider: widget.mapProvider,
                              analyticsTracker: widget.analyticsTracker,
                            ),
                          ),
                        ),
                      ),
                      ExploreCategoryCard(
                        title: strings.t('discover_clubs'),
                        subtitle: strings.t('explore_cat_clubs_sub'),
                        icon: Icons.diversity_3_rounded,
                        accent: accentAt(2),
                        badge: _clubsCount > 0 ? '$_clubsCount' : null,
                        onTap: () => Navigator.of(context).push(
                          MaterialPageRoute(
                            builder: (_) => ExploreClubsScreen(
                                repository: widget.repository),
                          ),
                        ),
                      ),
                      ExploreCategoryCard(
                        title: strings.t('discover_sports'),
                        subtitle: strings.t('explore_cat_sports_sub'),
                        icon: Icons.sports_soccer_rounded,
                        accent: accentAt(3),
                        badge: _sportsCount > 0 ? '$_sportsCount' : null,
                        onTap: () => Navigator.of(context).push(
                          MaterialPageRoute(
                            builder: (_) => ExploreSportsScreen(
                                repository: widget.repository),
                          ),
                        ),
                      ),
                      ExploreCategoryCard(
                        title: strings.t('discover_services'),
                        subtitle: strings.t('help_campus_services_sub'),
                        icon: Icons.support_agent_rounded,
                        accent: accentAt(4),
                        onTap: () => Navigator.of(context).push(
                          MaterialPageRoute(
                            builder: (_) =>
                                NeedHelpScreen(repository: widget.repository),
                          ),
                        ),
                      ),
                      ExploreCategoryCard(
                        title: strings.t('discover_career'),
                        subtitle: strings.t('career_opportunities'),
                        icon: Icons.work_rounded,
                        accent: accentAt(5),
                        onTap: () => Navigator.of(context).push(
                          MaterialPageRoute(
                            builder: (_) =>
                                CareerHubScreen(repository: widget.repository),
                          ),
                        ),
                      ),
                      ExploreCategoryCard(
                        title: strings.t('discover_places'),
                        subtitle: strings.t('explore_cat_places_sub'),
                        icon: Icons.location_on_rounded,
                        accent: accentAt(6),
                        badge: _placesCount > 0 ? '$_placesCount' : null,
                        onTap: () => Navigator.of(context).push(
                          MaterialPageRoute(
                            builder: (_) => ExplorePlacesScreen(
                              repository: widget.repository,
                              mapProvider: widget.mapProvider,
                              analyticsTracker: widget.analyticsTracker,
                            ),
                          ),
                        ),
                      ),
                      ExploreCategoryCard(
                        title: strings.t('discover_food'),
                        subtitle: strings.t('explore_cat_food_sub'),
                        icon: Icons.ramen_dining_rounded,
                        accent: accentAt(7),
                        badge: _foodCount > 0 ? '$_foodCount' : null,
                        onTap: () => Navigator.of(context).push(
                          MaterialPageRoute(
                            builder: (_) => ExploreFoodScreen(
                                repository: widget.repository),
                          ),
                        ),
                      ),
                    ]),
                  ),
                ),
                // Upcoming events, moved here from Home. Explore is where
                // someone goes looking for something to do, so the list of
                // what is actually on belongs on this page rather than in
                // the middle of a timeline.
                SliverPadding(
                  padding: const EdgeInsets.fromLTRB(20, 0, 20, 28),
                  sliver: SliverToBoxAdapter(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(children: [
                          Expanded(
                            child: Text(strings.t('home_today_events'),
                                style: const TextStyle(
                                    fontWeight: FontWeight.w900, fontSize: 15)),
                          ),
                          TextButton(
                            onPressed: _openEvents,
                            child: Text(strings.t('home_all')),
                          ),
                        ]),
                        const SizedBox(height: 6),
                        SizedBox(
                          width: double.infinity,
                          child: OutlinedButton.icon(
                            onPressed: () async {
                              final created =
                                  await Navigator.of(context).push<bool>(
                                MaterialPageRoute(
                                  builder: (_) => CreateOwnActivityScreen(
                                      repository: widget.repository),
                                ),
                              );
                              if (created == true) _load();
                            },
                            style: OutlinedButton.styleFrom(
                              backgroundColor: Colors.white,
                              foregroundColor:
                                  Theme.of(context).colorScheme.onSurface,
                              side: BorderSide(
                                  color:
                                      Theme.of(context).colorScheme.onSurface,
                                  width: 1.2),
                              shape: const StadiumBorder(),
                              padding: const EdgeInsets.symmetric(vertical: 12),
                            ),
                            icon:
                                const Icon(Icons.add_circle_outline, size: 18),
                            label: Text(strings.t('home_create_activity')),
                          ),
                        ),
                        if (_academicYears.length > 1) ...[
                          const SizedBox(height: ArucadSpacing.sm),
                          SizedBox(
                            height: 34,
                            child: ListView(
                              scrollDirection: Axis.horizontal,
                              children: [
                                SelectableChip(
                                  label: strings.t('home_all_years'),
                                  selected: _selectedYearId == null,
                                  onSelected: (_) => _changeYear(null),
                                ),
                                const SizedBox(width: 6),
                                for (final year in _academicYears) ...[
                                  SelectableChip(
                                    label: year.label,
                                    selected: _selectedYearId == year.id,
                                    onSelected: (_) => _changeYear(year.id),
                                  ),
                                  const SizedBox(width: 6),
                                ],
                              ],
                            ),
                          ),
                        ],
                        const SizedBox(height: 12),
                        if (_upcomingEvents.isEmpty)
                          Padding(
                            padding: const EdgeInsets.symmetric(vertical: 24),
                            child: Center(
                              child: Text(strings.t('home_no_events'),
                                  style: const TextStyle(
                                      color: ArucadColors.muted)),
                            ),
                          )
                        else ...[
                          for (int i = 0;
                              i <
                                  _visibleEvents.clamp(
                                      0, _upcomingEvents.length);
                              i++)
                            Padding(
                              padding: EdgeInsets.only(top: i == 0 ? 0 : 10),
                              child: EventCard(
                                  event: _upcomingEvents[i],
                                  accentColor: brandAccentAt(i),
                                  repository: widget.repository,
                                  mapProvider: widget.mapProvider,
                                  analyticsTracker: widget.analyticsTracker),
                            ),
                          LoadMoreButton(
                            shown:
                                _visibleEvents.clamp(0, _upcomingEvents.length),
                            total: _upcomingEvents.length,
                            itemLabel: strings.t('home_event_item'),
                            showCompleteLabel: false,
                            onTap: () =>
                                setState(() => _visibleEvents += kPageSize),
                          ),
                        ],
                      ],
                    ),
                  ),
                ),
              ],
            ],
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }
}
