import 'dart:async';
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/campus_weather.dart';
import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/location_service.dart';
import 'package:arucad_campus_prototype/core/services/chat_realtime_service.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/core/utils/relative_time.dart';
import 'package:arucad_campus_prototype/features/home/campus_live_map.dart';
import 'package:arucad_campus_prototype/features/home/greeting_card.dart';
import 'package:arucad_campus_prototype/features/home/survey_popup.dart';
import 'package:arucad_campus_prototype/features/map/in_app_navigation_screen.dart';
import 'package:arucad_campus_prototype/features/place/place_detail_screen.dart';
import 'package:arucad_campus_prototype/features/explore/popular_places_screen.dart';
import 'package:arucad_campus_prototype/features/services/service_detail_screen.dart';
import 'package:arucad_campus_prototype/features/social/notifications_screen.dart';
import 'package:arucad_campus_prototype/features/social/post_detail_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';
import 'package:arucad_campus_prototype/features/widgets/recommendation_tile.dart';

/// Home is a live timeline, not a map. The map is a real, useful service —
/// it just isn't the *primary* experience anymore: it's one card away
/// (Campus Pulse → Open Map), not the first thing that loads.
class HomeScreen extends StatefulWidget {
  final CampusUser user;
  final CampusRepository repository;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;
  final VoidCallback onExplore;
  final VoidCallback onQuests;
  final VoidCallback onAI;
  final VoidCallback onSocial;
  final VoidCallback onLogout;
  final CampusVisibility initialVisibility;
  final bool showForYou;

