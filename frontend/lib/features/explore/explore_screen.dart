import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/admin_content_store.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/clubs/club_detail_screen.dart';
import 'package:arucad_campus_prototype/features/home/campus_live_map.dart';
import 'package:arucad_campus_prototype/features/home/event_detail_screen.dart';
import 'package:arucad_campus_prototype/features/place/place_detail_screen.dart';
import 'package:arucad_campus_prototype/features/services/career_hub_screen.dart';
import 'package:arucad_campus_prototype/features/services/need_help_screen.dart';
import 'package:arucad_campus_prototype/features/services/service_detail_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

const _pageSize = kPageSize;

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
  String filter = 'All';
  bool _loading = true;
  List<CampusPlace> _places = const [];
  List<CampusEvent> _events = const [];
  List<CampusClub> _clubs = const [];
  List<CampusSport> _sports = const [];
  List<CampusService> _services = const [];
  List<CampusFoodVenue> _foodVenues = const [];
  Map<String, int> _checkInCounts = const {};
  CampusUser? _me;
  Position? _myPosition;
  int _visibleCount = _pageSize;
  int _visibleClubs = _pageSize;
  int _visibleServices = _pageSize;

  @override
  void initState() {
    super.initState();
    _load();
    _resolvePosition();
  }

  Future<void> _load() async {
    final results = await Future.wait([
      widget.repository.getPlaces(),
      widget.repository.getEvents(),
      widget.repository.getFeed(),
      widget.repository.getClubs(),
      widget.repository.getSports(),
      widget.repository.getServices(),
      AdminContentStore.foodVenues(),
      widget.repository.getMe(),
    ]);
    if (!mounted) return;
    final places = results[0] as List<CampusPlace>;
    final feed = results[2] as List<FeedPost>;
    final counts = <String, int>{};
    for (final place in places) {
      final name = place.name.toLowerCase();
      counts[place.id] =
          feed.where((p) => p.text.toLowerCase().contains(name)).length;
    }
    setState(() {
      _places = places;
      _events = results[1] as List<CampusEvent>;
      _clubs = results[3] as List<CampusClub>;
      _sports = results[4] as List<CampusSport>;
      _services = results[5] as List<CampusService>;
      _foodVenues = results[6] as List<CampusFoodVenue>;
      _me = results[7] as CampusUser;
      _checkInCounts = counts;
      _loading = false;
    });
  }

  /// A real (not fabricated) reason: matches the event's own category
  /// against the signed-in student's declared interests. Falls back to an
  /// honest, non-personalized reason when nothing matches.
  String _creativeReason(CampusEvent event) {
    final interests = _me?.interests ?? const <String>[];
    for (final interest in interests) {
      if (event.category.toLowerCase().contains(interest.toLowerCase()) ||
          interest.toLowerCase().contains(event.category.toLowerCase())) {
        return 'İlgi alanına uygun: $interest';
      }
    }
    return 'Bu hafta yaratıcı kampüste öne çıkan etkinlik';
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
      // Live distance is a nice-to-have — the static distance label already
      // covers the case where we can't get a real device position.
    }
  }

  String? _liveDistanceLabel(CampusPlace place) {
    final pos = _myPosition;
    if (pos == null) return null;
    final meters = Geolocator.distanceBetween(
        pos.latitude, pos.longitude, place.lat, place.lng);
    if (meters < 1000) return '${meters.round()} m uzakta';
    return '${(meters / 1000).toStringAsFixed(1)} km uzakta';
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    final visible = _places.where((p) {
      if (filter == 'All') return true;
      return p.category == filter;
    }).toList();
    final categories = {'All', ..._places.map((p) => p.category)}.toList();
    final busiest = [..._places]
      ..sort((a, b) =>
          _densityWeight(b.density).compareTo(_densityWeight(a.density)));
    final busiestVisible =
        busiest.where((p) => _densityWeight(p.density) >= 2).take(5).toList();
    final pageCount = _visibleCount.clamp(0, visible.length);
    final clubsShown = _visibleClubs.clamp(0, _clubs.length);
    final servicesShown = _visibleServices.clamp(0, _services.length);
    final strings = AppLocale.of(context);

    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 760),
        child: RefreshIndicator(
          onRefresh: _load,
          // Deliberately no scroll-triggered auto-load here — "Daha fazla
          // göster" is the only way this list grows, so pagination stays
          // real (a bounded page at a time) instead of quietly loading
          // everything the moment the student scrolls near the bottom.
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
                      Text('${_places.length} ${strings.t('explore_places_suffix')}',
                          style: const TextStyle(
                              color: ArucadColors.muted, fontSize: 12)),
                    ]),
                  ),
                ),
                // Campus Map is a real, separate service — a compact entry
                // point here, not the dominant hero it used to be.
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(20, 8, 20, 0),
                    child: Card(
                      child: InkWell(
                        borderRadius: BorderRadius.circular(20),
                        onTap: () => _openMap(context),
                        child: Padding(
                          padding: const EdgeInsets.all(16),
                          child: Row(children: [
                            Container(
                              width: 48,
                              height: 48,
                              decoration: BoxDecoration(
                                  color: ArucadColors.primary.withValues(alpha: .1),
                                  borderRadius: BorderRadius.circular(14)),
                              child: const Icon(Icons.map_outlined, color: ArucadColors.primary),
                            ),
                            const SizedBox(width: 14),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  const Text('Kampüs Haritası',
                                      style: TextStyle(fontWeight: FontWeight.w900)),
                                  Text('Canlı yoğunluk, servisler, navigasyon',
                                      style: const TextStyle(
                                          color: ArucadColors.muted, fontSize: 12)),
                                ],
                              ),
                            ),
                            const Icon(Icons.chevron_right),
                          ]),
                        ),
                      ),
                    ),
                  ),
                ),
                if (busiestVisible.isNotEmpty)
                  SliverToBoxAdapter(
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(20, 18, 0, 0),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Padding(
                            padding: const EdgeInsets.only(right: 20),
                            child: Text(strings.t('explore_busiest'),
                                style: const TextStyle(fontWeight: FontWeight.w900)),
                          ),
                          const SizedBox(height: 10),
                          SizedBox(
                            height: 84,
                            child: ListView.separated(
                              scrollDirection: Axis.horizontal,
                              padding: const EdgeInsets.only(right: 20),
                              itemCount: busiestVisible.length,
                              separatorBuilder: (_, __) =>
                                  const SizedBox(width: 10),
                              itemBuilder: (context, index) {
                                final place = busiestVisible[index];
                                final (color, label) = campusDensityInfo(place);
                                return _BusyPlaceChip(
                                  place: place,
                                  color: color,
                                  label: label,
                                  onTap: () => _openPlace(place),
                                );
                              },
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                if (_events.isNotEmpty)
                  SliverToBoxAdapter(
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(20, 18, 0, 0),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Padding(
                            padding: const EdgeInsets.only(right: 20),
                            child: Text(strings.t('discover_creative'),
                                style: const TextStyle(fontWeight: FontWeight.w900)),
                          ),
                          const SizedBox(height: 10),
                          SizedBox(
                            height: 110,
                            child: ListView.separated(
                              scrollDirection: Axis.horizontal,
                              padding: const EdgeInsets.only(right: 20),
                              itemCount: _events.length,
                              separatorBuilder: (_, __) => const SizedBox(width: 10),
                              itemBuilder: (context, index) => _CreativeEventChip(
                                  event: _events[index],
                                  reason: _creativeReason(_events[index]),
                                  onTap: () => _openEvent(_events[index])),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(20, 18, 0, 0),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Padding(
                          padding: const EdgeInsets.only(right: 20),
                          child: Text(strings.t('discover_sports'),
                              style: const TextStyle(fontWeight: FontWeight.w900)),
                        ),
                        const SizedBox(height: 10),
                        Padding(
                          padding: const EdgeInsets.only(right: 20),
                          child: Wrap(
                            spacing: 8,
                            runSpacing: 8,
                            children: [
                              for (final sport in _sports)
                                InputChip(
                                  avatar: const Icon(Icons.sports, size: 16),
                                  label: Text('${sport.name} · ${sport.facility}'),
                                  onPressed: () => _openSport(sport),
                                ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(20, 20, 20, 0),
                    child: Text(strings.t('discover_clubs'),
                        style: const TextStyle(fontWeight: FontWeight.w900)),
                  ),
                ),
                SliverPadding(
                  padding: const EdgeInsets.fromLTRB(20, 10, 20, 0),
                  sliver: SliverList.builder(
                    itemCount: clubsShown,
                    itemBuilder: (context, index) => Padding(
                      padding: const EdgeInsets.only(bottom: 10),
                      child: _ClubTile(club: _clubs[index], events: _events),
                    ),
                  ),
                ),
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 20),
                    child: LoadMoreButton(
                      shown: clubsShown,
                      total: _clubs.length,
                      itemLabel: 'kulüp',
                      onTap: () => setState(() => _visibleClubs += _pageSize),
                    ),
                  ),
                ),
                if (_foodVenues.isNotEmpty) ...[
                  SliverToBoxAdapter(
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(20, 20, 20, 0),
                      child: Text('Yemek',
                          style: const TextStyle(fontWeight: FontWeight.w900)),
                    ),
                  ),
                  SliverPadding(
                    padding: const EdgeInsets.fromLTRB(20, 10, 20, 0),
                    sliver: SliverList.builder(
                      itemCount: _foodVenues.length,
                      itemBuilder: (context, index) => Padding(
                        padding: const EdgeInsets.only(bottom: 10),
                        child: _FoodVenueTile(venue: _foodVenues[index]),
                      ),
                    ),
                  ),
                ],
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(20, 20, 20, 0),
                    child: Row(children: [
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: () => Navigator.of(context).push(MaterialPageRoute(
                              builder: (_) => NeedHelpScreen(repository: widget.repository))),
                          icon: const Icon(Icons.support_agent_outlined, size: 18),
                          label: const Text('Yardım Al'),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: () => Navigator.of(context).push(MaterialPageRoute(
                              builder: (_) => CareerHubScreen(repository: widget.repository))),
                          icon: const Icon(Icons.work_outline, size: 18),
                          label: const Text('Career'),
                        ),
                      ),
                    ]),
                  ),
                ),
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(20, 20, 20, 0),
                    child: Text(strings.t('discover_services'),
                        style: const TextStyle(fontWeight: FontWeight.w900)),
                  ),
                ),
                SliverPadding(
                  padding: const EdgeInsets.fromLTRB(20, 10, 20, 0),
                  sliver: SliverList.builder(
                    itemCount: servicesShown,
                    itemBuilder: (context, index) => Padding(
                      padding: const EdgeInsets.only(bottom: 10),
                      child: _ServiceTile(service: _services[index]),
                    ),
                  ),
                ),
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 20),
                    child: LoadMoreButton(
                      shown: servicesShown,
                      total: _services.length,
                      itemLabel: 'hizmet',
                      onTap: () => setState(() => _visibleServices += _pageSize),
                    ),
                  ),
                ),
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(20, 24, 20, 0),
                    child: Text(strings.t('discover_places'),
                        style: const TextStyle(fontWeight: FontWeight.w900)),
                  ),
                ),
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(20, 10, 20, 0),
                    child: Container(
                      padding: const EdgeInsets.symmetric(horizontal: 14),
                      decoration: BoxDecoration(
                        color: ArucadColors.mist,
                        borderRadius: BorderRadius.circular(14),
                      ),
                      child: DropdownButtonHideUnderline(
                        child: DropdownButton<String>(
                          value: filter,
                          isExpanded: true,
                          icon: const Icon(Icons.filter_list),
                          items: [
                            for (final category in categories)
                              DropdownMenuItem(
                                value: category,
                                child: Text(category == 'All'
                                    ? strings.t('category_all')
                                    : category),
                              ),
                          ],
                          onChanged: (value) {
                            if (value == null) return;
                            setState(() {
                              filter = value;
                              _visibleCount = _pageSize;
                            });
                          },
                        ),
                      ),
                    ),
                  ),
                ),
                SliverPadding(
                  padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
                  sliver: SliverList.builder(
                    itemCount: pageCount,
                    itemBuilder: (context, index) {
                      final place = visible[index];
                      return Padding(
                        padding: const EdgeInsets.only(bottom: 12),
                        child: PlaceCard(
                          place: place,
                          events: _events,
                          checkInCount: _checkInCounts[place.id] ?? 0,
                          distanceLabel: _liveDistanceLabel(place),
                          onOpen: () => _openPlace(place),
                        ),
                      );
                    },
                  ),
                ),
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
                    child: visible.isEmpty
                        ? Center(
                            child: Text(strings.t('explore_empty_category'),
                                style: const TextStyle(
                                    color: ArucadColors.muted, fontSize: 12)),
                          )
                        : LoadMoreButton(
                            shown: pageCount,
                            total: visible.length,
                            itemLabel: 'yer',
                            onTap: () =>
                                setState(() => _visibleCount += _pageSize),
                          ),
                  ),
                ),
              ],
            ),
        ),
      ),
    );
  }

  int _densityWeight(String density) {
    final raw = density.toLowerCase();
    if (raw.contains('busy') || raw.contains('high')) return 2;
    if (raw.contains('moderate')) return 1;
    return 0;
  }

  void _openPlace(CampusPlace place) {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => PlaceDetailScreen(
          place: place,
          repository: widget.repository,
          mapProvider: widget.mapProvider,
          analyticsTracker: widget.analyticsTracker,
        ),
      ),
    );
  }

  void _openSport(CampusSport sport) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (ctx) => Padding(
        padding: EdgeInsets.fromLTRB(
            20, 20, 20, 20 + MediaQuery.of(ctx).viewInsets.bottom),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              Container(
                width: 46,
                height: 46,
                decoration: BoxDecoration(
                    color: ArucadColors.primary.withValues(alpha: .1),
                    borderRadius: BorderRadius.circular(14)),
                child: const Icon(Icons.sports, color: ArucadColors.primary),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(sport.name,
                        style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 17)),
                    Text(sport.facility,
                        style: const TextStyle(color: ArucadColors.muted, fontSize: 13)),
                  ],
                ),
              ),
            ]),
            const SizedBox(height: 16),
            const Text(
              'Bu spora katılmak veya antrenman/tesis saatlerini öğrenmek için '
              'kampüs ekibiyle iletişime geç.',
              style: TextStyle(fontSize: 13.5, height: 1.4),
            ),
            const SizedBox(height: 18),
            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: () => _contactSport(sport),
                icon: const Icon(Icons.mail_outline),
                label: const Text('Katıl / İletişime Geç'),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _contactSport(CampusSport sport) async {
    await launchUrl(Uri(
      scheme: 'mailto',
      path: sport.contact ?? 'destek@arucad.edu.tr',
      query: 'subject=${Uri.encodeComponent('${sport.name} - Katılmak istiyorum')}',
    ));
  }

  void _openEvent(CampusEvent event) {
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => EventDetailScreen(
            event: event,
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

class _BusyPlaceChip extends StatelessWidget {
  final CampusPlace place;
  final Color color;
  final String label;
  final VoidCallback onTap;

  const _BusyPlaceChip(
      {required this.place,
      required this.color,
      required this.label,
      required this.onTap});

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(18),
        child: Container(
          width: 168,
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
                    style: TextStyle(
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

class _CreativeEventChip extends StatelessWidget {
  final CampusEvent event;
  final String reason;
  final VoidCallback onTap;
  const _CreativeEventChip({required this.event, required this.reason, required this.onTap});

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(16),
        child: Container(
          width: 190,
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          decoration: BoxDecoration(
            color: ArucadColors.mist,
            borderRadius: BorderRadius.circular(16),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(event.category,
                  style: const TextStyle(
                      color: ArucadColors.primary, fontSize: 11, fontWeight: FontWeight.w800)),
              const SizedBox(height: 4),
              Text(event.title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w900)),
              const SizedBox(height: 3),
              Text('${event.placeName} · ${event.time}',
                  style: const TextStyle(color: ArucadColors.muted, fontSize: 12)),
              const SizedBox(height: 3),
              Text(reason,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                      color: ArucadColors.primary, fontSize: 10.5, fontWeight: FontWeight.w600)),
            ],
          ),
        ),
      );
}

class _ClubTile extends StatelessWidget {
  final CampusClub club;
  final List<CampusEvent> events;
  const _ClubTile({required this.club, this.events = const []});

  @override
  Widget build(BuildContext context) => Card(
        child: ListTile(
          leading: CircleAvatar(
            backgroundColor: ArucadColors.mist,
            child: Text(club.name.substring(0, 1)),
          ),
          title: Text(club.name, style: const TextStyle(fontWeight: FontWeight.w800)),
          subtitle: Text(club.description),
          trailing: Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
            decoration: BoxDecoration(
                color: ArucadColors.mist, borderRadius: BorderRadius.circular(999)),
            child: Text(club.category, style: const TextStyle(fontSize: 11)),
          ),
          onTap: () => Navigator.of(context).push(MaterialPageRoute(
              builder: (_) => ClubDetailScreen(club: club, events: events))),
        ),
      );
}

