import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';

import 'package:arucad_campus_prototype/core/auth/app_settings_store.dart';
import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/config/onboarding_config.dart';
import 'package:arucad_campus_prototype/core/models/academic_year.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:arucad_campus_prototype/core/services/campus_access_policy.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/home/campus_live_map.dart';
import 'package:arucad_campus_prototype/features/home/create_own_activity_screen.dart';
import 'package:arucad_campus_prototype/features/home/survey_popup.dart';
import 'package:arucad_campus_prototype/features/map/in_app_navigation_screen.dart';
import 'package:arucad_campus_prototype/features/onboarding/new_student_screen.dart';
import 'package:arucad_campus_prototype/features/place/place_detail_screen.dart';
import 'package:arucad_campus_prototype/features/services/service_detail_screen.dart';
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

class _HomeScreenState extends State<HomeScreen> {
  List<CampusEvent> _events = const [];
  List<CampusPlace> _places = const [];
  List<ActivityItem> _activity = const [];
  List<FeedPost> _feed = const [];
  List<CampusService> _services = const [];
  List<AcademicYear> _academicYears = const [];
  String? _selectedYearId;
  bool _loading = true;
  int _visibleEvents = kPageSize;
  Position? _myPosition;
  bool _showOnboardingCard = false;
  int _doneOnboardingCount = 0;