  const HomeScreen({
    super.key,
    required this.user,
    required this.repository,
    required this.mapProvider,
    required this.analyticsTracker,
    required this.onExplore,
    required this.onQuests,
    required this.onAI,
    required this.onSocial,
    required this.onLogout,
    this.initialVisibility = CampusVisibility.friends,
    this.showForYou = true,
  });

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> with WidgetsBindingObserver {
  List<CampusEvent> _events = const [];
  List<CampusPlace> _places = const [];
  List<ActivityItem> _activity = const [];
  List<FeedPost> _feed = const [];
  List<CampusService> _services = const [];
  bool _loading = true;
  String? _loadError;
  Position? _myPosition;
  ChatRealtimeService? _realtime;
  StreamSubscription<List<String>>? _campusChanges;
  StreamSubscription<Position>? _positionSub;
  StreamSubscription<void>? _grantedSub;
  bool _refreshingEvents = false;
  bool _refreshingCatalog = false;
  int _unreadNotifs = 0;
  CampusWeather? _weather;
  bool _weatherLoading = true;
  static const _location = LocationService();

  /// `/feed` is ordered by pinned state first for the social screen. Home's
  /// “Kampüste Şimdi” instead shows the three latest published posts.
  List<FeedPost> get _latestSocialPosts {
    final posts = [..._feed];
    posts.sort((a, b) {
      final aTime = a.createdAt ?? DateTime.fromMillisecondsSinceEpoch(0);
      final bTime = b.createdAt ?? DateTime.fromMillisecondsSinceEpoch(0);
      return bTime.compareTo(aTime);
    });
    return posts.take(3).toList(growable: false);
  }

  int get _todayEventsCount {
    final now = DateTime.now();
    return _events.where((event) {
      final date = event.eventDate;
      return date != null &&
          date.year == now.year &&
          date.month == now.month &&
          date.day == now.day;
    }).length;
  }

  CampusPlace? get _mostPopularPlace {
    CampusPlace? best;
    for (final place in _places) {
      if (place.totalCheckins <= 0) continue;
      if (best == null || place.totalCheckins > best.totalCheckins) {
        best = place;
      }
    }
    return best;
  }

  /// Prefer dated upcoming events so the home list stays forward-looking.
  /// Events without a date stay included so seed/demo content is not empty.
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

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _load();
    _grantedSub = LocationService.onGranted.listen((_) {
      unawaited(_attachLiveLocation());
    });
    unawaited(_attachLiveLocation());
    _startRealtime();
    unawaited(_refreshUnread());
    unawaited(_loadWeather());

    // Surface an unanswered campus survey once Home has painted. Silent when
    // there is nothing pending, so this costs nothing on a normal launch.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) {
        unawaited(
            maybeShowSurveyPopup(context, widget.repository, silent: true));
      }
    });
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      unawaited(_resolvePositionOnResume());
    }
  }

  Future<void> _startRealtime() async {
    final realtime = ChatRealtimeService.forRepository(widget.repository);
    _realtime = realtime;
    _campusChanges = realtime.campusChanged.listen((resources) {
      if (resources.contains('events')) unawaited(_refreshEvents());
      if (resources.contains('places') || resources.contains('services')) {
        unawaited(_refreshCatalog(resources));
      }
    });
    await realtime.start(userId: widget.user.id, userName: widget.user.name);
  }

  Future<void> _refreshEvents() async {
    if (_refreshingEvents) return;
    _refreshingEvents = true;
    try {
      final events = await widget.repository.getEvents();
      if (mounted) setState(() => _events = events);
    } catch (_) {
      // The current view stays usable; pull-to-refresh is the REST fallback.
    } finally {
      _refreshingEvents = false;
    }
  }

  Future<void> _loadWeather() async {
    if (mounted) setState(() => _weatherLoading = true);
    try {
      final weather = await widget.repository.getWeather();
      if (mounted) setState(() => _weather = weather);
    } catch (_) {
      // Keep the weather affordance visible with an honest unavailable
      // state; silently removing it made a temporary provider outage look
      // like the feature itself had been deleted.
    } finally {
      if (mounted) setState(() => _weatherLoading = false);
    }
  }

  Future<void> _refreshUnread() async {
    try {
      final items = await widget.repository.getInboxNotifications();
      if (!mounted) return;
      setState(() => _unreadNotifs = items.where((n) => !n.read).length);
    } catch (_) {
      // A badge is not worth surfacing an error for; the bell still works.
    }
  }

  Future<void> _openNotifications() async {
    await Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => NotificationsScreen(repository: widget.repository),
    ));
    // Opening the screen marks them read, so the badge has to catch up.
    await _refreshUnread();
  }

  Future<void> _refreshCatalog(List<String> resources) async {
    if (_refreshingCatalog) return;
    _refreshingCatalog = true;
    try {
      final wantsPlaces = resources.contains('places');
      final wantsServices = resources.contains('services');
      final values = await Future.wait([
        if (wantsPlaces) widget.repository.getPlaces(),
        if (wantsServices) widget.repository.getServices(),
      ]);
      if (!mounted) return;
      var offset = 0;
      setState(() {
        if (wantsPlaces) _places = values[offset++] as List<CampusPlace>;
        if (wantsServices) _services = values[offset++] as List<CampusService>;
      });
    } catch (_) {
      // Existing cards remain visible; a manual refresh is still available.
    } finally {
      _refreshingCatalog = false;
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    unawaited(_positionSub?.cancel() ?? Future.value());
    unawaited(_grantedSub?.cancel() ?? Future.value());
    unawaited(_campusChanges?.cancel() ?? Future.value());
    unawaited(_realtime?.dispose() ?? Future.value());
    super.dispose();
  }

  Future<void> _load() async {
    unawaited(_loadWeather());
    // Two waves: `php artisan serve` on Windows is single-threaded
    // (PHP_CLI_SERVER_WORKERS cannot fork), so 7 parallel GETs after
    // login used to hit the client timeout while queued behind each other.
    try {
      final first = await Future.wait([
        widget.repository.getEvents(),
        widget.repository.getPlaces(),
        widget.repository.getServices(),
      ]);
      if (!mounted) return;
      final second = await Future.wait([
        widget.repository.getMyActivity(),
        widget.repository.getFeed(),
      ]);
      if (!mounted) return;
      setState(() {
        _events = first[0] as List<CampusEvent>;
        _places = first[1] as List<CampusPlace>;
        _services = first[2] as List<CampusService>;
        _activity = second[0] as List<ActivityItem>;
        _feed = second[1] as List<FeedPost>;
        _loading = false;
        _loadError = null;
      });
    } catch (e) {
      if (!mounted) return;
      final message = e is ApiClientException ? e.message : '$e';
      if (_places.isNotEmpty || _events.isNotEmpty) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(message)));
        return;
      }
      setState(() {
        _loading = false;
        _loadError = message;
      });
    }
  }

  Future<void> _resolvePositionOnResume() async {
    if (!await _location.hasGranted()) return;
    await _resolvePosition();
    if (_positionSub == null) await _listenPositionStream();
  }

  Future<void> _attachLiveLocation() async {
    if (!await _location.hasGranted()) return;
    await _resolvePosition();
    await _listenPositionStream();
  }

  Future<void> _resolvePosition() async {
    try {
      final position = await _location.getCurrentPositionIfGranted();
      if (position == null || !mounted) return;
      setState(() => _myPosition = position);
    } catch (_) {
      // Nearby is a bonus on top of the timeline — no position just means
      // that one card doesn't show, everything else still works.
    }
  }

  Future<void> _listenPositionStream() async {
    if (_positionSub != null) return;
    if (!await _location.hasGranted()) return;
    _positionSub = _location.positionStream().listen(
      (position) {
        if (!mounted) return;
        setState(() => _myPosition = position);
      },
      onError: (_) {},
    );
  }

  CampusPlace? get _nearestPlace {
    final pos = _myPosition;
    if (pos == null || _places.isEmpty) return null;
    var best = _places.first;
    var bestMeters = Geolocator.distanceBetween(
        pos.latitude, pos.longitude, best.lat, best.lng);
    for (final place in _places.skip(1)) {
      final meters = Geolocator.distanceBetween(
          pos.latitude, pos.longitude, place.lat, place.lng);
      if (meters < bestMeters) {
        bestMeters = meters;
        best = place;
      }
    }
    return best;
  }

  double? _distanceTo(CampusPlace place) {
    final pos = _myPosition;
    if (pos == null) return null;
    return Geolocator.distanceBetween(
        pos.latitude, pos.longitude, place.lat, place.lng);
  }

  Set<String> get _visitedPlaceNames => _activity
      .where((a) => a.kind == ActivityKind.checkIn)
      .map((a) => a.title.replaceFirst('Check-in: ', '').toLowerCase())
      .toSet();

  static const _homeServiceIds = [
    'student-affairs',
    'library',
    'it',
    'pdr',
    'career',
    'international',
  ];

  /// A handful of the most-reached-for services, so "I need help" is one
  /// tap from Home instead of three taps into Discover → Kampüs Hizmetleri.
  List<CampusService> get _homeServiceShortcuts {
    final byId = {for (final s in _services) s.id: s};
    final picked = <CampusService>[
      for (final id in _homeServiceIds)
        if (byId[id] != null && _homeServiceIds.indexOf(id) < 4) byId[id]!,
    ];
    for (final s in _services) {
      if (picked.length >= 4) break;
      if (!picked.contains(s)) picked.add(s);
    }
    return picked;
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_loadError != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.wifi_off_outlined,
                  size: 36,
                  color: Theme.of(context).colorScheme.onSurfaceVariant),
              const SizedBox(height: 12),
              Text(AppLocale.of(context).t('home_load_failed'),
                  textAlign: TextAlign.center,
                  style: const TextStyle(fontWeight: FontWeight.w700)),
              const SizedBox(height: 6),
              Text(_loadError!,
                  textAlign: TextAlign.center,
                  style: TextStyle(
                      color: Theme.of(context).colorScheme.onSurfaceVariant,
                      fontSize: 13)),
              const SizedBox(height: 16),
              FilledButton(
                onPressed: () {
                  setState(() {
                    _loading = true;
                    _loadError = null;
                  });
                  _load();
                },
                child: Text(AppLocale.of(context).t('common_retry')),
              ),
            ],
          ),
        ),
      );
    }
    final strings = AppLocale.of(context);
    final pos = _myPosition;
    final nearest = _nearestPlace;
    final nearestMeters = nearest == null ? null : _distanceTo(nearest);
    // A recommendation is a place the student has not checked in to yet.
    // Some campus data sources can list the same place more than once (for
    // example, a building and its entrance), so retain a single row per
    // normalized name before selecting the three recommendations.
    final recommendedNames = <String>{};
    final forYou = _places
        .where((p) {
          final normalizedName = p.name.trim().toLowerCase();
          return !_visitedPlaceNames.contains(normalizedName) &&
              recommendedNames.add(normalizedName);
        })
        .take(3)
        .toList();
    final unvisited = forYou.isEmpty ? null : forYou.first;

    return Column(
      children: [
        CampusPageHeader(
          title: 'ARUVERSE',
          leading: const SizedBox(
            width: 36,
            height: 40,
            child: BrandMark(
              height: 36,
              showWordmark: false,
            ),
          ),
          actions: [
            _ScoreChip(xp: widget.user.xp, onTap: widget.onQuests),
            _NotificationBell(
              unread: _unreadNotifs,
              onTap: _openNotifications,
            ),
            IconButton(
              visualDensity: VisualDensity.compact,
              constraints: const BoxConstraints(minWidth: 44, minHeight: 44),
              onPressed: widget.onLogout,
              icon: const Icon(Icons.logout, color: ArucadColors.ink),
              tooltip: strings.t('common_logout'),
            ),
          ],
        ),
        Expanded(
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 760),
              child: RefreshIndicator(
                onRefresh: _load,
                child: ListView(
                  padding: const EdgeInsets.symmetric(
                      horizontal: ArucadSpacing.md, vertical: ArucadSpacing.sm),
                  children: [
                    const SizedBox(height: ArucadSpacing.lg),

                    // GREETING — replaces the old feedback tile. Surveys still
                    // reach students, but as a prompt when one is actually
                    // waiting rather than as a permanent row asking for input.
                    GreetingCard(
                      userName: widget.user.name,
                      weather: _weather,
                      weatherLoading: _weatherLoading,
                    ),
                    const SizedBox(height: ArucadSpacing.lg),

                    // Restore the product's established Home information
                    // hierarchy: location first, then the live campus map,
                    // social activity, personalised suggestions and help.
                    const SizedBox(height: ArucadSpacing.lg),
                    Text(strings.t('home_nearby'),
                        style: const TextStyle(
                            fontWeight: FontWeight.w900, fontSize: 18)),
                    const SizedBox(height: ArucadSpacing.sm),
                    if (nearest == null)
                      Card(
                        child: Padding(
                          padding: const EdgeInsets.all(18),
                          child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(strings.t('home_nearby_empty'),
                                    style: const TextStyle(
                                        color: ArucadColors.muted)),
                                if (_upcomingEvents.isNotEmpty) ...[
                                  const SizedBox(height: 10),
                                  Text(
                                      strings
                                          .t('home_events_today_count')
                                          .replaceAll('{n}',
                                              '${_upcomingEvents.length}'),
                                      style: const TextStyle(
                                          fontWeight: FontWeight.w700)),
                                  const SizedBox(height: 12),
                                  OutlinedButton(
                                    onPressed: widget.onExplore,
                                    child:
                                        Text(strings.t('home_explore_today')),
                                  ),
                                ],
                              ]),
                        ),
                      )
                    else
                      _NearbyCard(
                        place: nearest,
                        meters: nearestMeters,
                        onGo: () => _navigateTo(nearest),
                        onOpen: () => _openPlace(nearest),
                      ),
                    const SizedBox(height: ArucadSpacing.lg),
                    SectionHeader(
                        title: strings.t('home_campus_pulse'),
                        action: strings.t('home_open_map'),
                        actionIcon: Icons.map_outlined,
                        actionColor: Theme.of(context).colorScheme.onSurface,
                        onTap: () => _openMap(context)),
                    const SizedBox(height: ArucadSpacing.sm),
                    SizedBox(
                      height: 260,
                      child: CampusLiveMap(
                        places: _places,
                        events: _events,
                        repository: widget.repository,
                        mapProvider: widget.mapProvider,
                        analyticsTracker: widget.analyticsTracker,
                        onOpenGalatea: widget.onAI,
                        userLocation: pos == null
                            ? null
                            : GeoPoint(pos.latitude, pos.longitude),
                        mapHeight: 260,
                        initialVisibility: widget.initialVisibility,
                      ),
                    ),

                    if (widget.showForYou &&
                        (forYou.isNotEmpty ||
                            _events.isNotEmpty ||
                            _places.isNotEmpty)) ...[
                      const SizedBox(height: ArucadSpacing.lg),
                      Text(strings.t('home_for_you'),
                          style: const TextStyle(
                              fontWeight: FontWeight.w900, fontSize: 18)),
                      const SizedBox(height: ArucadSpacing.sm),
                      RecommendationTile(
                        icon: Icons.event_available_rounded,
                        accent: ArucadColors.blue,
                        title: strings.t('explore_recommend_today_events'),
                        subtitle: _todayEventsCount > 0
                            ? '$_todayEventsCount ${strings.t('explore_recommend_today_events_suffix')}'
                            : strings.t('explore_recommend_today_events_empty'),
                        onTap: widget.onExplore,
                      ),
                      const SizedBox(height: 10),
                      Builder(builder: (context) {
                        final popular = _mostPopularPlace;
                        return RecommendationTile(
                          icon: Icons.local_fire_department_rounded,
                          accent: ArucadColors.campusGreen,
                          title: strings.t('explore_recommend_popular_places'),
                          subtitle: popular == null
                              ? strings
                                  .t('explore_recommend_popular_places_empty')
                              : '${popular.name} · ${popular.totalCheckins} '
                                  '${strings.t('explore_recommend_popular_suffix')}',
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
                      const SizedBox(height: 10),
                      RecommendationTile(
                        icon: Icons.explore_rounded,
                        accent: ArucadColors.red,
                        title: strings.t('home_recommend_unvisited'),
                        subtitle: unvisited == null
                            ? strings.t('home_recommend_unvisited_empty')
                            : '${unvisited.name} · ${unvisited.category}',
                        onTap: unvisited == null
                            ? widget.onExplore
                            : () => _openPlace(unvisited),
                      ),
                    ],

                    // Social activity follows personalised recommendations.
                    if (_feed.isNotEmpty) ...[
                      const SizedBox(height: ArucadSpacing.lg),
                      SectionHeader(
                          title: strings.t('home_campus_now'),
                          action: strings.t('home_see_social'),
                          actionColor: Theme.of(context).colorScheme.onSurface,
                          onTap: widget.onSocial),
                      const SizedBox(height: ArucadSpacing.sm),
                      for (var i = 0; i < _latestSocialPosts.length; i++) ...[
                        if (i > 0) const SizedBox(height: 10),
                        Material(
                          color: ArucadColors.paper,
                          borderRadius: BorderRadius.circular(16),
                          child: ListTile(
                            minTileHeight: 68,
                            shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(16)),
                            hoverColor: Colors.transparent,
                            splashColor: Colors.transparent,
                            onTap: () => Navigator.of(context).push(
                              MaterialPageRoute(
                                builder: (_) => PostDetailScreen(
                                  post: _latestSocialPosts[i],
                                  repository: widget.repository,
                                ),
                              ),
                            ),
                            leading:
                                _CampusNowAvatar(post: _latestSocialPosts[i]),
                            title: Text(
                                '${_latestSocialPosts[i].name} ${_latestSocialPosts[i].displayText}',
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(
                                    fontWeight: FontWeight.w800,
                                    fontSize: kListTitleSize)),
                            subtitle: LiveTimeAgo(
                                _latestSocialPosts[i].createdAt ??
                                    DateTime.now(),
                                style: const TextStyle(
                                    color: ArucadColors.muted,
                                    fontSize: kListSubtitleSize)),
                            trailing: const Icon(Icons.chevron_right_rounded,
                                color: ArucadColors.muted, size: 22),
                          ),
                        ),
                      ],
                    ],

                    // SERVICES / HELP SNAPSHOT
                    if (_homeServiceShortcuts.isNotEmpty) ...[
                      const SizedBox(height: ArucadSpacing.lg),
                      SectionHeader(
                          title: strings.t('home_need_help'),
                          action: strings.t('home_all_services'),
                          actionColor: Theme.of(context).colorScheme.onSurface,
                          onTap: widget.onExplore),
                      const SizedBox(height: ArucadSpacing.sm),
                      LayoutBuilder(builder: (context, constraints) {
                        final itemWidth = (constraints.maxWidth - 10) / 2;
                        return Wrap(
                          spacing: 10,
                          runSpacing: 10,
                          children: [
                            for (var i = 0;
                                i < _homeServiceShortcuts.length;
                                i++)
                              _ServiceShortcut(
                                width: itemWidth,
                                service: _homeServiceShortcuts[i],
                                icon: _serviceIcon(_homeServiceShortcuts[i]),
                                accent: brandAccentAt(i),
                                onTap: () => Navigator.of(context).push(
                                    MaterialPageRoute(
                                        builder: (_) => ServiceDetailScreen(
                                            service: _homeServiceShortcuts[i],
                                            repository: widget.repository))),
                              ),
                          ],
                        );
                      }),
                    ],
                    const SizedBox(height: ArucadSpacing.xl),
                  ],
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }

  void _navigateTo(CampusPlace place) {
    widget.analyticsTracker.track('route_started', {'place': place.name});
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => InAppNavigationScreen(
            destinationName: place.name,
            destination: GeoPoint(place.lat, place.lng),
            repository: widget.repository,
            mapProvider: widget.mapProvider,
            analyticsTracker: widget.analyticsTracker)));
  }

  void _openPlace(CampusPlace place) {
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => PlaceDetailScreen(
            place: place,
            repository: widget.repository,
            mapProvider: widget.mapProvider,
            analyticsTracker: widget.analyticsTracker,
            canSetCover: widget.user.canManagePlaces)));
  }

  void _openMap(BuildContext context) {
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => CampusMapFullScreen(
              places: _places,
              events: _events,
              repository: widget.repository,
              mapProvider: widget.mapProvider,
              analyticsTracker: widget.analyticsTracker,
              onOpenGalatea: widget.onAI,
              initialVisibility: widget.initialVisibility,
            )));
  }
}