class _FoodVenueTile extends StatefulWidget {
  final CampusFoodVenue venue;
  const _FoodVenueTile({required this.venue});

  @override
  State<_FoodVenueTile> createState() => _FoodVenueTileState();
}

class _FoodVenueTileState extends State<_FoodVenueTile> {
  late DateTime _day;

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    _day = DateTime(now.year, now.month, now.day);
  }

  bool get _isToday {
    final now = DateTime.now();
    return _day.year == now.year && _day.month == now.month && _day.day == now.day;
  }

  Future<void> _pickDay() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _day,
      firstDate: DateTime.now().subtract(const Duration(days: 30)),
      lastDate: DateTime.now().add(const Duration(days: 60)),
    );
    if (picked != null) setState(() => _day = DateTime(picked.year, picked.month, picked.day));
  }

  Future<void> _openMenuFile() async {
    final url = widget.venue.menuFileUrl;
    if (url == null) return;
    await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
  }

  @override
  Widget build(BuildContext context) {
    final venue = widget.venue;
    final menu = venue.menuForDay(_day);
    final dayLabel = _isToday ? 'Bugün' : '${_day.day}.${_day.month}.${_day.year}';
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                  color: ArucadColors.mist, borderRadius: BorderRadius.circular(14)),
              child: const Icon(Icons.restaurant_outlined, color: ArucadColors.primary),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(venue.name, style: const TextStyle(fontWeight: FontWeight.w800)),
                if (venue.hours != null)
                  Text(venue.hours!,
                      style: const TextStyle(color: ArucadColors.muted, fontSize: 11.5)),
              ]),
            ),
            TextButton.icon(
              onPressed: _pickDay,
              icon: const Icon(Icons.calendar_month_outlined, size: 16),
              label: Text(dayLabel, style: const TextStyle(fontSize: 12.5)),
            ),
          ]),
          const SizedBox(height: 8),
          if (menu == null)
            Text('$dayLabel için menü henüz girilmedi.',
                style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5))
          else ...[
            Text(menu.items.isEmpty ? 'Menü detayı girilmedi' : menu.items.join(' · '),
                style: const TextStyle(fontSize: 12.5)),
            if (menu.price != null || menu.hours != null) ...[
              const SizedBox(height: 3),
              Text([if (menu.price != null) menu.price!, if (menu.hours != null) menu.hours!]
                  .join(' · '),
                  style: const TextStyle(color: ArucadColors.muted, fontSize: 11.5)),
            ],
          ],
          if (venue.menuFileUrl != null) ...[
            const SizedBox(height: 8),
            OutlinedButton.icon(
              onPressed: _openMenuFile,
              icon: const Icon(Icons.download_outlined, size: 16),
              label: const Text('Aylık Menüyü Aç / İndir', style: TextStyle(fontSize: 12.5)),
            ),
          ],
        ]),
      ),
    );
  }
}

class _ServiceTile extends StatelessWidget {
  final CampusService service;
  const _ServiceTile({required this.service});

  @override
  Widget build(BuildContext context) => Card(
        child: ListTile(
          leading: const Icon(Icons.support_agent_outlined, color: ArucadColors.primary),
          title: Text(service.title, style: const TextStyle(fontWeight: FontWeight.w800)),
          subtitle: Text(service.description),
          isThreeLine: true,
          onTap: () => Navigator.of(context).push(MaterialPageRoute(
              builder: (_) => ServiceDetailScreen(service: service))),
        ),
      );
}