  @override
  void initState() {
    super.initState();
    _load();
    _resolvePosition();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) maybeShowSurveyPopup(context, widget.repository);
    });
  }

  Future<void> _load() async {
    final results = await Future.wait([
      widget.repository.getEvents(academicYearId: _selectedYearId),
      widget.repository.getPlaces(),
      widget.repository.getMyActivity(),
      widget.repository.getFeed(),
      widget.repository.getServices(),
      widget.repository.getAcademicYears(),
    ]);
    final done = await AppSettingsStore.onboardingDone();
    final startedAt = await AppSettingsStore.onboardingStartedAt();
    if (!mounted) return;
    setState(() {
      _events = results[0] as List<CampusEvent>;
      _places = results[1] as List<CampusPlace>;
      _activity = results[2] as List<ActivityItem>;
      _feed = results[3] as List<FeedPost>;
      _services = results[4] as List<CampusService>;
      _academicYears = results[5] as List<AcademicYear>;
      _doneOnboardingCount = done.length;
      _showOnboardingCard = done.length < onboardingSteps.length &&
          DateTime.now().difference(startedAt).inDays <= 30;
      _loading = false;
    });
  }

  Future<void> _changeYear(String? yearId) async {
    setState(() => _selectedYearId = yearId);
    final events = await widget.repository.getEvents(academicYearId: yearId);
    if (mounted) setState(() => _events = events);
  }

  Future<void> _resolvePosition() async {
    try {
      final serviceEnabled = await Geolocator.isLocationServiceEnabled();
      if (!serviceEnabled) return;
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        return;
      }
      final position = await Geolocator.getCurrentPosition(
          locationSettings:
              const LocationSettings(accuracy: LocationAccuracy.medium));
      if (!mounted) return;
      setState(() => _myPosition = position);
    } catch (_) {
      // Nearby is a bonus on top of the timeline — no position just means
      // that one card doesn't show, everything else still works.
    }
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

  IconData _feedIcon(FeedPost post) {
    final t = post.text.toLowerCase();
    if (t.contains('check-in') || t.contains('checked in')) {
      return Icons.groups_outlined;
    }
    if (post.imageBytes != null || post.imageUrl != null || t.contains('shared a memory')) {
      return Icons.photo_camera_outlined;
    }
    return Icons.celebration_outlined;
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }
    final strings = AppLocale.of(context);
    final pos = _myPosition;
    final offCampus = pos != null &&
        !CampusAccessPolicy.isNearAnyCampus(pos.latitude, pos.longitude);
    final nearest = _nearestPlace;
    final nearestMeters = nearest == null ? null : _distanceTo(nearest);
    final busiest = [..._places]
      ..sort((a, b) => campusOnlineCount(b.name).compareTo(campusOnlineCount(a.name)));
    final pulse = busiest.take(3).toList();
    final forYou = _places
        .where((p) => !_visitedPlaceNames.contains(p.name.toLowerCase()))
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
                Expanded(
                  child: Image.asset('assets/images/arucad_home_logo.png',
                      height: 34, alignment: Alignment.centerLeft),
                ),
                _ScoreChip(xp: widget.user.xp, onTap: widget.onQuests),
                const SizedBox(width: 6),
                IconButton(
                  onPressed: widget.onLogout,
                  icon: const Icon(Icons.logout, color: ArucadColors.muted),
                  tooltip: 'Çıkış Yap',
                ),
              ]),
              if (offCampus) ...[
                const SizedBox(height: ArucadSpacing.sm),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                  decoration: BoxDecoration(
                    color: ArucadColors.mist,
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: Row(children: [
                    const Icon(Icons.location_off_outlined,
                        size: 16, color: ArucadColors.muted),
                    const SizedBox(width: 8),
                    const Expanded(
                      child: Text(
                          'Şu an kampüs dışındasın — check-in ve canlı harita konumun kampüse yaklaşana kadar sınırlı olabilir.',
                          style: TextStyle(color: ArucadColors.muted, fontSize: 12)),
                    ),
                  ]),
                ),
              ],
              if (_showOnboardingCard) ...[
                const SizedBox(height: ArucadSpacing.sm),
                _OnboardingCard(
                  done: _doneOnboardingCount,
                  total: onboardingSteps.length,
                  onTap: () => Navigator.of(context).push(MaterialPageRoute(
                      builder: (_) => NewStudentScreen(repository: widget.repository))),
                ),
              ],
              const SizedBox(height: ArucadSpacing.lg),

              // NEARBY
              Text(strings.t('home_nearby'),
                  style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
              const SizedBox(height: ArucadSpacing.sm),
              if (nearest == null)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(18),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(strings.t('home_nearby_empty'),
                          style: const TextStyle(color: ArucadColors.muted)),
                      if (_events.isNotEmpty) ...[
                        const SizedBox(height: 10),
                        Text('Bugün kampüste ${_events.length} etkinlik var.',
                            style: const TextStyle(fontWeight: FontWeight.w700)),
                        const SizedBox(height: 12),
                        OutlinedButton(
                          onPressed: widget.onExplore,
                          child: const Text('Bugünü Keşfet'),
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
                  onTap: () => _openMap(context)),
              const Text('Son aktivite/check-in verilerine göre.',
                  style: TextStyle(color: ArucadColors.muted, fontSize: 12)),
              const SizedBox(height: ArucadSpacing.sm),
              SizedBox(
                height: 78,
                child: ListView.separated(
                  scrollDirection: Axis.horizontal,
                  itemCount: pulse.length,
                  separatorBuilder: (_, __) => const SizedBox(width: 10),
                  itemBuilder: (context, index) {
                    final place = pulse[index];
                    final (color, label) = campusDensityInfo(place);
                    return _PulseChip(
                      place: place,
                      color: color,
                      label: label,
                      onTap: () => _openPlace(place),
                    );
                  },
                ),
              ),
              const SizedBox(height: ArucadSpacing.lg),

              // TODAY
              SectionHeader(
                  title: strings.t('home_today_events'),
                  action: strings.t('home_all'),
                  onTap: widget.onExplore),
              const SizedBox(height: ArucadSpacing.sm),
              Align(
                alignment: Alignment.centerLeft,
                child: OutlinedButton.icon(
                  onPressed: () async {
                    final created = await Navigator.of(context).push<bool>(MaterialPageRoute(
                        builder: (_) => CreateOwnActivityScreen(repository: widget.repository)));
                    if (created == true) _load();
                  },
                  icon: const Icon(Icons.add_circle_outline, size: 16),
                  label: const Text('Kendi Aktiviteni Oluştur'),
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
                        label: 'Tüm Yıllar',
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
              if (_events.isEmpty)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 24),
                  child: Center(
                      child: Text(strings.t('home_no_events'),
                          style: Theme.of(context).textTheme.bodyLarge)),
                )
              else ...[
                for (int i = 0; i < _visibleEvents.clamp(0, _events.length); i++)
                  Padding(
                    padding: EdgeInsets.only(top: i == 0 ? 0 : 10),
                    child: EventCard(
                        event: _events[i],
                        repository: widget.repository,
                        mapProvider: widget.mapProvider,
                        analyticsTracker: widget.analyticsTracker),
                  ),
                LoadMoreButton(
                  shown: _visibleEvents.clamp(0, _events.length),
                  total: _events.length,
                  itemLabel: 'etkinlik',
                  onTap: () => setState(() => _visibleEvents += kPageSize),
                ),
              ],

              // SOCIAL NOW
              if (_feed.isNotEmpty) ...[
                const SizedBox(height: ArucadSpacing.lg),
                SectionHeader(
                    title: 'Kampüste Şimdi',
                    action: 'Sosyali Gör',
                    onTap: widget.onSocial),
                const SizedBox(height: ArucadSpacing.sm),
                Card(
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
                    child: Column(
                      children: [
                        for (int i = 0; i < _feed.length.clamp(0, 3); i++)
                          ListTile(
                            dense: true,
                            leading: Icon(_feedIcon(_feed[i]), color: ArucadColors.primary),
                            title: Text('${_feed[i].name} ${_feed[i].text}',
                                maxLines: 1, overflow: TextOverflow.ellipsis),
                            subtitle: Text(_feed[i].meta,
                                style: const TextStyle(fontSize: 11)),
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
                    style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
                const Text('Henüz check-in yapmadığın yakın yerler.',
                    style: TextStyle(color: ArucadColors.muted, fontSize: 12)),
                const SizedBox(height: ArucadSpacing.sm),
                for (int i = 0; i < forYou.length; i++)
                  Padding(
                    padding: EdgeInsets.only(top: i == 0 ? 0 : 10),
                    child: TrendTile(
                      icon: Icons.explore_outlined,
                      title: forYou[i].name,
                      subtitle: '${forYou[i].category} · henüz gitmedin',
                      trailing: forYou[i].distance,
                      accentColor: categoryAccent(forYou[i].category),
                      onTap: () => _openPlace(forYou[i]),
                    ),
                  ),
              ],

              // SERVICES / HELP SNAPSHOT
              if (_homeServiceShortcuts.isNotEmpty) ...[
                const SizedBox(height: ArucadSpacing.lg),
                SectionHeader(
                    title: 'İhtiyacın mı var?',
                    action: 'Tüm Hizmetler',
                    onTap: widget.onExplore),
                const SizedBox(height: ArucadSpacing.sm),
                Wrap(
                  spacing: 10,
                  runSpacing: 10,
                  children: [
                    for (final service in _homeServiceShortcuts)
                      _ServiceShortcut(
                        service: service,
                        onTap: () => Navigator.of(context).push(MaterialPageRoute(
                            builder: (_) => ServiceDetailScreen(service: service))),
                      ),
                  ],
                ),
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
            destination: GeoPoint(place.lat, place.lng))));
  }

  void _openPlace(CampusPlace place) {
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => PlaceDetailScreen(
            place: place,
            repository: widget.repository,
            mapProvider: widget.mapProvider,
            analyticsTracker: widget.analyticsTracker)));
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

class _ScoreChip extends StatelessWidget {
  final int xp;
  final VoidCallback onTap;
  const _ScoreChip({required this.xp, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(999),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        decoration: BoxDecoration(
          color: ArucadColors.warning.withValues(alpha: .14),
          borderRadius: BorderRadius.circular(999),
          border: Border.all(color: ArucadColors.warning.withValues(alpha: .4)),
        ),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          const Icon(Icons.bolt, size: 17, color: ArucadColors.warning),
          const SizedBox(width: 4),
          Text('${strings.t('home_score')} $xp',
              style: const TextStyle(
                  fontWeight: FontWeight.w900,
                  fontSize: 12.5,
                  color: ArucadColors.warning)),
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
      {required this.place, required this.meters, required this.onGo, required this.onOpen});

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final distanceLabel = meters == null
        ? place.distance
        : meters! < 1000
            ? '${meters!.round()} m'
            : '${(meters! / 1000).toStringAsFixed(1)} km';
    return Card(
      child: InkWell(
        onTap: onOpen,
        borderRadius: BorderRadius.circular(22),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(children: [
            Container(
              width: 52,
              height: 52,
              decoration: BoxDecoration(
                  color: ArucadColors.mist, borderRadius: BorderRadius.circular(16)),
              child: const Icon(Icons.place_outlined, color: ArucadColors.primary),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(place.name, style: const TextStyle(fontWeight: FontWeight.w900)),
                const SizedBox(height: 4),
                Text('${place.category} · $distanceLabel',
                    style: const TextStyle(color: ArucadColors.muted)),
              ]),
            ),
            FilledButton(
              style: FilledButton.styleFrom(
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16))),
              onPressed: onGo,
              child: Text(strings.t('home_go_there')),
            ),
          ]),
        ),
      ),
    );
  }
}