class _CampusNowAvatar extends StatelessWidget {
  final FeedPost post;
  const _CampusNowAvatar({required this.post});

  @override
  Widget build(BuildContext context) {
    if (post.official) {
      return const CircleAvatar(
        radius: kListIconSize / 2,
        backgroundColor: ArucadColors.primary,
        child: Icon(Icons.school_outlined, size: 22, color: Colors.white),
      );
    }
    final avatarUrl = post.authorAvatarUrl;
    final fallback = ColoredBox(
      color: ArucadColors.mist,
      child: Center(
        child: Text(
          post.name.isEmpty ? '?' : post.name.substring(0, 1).toUpperCase(),
          style: const TextStyle(fontWeight: FontWeight.w800),
        ),
      ),
    );
    return SizedBox(
      width: kListIconSize,
      height: kListIconSize,
      child: ClipOval(
        child: avatarUrl == null || avatarUrl.isEmpty
            ? fallback
            : Image.network(
                avatarUrl,
                fit: BoxFit.cover,
                errorBuilder: (_, __, ___) => fallback,
              ),
      ),
    );
  }
}

/// Bell with an unread count.
///
/// The badge is capped at "9+" rather than showing a real total: past a
/// handful the exact number changes nothing about what you do, and a
/// three-digit badge either overflows the icon or shrinks the text to
/// unreadable.
class _NotificationBell extends StatelessWidget {
  final int unread;
  final VoidCallback onTap;

