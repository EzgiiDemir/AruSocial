import 'dart:async';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/config/place_catalog.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/chat_realtime_service.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/explore/explore_calendar_models.dart';
import 'package:arucad_campus_prototype/features/explore/explore_calendar_screen.dart';
import 'package:arucad_campus_prototype/features/explore/explore_category_card.dart';
import 'package:arucad_campus_prototype/features/explore/explore_clubs_screen.dart';
import 'package:arucad_campus_prototype/features/explore/explore_events_screen.dart';
import 'package:arucad_campus_prototype/features/explore/explore_food_screen.dart';
import 'package:arucad_campus_prototype/features/explore/explore_places_screen.dart';
import 'package:arucad_campus_prototype/features/explore/popular_places_screen.dart';
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
        out.add((Icons.location_on_rounded, place.name,
            normalizeCategory(place.category), _openPlaces));
      }
    }
    for (final event in _events) {
      if (event.title.toLowerCase().contains(q) ||
          event.placeName.toLowerCase().contains(q)) {
        out.add((Icons.local_activity_rounded, event.title,
            '${event.time} · ${event.placeName}', _openEvents));
      }
    }
    for (final club in _clubs) {
      if (club.name.toLowerCase().contains(q)) {
        out.add((Icons.diversity_3_rounded, club.name, club.category, _openClubs));
      }
    }
    for (final sport in _sports) {
      if (sport.name.toLowerCase().contains(q)) {
        out.add((Icons.sports_soccer_rounded, sport.name, sport.facility,
            _openSports));
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
    final events =
        await one(widget.repository.getEvents(), const <CampusEvent>[]);
    final clubs =
        await one(widget.repository.getClubs(), const <CampusClub>[]);
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

  bool _isToday(DateTime date) {
    final now = DateTime.now();
    return date.year == now.year && date.month == now.month && date.day == now.day;
  }

  int get _todayEventsCount =>
      _events.where((e) => e.eventDate != null && _isToday(e.eventDate!)).length;

  /// Busiest place by real recent check-ins. Students said they open this
  /// to find where people actually are, which is a different question from
  /// which building happens to be closest.
  CampusPlace? get _mostPopularPlace {
    CampusPlace? best;
    for (final place in _places) {
      if (place.recentCheckins <= 0) continue;
      if (best == null || place.recentCheckins > best.recentCheckins) {
        best = place;
      }
    }
    return best;
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

    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 760),
        child: RefreshIndicator(
          onRefresh: _load,
          child: CustomScrollView(
            slivers: [
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(20, 12, 8, 4),
                  child: Row(children: [
                    Expanded(
                      child: Text(strings.t('explore_title'),
                          style: Theme.of(context)
                              .textTheme
                              .headlineSmall
                              ?.copyWith(fontWeight: FontWeight.w900)),
                    ),
                    IconButton(
                      tooltip: strings.t('social_notifications'),
                      icon: const Icon(Icons.notifications_none_rounded),
                      onPressed: () => Navigator.of(context).push(
                          MaterialPageRoute(
                              builder: (_) => NotificationsScreen(
                                  repository: widget.repository))),
                    ),
                  ]),
                ),
              ),

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
                    onChanged: (value) =>
                        setState(() => _query = value.trim()),
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
                      contentPadding:
                          const EdgeInsets.symmetric(vertical: 14, horizontal: 8),
                    ),
                  ),
                ),
              ),

              // While a query is active the suggestions replace the category
              // grid: showing both would mean scrolling past the grid to
              // reach the answer to what you just typed.
              if (_query.isNotEmpty) ..._searchSlivers(strings),
              if (_query.isEmpty) ...[
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(20, 0, 20, 4),
                  child: Text(
                      '$_placesCount ${strings.t('explore_places_suffix')}',
                      style: const TextStyle(
                          color: ArucadColors.muted, fontSize: 12)),
                ),
              ),
              SliverPadding(
                padding: const EdgeInsets.fromLTRB(20, 12, 20, 28),
                sliver: SliverGrid(
                  gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
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
                          builder: (_) =>
                              ExploreFoodScreen(repository: widget.repository),
                        ),
                      ),
                    ),
                  ]),
                ),
              ),
              SliverPadding(
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 28),
                sliver: SliverToBoxAdapter(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(strings.t('explore_recommended'),
                          style: const TextStyle(
                              fontWeight: FontWeight.w900, fontSize: 15)),
                      const SizedBox(height: 10),
                      _RecommendationTile(
                        icon: Icons.event_available_rounded,
                        accent: ArucadColors.blue,
                        title: strings.t('explore_recommend_today_events'),
                        subtitle: _todayEventsCount > 0
                            ? '$_todayEventsCount ${strings.t('explore_recommend_today_events_suffix')}'
                            : strings.t('explore_recommend_today_events_empty'),
                        onTap: () => _openCalendar(),
                      ),
                      const SizedBox(height: 10),
                      Builder(builder: (context) {
                        final popular = _mostPopularPlace;
                        return _RecommendationTile(
                          icon: Icons.local_fire_department_rounded,
                          accent: ArucadColors.campusGreen,
                          title: strings.t('explore_recommend_popular_places'),
                          subtitle: popular == null
                              ? strings.t('explore_recommend_popular_places_empty')
                              : '${popular.name} · ${popular.recentCheckins} ${strings.t('explore_recommend_popular_suffix')}',
                          onTap: () => Navigator.of(context).push(
                            MaterialPageRoute(
                              builder: (_) => PopularPlacesScreen(
                                repository: widget.repository,
                                mapProvider: widget.mapProvider,
                                analyticsTracker: widget.analyticsTracker,
                              ),
                            ),
                          ),
                        );
                      }),
                    ],
                  ),
                ),
              ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// Searches everything the Explore hub already has in memory. No network
/// call: the hub loads places, events, clubs and sports on entry, so typing
/// filters instantly instead of waiting on a round trip per keystroke.

class _RecommendationTile extends StatelessWidget {
  final IconData icon;
  final Color accent;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  const _RecommendationTile({
    required this.icon,
    required this.accent,
    required this.title,
    required this.subtitle,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return Material(
      color: ArucadColors.paper,
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          child: Row(children: [
            Container(
              width: 38,
              height: 38,
              decoration: BoxDecoration(
                  color: accent.withValues(alpha: .12),
                  borderRadius: BorderRadius.circular(12)),
              child: Icon(icon, color: accent, size: 20),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title,
                      style: const TextStyle(
                          fontWeight: FontWeight.w800, fontSize: 13.5)),
                  const SizedBox(height: 2),
                  Text(subtitle,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                          color: ArucadColors.muted, fontSize: 11.5)),
                ],
              ),
            ),
            const Icon(Icons.chevron_right, color: ArucadColors.muted),
          ]),
        ),
      ),
    );
  }
}