class _PulseChip extends StatelessWidget {
  final CampusPlace place;
  final Color color;
  final String label;
  final VoidCallback onTap;

  const _PulseChip(
      {required this.place, required this.color, required this.label, required this.onTap});

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(18),
        child: Container(
          width: 160,
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          decoration: BoxDecoration(
            color: color.withValues(alpha: .1),
            borderRadius: BorderRadius.circular(18),
            border: Border.all(color: color.withValues(alpha: .35)),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Row(children: [
                Container(
                  width: 8,
                  height: 8,
                  decoration: BoxDecoration(color: color, shape: BoxShape.circle),
                ),
                const SizedBox(width: 6),
                Text(label,
                    style: ArucadTextStyles.display(
                        color: color, fontSize: 11, fontWeight: FontWeight.w800)),
              ]),
              const SizedBox(height: 6),
              Text(place.name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w900)),
            ],
          ),
        ),
      );
}

class _ServiceShortcut extends StatelessWidget {
  final CampusService service;
  final VoidCallback onTap;
  const _ServiceShortcut({required this.service, required this.onTap});

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(14),
        child: Container(
          width: 150,
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          decoration: BoxDecoration(
            color: ArucadColors.mist,
            borderRadius: BorderRadius.circular(14),
          ),
          child: Row(children: [
            const Icon(Icons.support_agent_outlined, size: 18, color: ArucadColors.primary),
            const SizedBox(width: 8),
            Expanded(
              child: Text(service.title,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5)),
            ),
          ]),
        ),
      );
}

/// A dismiss-by-completion (not dismiss-by-tap) Home card: it disappears on
/// its own once the checklist is done or 30 days have passed
/// (`_showOnboardingCard` in the parent), rather than needing an explicit
/// "hide this" affordance.
class _OnboardingCard extends StatelessWidget {
  final int done;
  final int total;
  final VoidCallback onTap;
  const _OnboardingCard({required this.done, required this.total, required this.onTap});

  @override
  Widget build(BuildContext context) => Card(
        color: ArucadColors.primary,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(20),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Row(children: [
              const Icon(Icons.flag_outlined, color: Colors.white),
              const SizedBox(width: 12),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  const Text('Kampüse Hoş Geldin',
                      style: TextStyle(
                          color: Colors.white, fontWeight: FontWeight.w900, fontSize: 15)),
                  const SizedBox(height: 3),
                  Text('$done / $total tamamlandı',
                      style: const TextStyle(color: Colors.white70, fontSize: 12.5)),
                ]),
              ),
              const Icon(Icons.chevron_right, color: Colors.white),
            ]),
          ),
        ),
      );
}