  const _NotificationBell({required this.unread, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return Stack(clipBehavior: Clip.none, children: [
      IconButton(
        visualDensity: VisualDensity.compact,
        padding: EdgeInsets.zero,
        constraints: const BoxConstraints(minWidth: 40, minHeight: 40),
        onPressed: onTap,
        icon: const Icon(Icons.notifications_none_rounded,
            color: ArucadColors.ink),
        tooltip: AppLocale.of(context).t('social_notifications'),
      ),
      if (unread > 0)
        Positioned(
          right: 4,
          top: 4,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 3, vertical: 1),
            constraints: const BoxConstraints(minWidth: 13, minHeight: 13),
            decoration: BoxDecoration(
              color: ArucadColors.red,
              borderRadius: BorderRadius.circular(999),
            ),
            child: Text(
              unread > 9 ? '9+' : '$unread',
              textAlign: TextAlign.center,
              style: const TextStyle(
                  color: Colors.white,
                  fontSize: 8,
                  height: 1.1,
                  fontWeight: FontWeight.w900),
            ),
          ),
        ),
    ]);
  }
}

class _ScoreChip extends StatelessWidget {
  final int xp;
  final VoidCallback onTap;
  const _ScoreChip({required this.xp, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    // Every phone header uses the numeric-only score. The labelled variant
    // belongs to tablet/desktop widths; at 393 px it competed with the brand,
    // bell and logout button and could overflow with four-digit scores.
    final compact = MediaQuery.sizeOf(context).width < 600;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(999),
      child: Container(
        padding:
            EdgeInsets.symmetric(horizontal: compact ? 8 : 12, vertical: 6),
        decoration: BoxDecoration(
          color: ArucadColors.yellow,
          borderRadius: BorderRadius.circular(ArucadRadius.pill),
        ),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          const Icon(Icons.bolt_rounded, size: 16, color: ArucadColors.ink),
          const SizedBox(width: 4),
          Text(
            compact ? '$xp' : '${strings.t('home_score')} $xp',
            style: const TextStyle(
                fontWeight: FontWeight.w900,
                fontSize: 12,
                color: ArucadColors.ink),
          ),
        ]),
      ),
    );
  }
}

