import 'dart:async';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
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
import 'package:arucad_campus_prototype/features/explore/explore_sports_screen.dart';
import 'package:arucad_campus_prototype/features/home/campus_live_map.dart';
import 'package:arucad_campus_prototype/features/services/career_hub_screen.dart';
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
  int _placesCount = 0;
  int _eventsCount = 0;
  int _clubsCount = 0;
  int _sportsCount = 0;
  int _foodCount = 0;
  int _calendarBadge = 0;
  ChatRealtimeService? _realtime;
  StreamSubscription<List<String>>? _campusChanges;
  bool _refreshing = false;

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
                  padding: const EdgeInsets.fromLTRB(20, 18, 20, 8),
                  child: Row(children: [
                    Expanded(
                      child: Text(strings.t('explore_title'),
                          style: Theme.of(context)
                              .textTheme
                              .headlineSmall
                              ?.copyWith(fontWeight: FontWeight.w900)),
                    ),
                    Text(
                        '$_placesCount ${strings.t('explore_places_suffix')}',
                        style: const TextStyle(
                            color: ArucadColors.muted, fontSize: 12)),
                  ]),
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
            ],
          ),
        ),
      ),
    );
  }
}
