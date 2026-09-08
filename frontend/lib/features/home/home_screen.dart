import 'dart:async';
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/models/academic_year.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/campus_weather.dart';
import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/campus_access_policy.dart';
import 'package:arucad_campus_prototype/core/services/location_service.dart';
import 'package:arucad_campus_prototype/core/services/chat_realtime_service.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/core/utils/relative_time.dart';
import 'package:arucad_campus_prototype/features/home/campus_live_map.dart';
import 'package:arucad_campus_prototype/features/home/create_own_activity_screen.dart';
import 'package:arucad_campus_prototype/features/home/greeting_card.dart';
import 'package:arucad_campus_prototype/features/home/survey_popup.dart';
import 'package:arucad_campus_prototype/features/map/in_app_navigation_screen.dart';
import 'package:arucad_campus_prototype/features/place/place_detail_screen.dart';
import 'package:arucad_campus_prototype/features/services/service_detail_screen.dart';
import 'package:arucad_campus_prototype/features/social/notifications_screen.dart';
import 'package:arucad_campus_prototype/features/social/post_detail_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

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
  List<AcademicYear> _academicYears = const [];
  String? _selectedYearId;
  bool _loading = true;
  String? _loadError;
  int _visibleEvents = kPageSize;
  Position? _myPosition;
  ChatRealtimeService? _realtime;
  StreamSubscription<List<String>>? _campusChanges;
  StreamSubscription<Position>? _positionSub;
  StreamSubscription<void>? _grantedSub;
  bool _refreshingEvents = false;
  bool _refreshingCatalog = false;
  int _unreadNotifs = 0;
  CampusWeather? _weather;
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
      final events =
          await widget.repository.getEvents(academicYearId: _selectedYearId);
      if (mounted) setState(() => _events = events);
    } catch (_) {
      // The current view stays usable; pull-to-refresh is the REST fallback.
    } finally {
      _refreshingEvents = false;
    }
  }

  Future<void> _loadWeather() async {
    try {
      final weather = await widget.repository.getWeather();
      if (mounted) setState(() => _weather = weather);
    } catch (_) {
      // The greeting card simply omits the weather row rather than
      // showing a made-up temperature.
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
    // Two waves: `php artisan serve` on Windows is single-threaded
    // (PHP_CLI_SERVER_WORKERS cannot fork), so 7 parallel GETs after
    // login used to hit the client timeout while queued behind each other.
    try {
      final first = await Future.wait([
        widget.repository.getEvents(academicYearId: _selectedYearId),
        widget.repository.getPlaces(),
        widget.repository.getServices(),
      ]);
      if (!mounted) return;
      final second = await Future.wait([
        widget.repository.getMyActivity(),
        widget.repository.getFeed(),
        widget.repository.getAcademicYears(),
      ]);
      if (!mounted) return;
      setState(() {
        _events = first[0] as List<CampusEvent>;
        _places = first[1] as List<CampusPlace>;
        _services = first[2] as List<CampusService>;
        _activity = second[0] as List<ActivityItem>;
        _feed = second[1] as List<FeedPost>;
        _academicYears = second[2] as List<AcademicYear>;
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

  Future<void> _changeYear(String? yearId) async {
    setState(() => _selectedYearId = yearId);
    try {
      final events = await widget.repository.getEvents(academicYearId: yearId);
      if (mounted) setState(() => _events = events);
    } catch (_) {}
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
        if (byId[id] != null) byId[id]!,
    ];
    for (final s in _services) {
      if (picked.length >= 6) break;
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
    final offCampus = pos != null &&
        !CampusAccessPolicy.isNearAnyCampus(pos.latitude, pos.longitude);
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

    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 760),
        child: RefreshIndicator(
          onRefresh: _load,
          child: ListView(
            padding: const EdgeInsets.symmetric(
                horizontal: ArucadSpacing.md, vertical: ArucadSpacing.sm),
            children: [
              const SizedBox(height: ArucadSpacing.sm),
              Row(children: [
                const Expanded(
                  child: SizedBox(
                    height: 36,
                    child: BrandMark(height: 36),
                  ),
                ),
                const SizedBox(width: 8),
                _ScoreChip(xp: widget.user.xp, onTap: widget.onQuests),
                // Home is where people land, so the unread badge belongs
                // here too — not only on Explore and Social, which they
                // have to navigate to before they learn anything happened.
                _NotificationBell(
                  unread: _unreadNotifs,
                  onTap: _openNotifications,
                ),
                IconButton(
                  visualDensity: VisualDensity.compact,
                  padding: EdgeInsets.zero,
                  constraints:
                      const BoxConstraints(minWidth: 40, minHeight: 40),
                  onPressed: widget.onLogout,
                  icon: const Icon(Icons.logout, color: ArucadColors.muted),
                  tooltip: strings.t('common_logout'),
                ),
              ]),
              if (offCampus) ...[
                const SizedBox(height: ArucadSpacing.sm),
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                  decoration: BoxDecoration(
                    color: ArucadColors.mist,
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: Row(children: [
                    const Icon(Icons.location_off_outlined,
                        size: 16, color: ArucadColors.muted),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(AppLocale.of(context).t('home_off_campus'),
                          style: TextStyle(
                              color: Theme.of(context)
                                  .colorScheme
                                  .onSurfaceVariant,
                              fontSize: 12)),
                    ),
                  ]),
                ),
              ],
              const SizedBox(height: ArucadSpacing.lg),

              // GREETING — replaces the old feedback tile. Surveys still
              // reach students, but as a prompt when one is actually
              // waiting rather than as a permanent row asking for input.
              GreetingCard(
                userName: widget.user.name,
                weather: _weather,
              ),
              const SizedBox(height: ArucadSpacing.lg),

              // NEARBY
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
                              style:
                                  const TextStyle(color: ArucadColors.muted)),
                          if (_upcomingEvents.isNotEmpty) ...[
                            const SizedBox(height: 10),
                            Text(
                                strings
                                    .t('home_events_today_count')
                                    .replaceAll(
                                        '{n}', '${_upcomingEvents.length}'),
                                style: const TextStyle(
                                    fontWeight: FontWeight.w700)),
                            const SizedBox(height: 12),
                            OutlinedButton(
                              onPressed: widget.onExplore,
                              child: Text(strings.t('home_explore_today')),
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

              // CAMPUS PULSE
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
              const SizedBox(height: ArucadSpacing.lg),

              // TODAY
              SectionHeader(
                  title: strings.t('home_today_events'),
                  action: strings.t('home_all'),
                  actionColor: Theme.of(context).colorScheme.onSurface,
                  onTap: widget.onExplore),
              const SizedBox(height: ArucadSpacing.sm),
              SizedBox(
                width: double.infinity,
                child: OutlinedButton.icon(
                  onPressed: () async {
                    final created = await Navigator.of(context).push<bool>(
                        MaterialPageRoute(
                            builder: (_) => CreateOwnActivityScreen(
                                repository: widget.repository)));
                    if (created == true) _load();
                  },
                  style: OutlinedButton.styleFrom(
                    foregroundColor: Theme.of(context).colorScheme.onSurface,
                    side: BorderSide(
                        color: Theme.of(context).colorScheme.onSurface,
                        width: 1.2),
                    shape: const StadiumBorder(),
                    padding: const EdgeInsets.symmetric(vertical: 12),
                  ),
                  icon: const Icon(Icons.add_circle_outline, size: 18),
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
              const SizedBox(height: ArucadSpacing.sm),
              if (_upcomingEvents.isEmpty)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 24),
                  child: Center(
                      child: Text(strings.t('home_no_events'),
                          style: Theme.of(context).textTheme.bodyLarge)),
                )
              else ...[
                for (int i = 0;
                    i < _visibleEvents.clamp(0, _upcomingEvents.length);
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
                  shown: _visibleEvents.clamp(0, _upcomingEvents.length),
                  total: _upcomingEvents.length,
                  itemLabel: strings.t('home_event_item'),
                  showCompleteLabel: false,
                  onTap: () => setState(() => _visibleEvents += kPageSize),
                ),
              ],

              // SOCIAL NOW
              if (_feed.isNotEmpty) ...[
                const SizedBox(height: ArucadSpacing.lg),
                SectionHeader(
                    title: strings.t('home_campus_now'),
                    action: strings.t('home_see_social'),
                    actionColor: Theme.of(context).colorScheme.onSurface,
                    onTap: widget.onSocial),
                const SizedBox(height: ArucadSpacing.sm),
                Card(
                  child: Padding(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
                    child: Column(
                      children: [
                        for (final post in _latestSocialPosts)
                          ListTile(
                            dense: true,
                            hoverColor: Colors.transparent,
                            onTap: () => Navigator.of(context).push(
                              MaterialPageRoute(
                                builder: (_) => PostDetailScreen(
                                  post: post,
                                  repository: widget.repository,
                                ),
                              ),
                            ),
                            leading: _CampusNowAvatar(post: post),
                            title: Text('${post.name} ${post.displayText}',
                                maxLines: 1, overflow: TextOverflow.ellipsis),
                            subtitle: Text(
                                formatRelativeTime(
                                    post.createdAt ?? DateTime.now()),
                                style: const TextStyle(fontSize: 11)),
                            trailing: const Icon(Icons.chevron_right_rounded,
                                size: 18),
                          ),
                      ],
                    ),
                  ),
                ),
              ],

              // FOR YOU
              if (widget.showForYou && forYou.isNotEmpty) ...[
                const SizedBox(height: ArucadSpacing.lg),
                Text(strings.t('home_for_you'),
                    style: const TextStyle(
                        fontWeight: FontWeight.w900, fontSize: 18)),
                const SizedBox(height: ArucadSpacing.sm),
                for (int i = 0; i < forYou.length; i++)
                  Padding(
                    padding: EdgeInsets.only(top: i == 0 ? 0 : 10),
                    child: TrendTile(
                      icon: Icons.explore_outlined,
                      leading: Container(
                        width: 44,
                        height: 44,
                        decoration: BoxDecoration(
                          color: brandAccentAt(i).withValues(alpha: .08),
                          shape: BoxShape.circle,
                        ),
                        child: Center(
                          child: PlaceLineArtIcon(
                            placeName: forYou[i].name,
                            color: brandAccentAt(i),
                            size: 34,
                          ),
                        ),
                      ),
                      title: forYou[i].name,
                      subtitle: '${forYou[i].category} · henüz gitmedin',
                      trailing: forYou[i].distance,
                      accentColor: brandAccentAt(i),
                      accentIconTextOnly: true,
                      onTap: () => _openPlace(forYou[i]),
                    ),
                  ),
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
                      for (var i = 0; i < _homeServiceShortcuts.length; i++)
                        _ServiceShortcut(
                          width: itemWidth,
                          service: _homeServiceShortcuts[i],
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
        radius: 18,
        backgroundColor: ArucadColors.primary,
        child: Icon(Icons.school_outlined, size: 18, color: Colors.white),
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
      width: 36,
      height: 36,
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
            color: ArucadColors.muted),
        tooltip: AppLocale.of(context).t('social_notifications'),
      ),
      if (unread > 0)
        Positioned(
          right: 4,
          top: 4,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
            constraints: const BoxConstraints(minWidth: 16),
            decoration: BoxDecoration(
              color: ArucadColors.red,
              borderRadius: BorderRadius.circular(999),
              border: Border.all(color: Colors.white, width: 1.5),
            ),
            child: Text(
              unread > 9 ? '9+' : '$unread',
              textAlign: TextAlign.center,
              style: const TextStyle(
                  color: Colors.white,
                  fontSize: 9.5,
                  height: 1.2,
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
    final compact = MediaQuery.sizeOf(context).width < 380;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(999),
      child: Container(
        padding:
            EdgeInsets.symmetric(horizontal: compact ? 8 : 12, vertical: 6),
        decoration: BoxDecoration(
          color: ArucadColors.red,
          borderRadius: BorderRadius.circular(ArucadRadius.pill),
        ),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          const Icon(Icons.bolt_rounded, size: 16, color: Colors.white),
          const SizedBox(width: 4),
          Text(
            compact ? '$xp' : '${strings.t('home_score')} $xp',
            style: const TextStyle(
                fontWeight: FontWeight.w900,
                fontSize: 12,
                color: Colors.white),
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
  const _ServiceShortcut({
    required this.width,
    required this.service,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(14),
        child: Container(
          width: width,
          height: 60,
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          decoration: BoxDecoration(
            color: Theme.of(context).colorScheme.surface,
            borderRadius: BorderRadius.circular(14),
          ),
          child: Row(children: [
            Icon(Icons.support_agent_outlined,
                size: 18, color: Theme.of(context).colorScheme.onSurface),
            const SizedBox(width: 8),
            Expanded(
              child: Text(service.title,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                      fontWeight: FontWeight.w700,
                      fontSize: 12.5,
                      color: Theme.of(context).colorScheme.onSurface)),
            ),
          ]),
        ),
      );
}