class _NearbyCard extends StatelessWidget {
  final CampusPlace place;
  final double? meters;
  final VoidCallback onGo;
  final VoidCallback onOpen;

  const _NearbyCard(
      {required this.place,
      required this.meters,
      required this.onGo,
      required this.onOpen});

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final distanceLabel = meters == null
        ? place.distance
        : meters! < 1000
            ? '${meters!.round()} m'
            : '${(meters! / 1000).toStringAsFixed(1)} km';
    return Container(
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surface,
        borderRadius: BorderRadius.circular(ArucadRadius.feature),
        boxShadow: ArucadShadows.card,
      ),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onOpen,
          borderRadius: BorderRadius.circular(ArucadRadius.feature),
          hoverColor: Colors.transparent,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Row(children: [
              Container(
                width: 52,
                height: 52,
                decoration: BoxDecoration(
                  color: ArucadColors.primary.withValues(alpha: .07),
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Center(
                  child: PlaceLineArtIcon(
                    placeName: place.name,
                    color: ArucadColors.primary.withValues(alpha: .78),
                    size: 38,
                  ),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(place.name,
                          style: const TextStyle(fontWeight: FontWeight.w900)),
                      const SizedBox(height: 4),
                      Text('${place.category} · $distanceLabel',
                          style: const TextStyle(color: ArucadColors.muted)),
                    ]),
              ),
              FilledButton(
                style: FilledButton.styleFrom(
                    minimumSize: const Size(0, 46),
                    shape: const StadiumBorder()),
                onPressed: onGo,
                child: Text(strings.t('home_go_there')),
              ),
            ]),
          ),
        ),
      ),
    );
  }
}

class _ServiceShortcut extends StatelessWidget {
  final double width;
  final CampusService service;
  final VoidCallback onTap;
  final IconData icon;
  final Color accent;
  const _ServiceShortcut({
    required this.width,
    required this.service,
    required this.onTap,
    required this.icon,
    required this.accent,
  });

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(16),
        child: Container(
          width: width,
          // Tall enough for the shared 44px icon chip plus a two-line
          // title, so a long service name no longer clips.
          constraints: const BoxConstraints(minHeight: 72),
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
          decoration: BoxDecoration(
            color: Theme.of(context).colorScheme.surface,
            borderRadius: BorderRadius.circular(16),
          ),
          child: Row(children: [
            Container(
              width: kListIconSize,
              height: kListIconSize,
              decoration: BoxDecoration(
                  color: accent.withValues(alpha: .14), shape: BoxShape.circle),
              child: Icon(icon, size: 22, color: accent),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Text(service.title,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                      fontWeight: FontWeight.w700,
                      fontSize: kListTitleSize,
                      color: Theme.of(context).colorScheme.onSurface)),
            ),
          ]),
        ),
      );
}

IconData _serviceIcon(CampusService service) {
  final value =
      '${service.id} ${service.title} ${service.category}'.toLowerCase();
  if (value.contains('pdr') ||
      value.contains('wellbeing') ||
      value.contains('psik')) {
    return Icons.favorite_outline_rounded;
  }
  if (value.contains('öğrenci') ||
      value.contains('ogrenci') ||
      value.contains('student')) {
    return Icons.school_rounded;
  }
  if (value.contains('it') ||
      value.contains('teknik') ||
      value.contains('tech')) {
    return Icons.computer_rounded;
  }
  if (value.contains('library') || value.contains('kütüphane')) {
    return Icons.local_library_rounded;
  }
  if (value.contains('yurt') || value.contains('konak')) {
    return Icons.apartment_rounded;
  }
  if (value.contains('kariyer') || value.contains('career')) {
    return Icons.work_outline_rounded;
  }
  if (value.contains('uluslararası') || value.contains('international')) {
    return Icons.public_rounded;
  }
  if (value.contains('eriş') || value.contains('access')) {
    return Icons.accessible_rounded;
  }
  return Icons.support_agent_rounded;
}
