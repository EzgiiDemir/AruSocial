import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

import 'package:arucad_campus_prototype/core/config/poi_config.dart';
import 'package:arucad_campus_prototype/core/config/shuttle_config.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/home/shuttle_sheet.dart';
import 'package:arucad_campus_prototype/features/map/heatmap_adapter.dart';
import 'package:arucad_campus_prototype/features/map/in_app_navigation_screen.dart';
import 'package:arucad_campus_prototype/features/map/maplibre_campus_map.dart';
import 'package:arucad_campus_prototype/features/place/place_detail_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

/// Static campus weather snapshot; the prototype has no live weather feed.
const _weather = (icon: Icons.wb_sunny_outlined, temp: '28°C', label: 'Açık');

/// Konum görünürlüğü — Gizli (ghost) / Arkadaşlarım (friends) / Topluluğum
/// (community) / Herkes (public), en kısıtlıdan en açığa doğru sıralı.
enum CampusVisibility { ghost, friends, community, public }

extension CampusVisibilityLabel on CampusVisibility {
  IconData get icon => switch (this) {
        CampusVisibility.public => Icons.public,
        CampusVisibility.friends => Icons.people_alt_outlined,
        CampusVisibility.community => Icons.diversity_3_outlined,
        CampusVisibility.ghost => Icons.visibility_off_outlined,
      };
  String get label => switch (this) {
        CampusVisibility.public => 'Herkes',
        CampusVisibility.friends => 'Arkadaşlarım',
        CampusVisibility.community => 'Topluluğum',
        CampusVisibility.ghost => 'Gizli',
      };
}

/// Opens the shared place-info bottom sheet (density, live counts, events,
/// floor plan, workshop status) from anywhere in the app — the map and
/// Galatea both show the same panel for a given place.
Future<void> showPlaceInfoSheet(
  BuildContext context, {
  required Poi poi,
  required CampusPlace? place,
  required List<CampusEvent> events,
  required VoidCallback onNavigate,
  VoidCallback? onDetails,
  CampusVisibility visibility = CampusVisibility.friends,
}) {
  return showModalBottomSheet<void>(
    context: context,
    backgroundColor: Colors.transparent,
    isScrollControlled: true,
    builder: (_) => PlaceInfoSheet(
      poi: poi,
      place: place,
      events: events,
      visibility: visibility,
      onNavigate: onNavigate,
      onDetails: onDetails,
    ),
  );
}

const _checkinPool = ['E.', 'A.', 'K.', 'M.', 'S.', 'D.'];

List<String> _recentCheckinsFor(String name) {
  final seed = name.hashCode.abs();
  final first = _checkinPool[seed % _checkinPool.length];
  final second = _checkinPool[(seed ~/ 7) % _checkinPool.length];
  return ['$first. · 3 dk önce check-in yaptı', '$second. · 11 dk önce check-in yaptı'];
}

List<String> _floorPlanFor(String category) {
  final c = category.toLowerCase();
  if (c.contains('workshop') || c.contains('studio') || c.contains('gallery')) {
    return const ['Kat 1: Atölyeler', 'Kat 2: Tasarım Stüdyoları', 'Kat 3: Sergi Alanı'];
  }
  if (c.contains('campus') || c.contains('admin')) {
    return const ['Kat 1: Danışma & İdari Ofisler', 'Kat 2: Derslikler'];
  }
  return const [];
}

const _workshopEquipment = [
  (name: '3D Yazıcı', available: true),
  (name: 'Lazer Kesici', available: false),
  (name: 'Fotoğraf Stüdyosu', available: true),
];

const _collaborationBoard = [
  'Heykel projesi için model aranıyor',
  'Malzeme takası: kil ⇄ ahşap',
];

/// ARUCAD Sosyal Harita: her binanın gerçek koordinatı işaretli, dokununca
/// yoğunluk / etkinlik / online sayısı / check-in akışı / kat planı / atölye
/// durumu gösteren bir bina paneli açılır ve canlı navigasyon başlatılabilir.
/// Gerçek OpenStreetMap vektör harita üzerinde çizilir (MapLibre + OpenFreeMap
/// — Google Maps'e bağımlı değil, bkz. `maplibre_campus_map.dart`).
class CampusLiveMap extends StatefulWidget {
  final List<CampusPlace> places;
  final List<CampusEvent> events;
  final CampusRepository repository;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;
  final VoidCallback onOpenGalatea;

  /// Fixed height for the home-screen preview card. Pass null (used by the
  /// full-screen page) to let the map expand to fill its parent instead.
  final double? mapHeight;

  /// Starting visibility, driven by the student's real "Kampüste beni
  /// göster" setting in Profile — not just a hardcoded default.
  final CampusVisibility initialVisibility;

  const CampusLiveMap({
    super.key,
    required this.places,
    required this.events,
    required this.repository,
    required this.mapProvider,
    required this.analyticsTracker,
    required this.onOpenGalatea,
    this.mapHeight = 400,
    this.initialVisibility = CampusVisibility.friends,
  });

  @override
  State<CampusLiveMap> createState() => _CampusLiveMapState();
}

class _CampusLiveMapState extends State<CampusLiveMap> {
  late List<CampusPulseZone> _pulseZones = HeatmapAdapter.pulseZones(widget.places);
  late CampusVisibility _visibility = widget.initialVisibility;

  @override
  void initState() {
    super.initState();
    _loadOccupancy();
  }

  Future<void> _loadOccupancy() async {
    try {
      final res =
          await http.get(Uri.parse('http://localhost:3333/occupancy'));
      if (res.statusCode != 200) return;
      final data = (json.decode(res.body) as List<dynamic>)
          .map((e) => Map<String, dynamic>.from(e as Map))
          .toList();
      if (!mounted) return;
      setState(() => _pulseZones = [
            ...HeatmapAdapter.pulseZones(widget.places),
            ...HeatmapAdapter.zonesFromOccupancy(data),
          ]);
    } catch (_) {
      // Live occupancy service is optional in this prototype — the
      // density-derived pulse zones set in initState already cover the
      // map with a real (if coarser) red/yellow/green visualization.
    }
  }

  CampusPlace? _matchPlace(String name) {
    final target = name.toLowerCase();
    for (final place in widget.places) {
      final candidate = place.name.toLowerCase();
      if (candidate == target ||
          candidate.contains(target) ||
          target.contains(candidate)) {
        return place;
      }
    }
    return null;
  }

  int get _totalOnline =>
      pois.fold<int>(0, (sum, p) => sum + campusOnlineCount(p.name));

  @override
  Widget build(BuildContext context) {
    // Only busy/moderate places get a real named, tappable marker — every
    // other POI still shows as a small context dot so the map has real
    // texture, but wayfinding to anywhere else goes through Ask ARUCAD
    // instead of tapping a permanently-plotted pin for all 20+ places. No
    // bus icons on the map itself (those live in the shuttle sheet behind
    // the left control button).
    final labelMarkers = <CampusMapMarker>[];
    final contextDots = <CampusMapContextDot>[];
    for (final place in widget.places) {
      final raw = place.density.toLowerCase();
      final isPulse = raw.contains('busy') || raw.contains('high') || raw.contains('moderate');
      final (color, _) = campusDensityInfo(place);
      if (isPulse) {
        labelMarkers.add(CampusMapMarker(
          id: 'pulse-${place.id}',
          position: GeoPoint(place.lat, place.lng),
          label: place.name,
          color: color,
          onTap: () => _openInfo(
              Poi(name: place.name, category: place.category, lat: place.lat, lng: place.lng)),
        ));
      } else {
        contextDots.add(CampusMapContextDot(
            position: GeoPoint(place.lat, place.lng), color: ArucadColors.slate));
      }
    }

    final extentPoints = widget.places.isEmpty
        ? const [GeoPoint(35.33715, 33.32135)]
        : widget.places.map((p) => GeoPoint(p.lat, p.lng)).toList();

    final mapBox = ClipRRect(
      borderRadius: BorderRadius.circular(26),
      child: Stack(children: [
        Positioned.fill(
          child: CampusMapView(
            extentPoints: extentPoints,
            markers: labelMarkers,
            contextDots: contextDots,
            pulseZones: _pulseZones,
            showUserLocation: true,
          ),
        ),
        Positioned(
          left: 14,
          top: 14,
          child: _MapControlButton(
            onlineCount: _totalOnline,
            visibility: _visibility,
            onVisibilityChanged: (v) => setState(() => _visibility = v),
            onOpenShuttle: () => showShuttleSheet(context),
          ),
        ),
        Positioned(
          right: 14,
          bottom: 14,
          child: _GalateaBubble(onTap: widget.onOpenGalatea),
        ),
      ]),
    );

    return widget.mapHeight == null
        ? mapBox
        : SizedBox(height: widget.mapHeight, child: mapBox);
  }

  void _openInfo(Poi poi) {
    final place = _matchPlace(poi.name);
    final events = eventsAtPlace(widget.events, place?.name ?? poi.name);
    showPlaceInfoSheet(
      context,
      poi: poi,
      place: place,
      events: events,
      visibility: _visibility,
      onNavigate: () => _navigate(poi),
      onDetails: place == null ? null : () => _openDetails(place),
    );
  }

  void _openDetails(CampusPlace place) {
    Navigator.of(context).pop();
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => PlaceDetailScreen(
            place: place,
            repository: widget.repository,
            mapProvider: widget.mapProvider,
            analyticsTracker: widget.analyticsTracker)));
  }

  void _navigate(Poi poi) {
    Navigator.of(context).pop();
    widget.analyticsTracker.track('route_started', {'place': poi.name});
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => InAppNavigationScreen(
            destinationName: poi.name,
            destination: GeoPoint(poi.lat, poi.lng))));
  }
}

/// Single compact entry point for everything that used to be a row of chips
/// on top of the map — tapping it pops up all the map's live details in one
/// place, so the map surface itself stays uncluttered.
class _MapControlButton extends StatelessWidget {
  final int onlineCount;
  final CampusVisibility visibility;
  final ValueChanged<CampusVisibility> onVisibilityChanged;
  final VoidCallback onOpenShuttle;

  const _MapControlButton({
    required this.onlineCount,
    required this.visibility,
    required this.onVisibilityChanged,
    required this.onOpenShuttle,
  });

  @override
  Widget build(BuildContext context) => GestureDetector(
        onTap: () => _openSheet(context),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
          decoration: BoxDecoration(
            color: Colors.white.withValues(alpha: .95),
            borderRadius: BorderRadius.circular(999),
            boxShadow: [
              BoxShadow(
                  color: Colors.black.withValues(alpha: .15),
                  blurRadius: 8,
                  offset: const Offset(0, 3)),
            ],
          ),
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            Icon(_weather.icon, size: 16, color: ArucadColors.warning),
            const SizedBox(width: 5),
            Text(_weather.temp,
                style:
                    const TextStyle(fontWeight: FontWeight.w800, fontSize: 12)),
            const SizedBox(width: 4),
            const Icon(Icons.expand_more, size: 16, color: ArucadColors.muted),
          ]),
        ),
      );

  void _openSheet(BuildContext context) {
    final soonest = soonestDeparture();
    showModalBottomSheet<void>(
      context: context,
      builder: (sheetContext) => Padding(
        padding: const EdgeInsets.fromLTRB(20, 20, 20, 28),
        child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('Harita Bilgileri',
                  style: TextStyle(fontWeight: FontWeight.w900, fontSize: 18)),
              const SizedBox(height: 16),
              Row(children: [
                Icon(_weather.icon, size: 18, color: ArucadColors.warning),
                const SizedBox(width: 10),
                Text('${_weather.temp} · ${_weather.label}',
                    style: const TextStyle(fontWeight: FontWeight.w700)),
              ]),
              const SizedBox(height: 10),
              const Row(children: [
                Icon(Icons.traffic, size: 18, color: ArucadColors.blue),
                SizedBox(width: 10),
                Text('Trafik: Orta',
                    style: TextStyle(fontWeight: FontWeight.w700)),
              ]),
              const SizedBox(height: 10),
              Row(children: [
                const Icon(Icons.circle, size: 10, color: ArucadColors.success),
                const SizedBox(width: 10),
                Text('$onlineCount çevrimiçi',
                    style: const TextStyle(fontWeight: FontWeight.w700)),
              ]),
              const Divider(height: 30),
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: const Icon(Icons.directions_bus_filled_outlined,
                    color: ArucadColors.primary),
                title: const Text('Servis Saatleri'),
                subtitle: Text('En yakın: ${formatCountdown(soonest.until)}'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () {
                  Navigator.pop(sheetContext);
                  onOpenShuttle();
                },
              ),
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: Icon(visibility.icon, color: ArucadColors.primary),
                title: const Text('Görünürlük'),
                subtitle: Text(visibility.label),
                trailing: const Icon(Icons.chevron_right),
                onTap: () {
                  Navigator.pop(sheetContext);
                  _openVisibilityPicker(context);
                },
              ),
            ]),
      ),
    );
  }

  void _openVisibilityPicker(BuildContext context) {
    showModalBottomSheet<void>(
      context: context,
      builder: (_) => SafeArea(
        child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              for (final v in CampusVisibility.values)
                ListTile(
                  leading: Icon(v.icon,
                      color: v == visibility
                          ? ArucadColors.primary
                          : ArucadColors.muted),
                  title: Text(v.label),
                  trailing: v == visibility
                      ? const Icon(Icons.check, color: ArucadColors.primary)
                      : null,
                  onTap: () {
                    onVisibilityChanged(v);
                    Navigator.pop(context);
                  },
                ),
            ]),
      ),
    );
  }
}

class _GalateaBubble extends StatelessWidget {
  final VoidCallback onTap;
  const _GalateaBubble({required this.onTap});

  @override
  Widget build(BuildContext context) => GestureDetector(
        onTap: onTap,
        child: Container(
          width: 56,
          height: 56,
          padding: const EdgeInsets.all(2.5),
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: Colors.white,
            boxShadow: [
              BoxShadow(
                  color: Colors.black.withValues(alpha: .18),
                  blurRadius: 12,
                  offset: const Offset(0, 4)),
            ],
            border: Border.all(color: ArucadColors.primary, width: 2),
          ),
          child: const CircleAvatar(
            backgroundImage: AssetImage('assets/images/galatea.png'),
          ),
        ),
      );
}

class PlaceInfoSheet extends StatefulWidget {
  final Poi poi;
  final CampusPlace? place;
  final List<CampusEvent> events;
  final CampusVisibility visibility;
  final VoidCallback onNavigate;
  final VoidCallback? onDetails;

  const PlaceInfoSheet({
    super.key,
    required this.poi,
    required this.place,
    required this.events,
    required this.visibility,
    required this.onNavigate,
    required this.onDetails,
  });

  @override
  State<PlaceInfoSheet> createState() => _PlaceInfoSheetState();
}

class _PlaceInfoSheetState extends State<PlaceInfoSheet> {
  bool _showFloorPlan = false;

  @override
  Widget build(BuildContext context) {
    final (densityColor, densityLabel) = campusDensityInfo(widget.place);
    final activeCount =
        widget.events.fold<int>(0, (sum, e) => sum + e.attendees);
    final onlineHere = campusOnlineCount(widget.poi.name);
    final checkins = _recentCheckinsFor(widget.poi.name);
    final floors = _floorPlanFor(widget.poi.category);
    final isWorkshop = widget.poi.category.toLowerCase().contains('workshop');

    return Container(
      padding: const EdgeInsets.fromLTRB(20, 18, 20, 28),
      constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * .85),
      decoration: const BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.vertical(top: Radius.circular(28))),
      child: SafeArea(
        top: false,
        child: SingleChildScrollView(
          child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(children: [
                  Expanded(
                      child: Text(widget.poi.name,
                          style: const TextStyle(
                              fontWeight: FontWeight.w900, fontSize: 20))),
                  IconButton(
                      onPressed: () => Navigator.pop(context),
                      icon: const Icon(Icons.close)),
                ]),
                Text(widget.poi.category,
                    style: const TextStyle(
                        color: ArucadColors.muted, fontWeight: FontWeight.w700)),
                const SizedBox(height: 14),
                Wrap(spacing: 8, runSpacing: 8, children: [
                  _InfoChip(
                      icon: Icons.people_outline,
                      label: densityLabel,
                      color: densityColor),
                  _InfoChip(
                      icon: Icons.wifi_tethering,
                      label: '$onlineHere kişi burada',
                      color: ArucadColors.blue),
                  _InfoChip(
                      icon: Icons.event_outlined,
                      label: widget.events.isEmpty
                          ? 'Etkinlik yok'
                          : '${widget.events.length} etkinlik',
                      color: widget.events.isEmpty
                          ? ArucadColors.muted
                          : ArucadColors.primary),
                  if (activeCount > 0)
                    _InfoChip(
                        icon: Icons.groups_outlined,
                        label: '$activeCount aktif katılımcı',
                        color: ArucadColors.blue),
                  _InfoChip(
                      icon: _weather.icon,
                      label: '${_weather.temp} · ${_weather.label}',
                      color: ArucadColors.warning),
                ]),
                if (widget.events.isNotEmpty) ...[
                  const SizedBox(height: 14),
                  for (final e in widget.events)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 6),
                      child: Text('${e.time}  ${e.title}',
                          style: const TextStyle(fontWeight: FontWeight.w700)),
                    ),
                ],
                const SizedBox(height: 16),
                const Text('Az önce',
                    style: TextStyle(fontWeight: FontWeight.w900, fontSize: 13)),
                const SizedBox(height: 8),
                for (final c in checkins)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 6),
                    child: Row(children: [
                      const Icon(Icons.photo_camera_back_outlined,
                          size: 15, color: ArucadColors.muted),
                      const SizedBox(width: 6),
                      Expanded(
                          child: Text(c,
                              style: const TextStyle(
                                  fontSize: 13, color: ArucadColors.muted))),
                    ]),
                  ),
                if (floors.isNotEmpty) ...[
                  const SizedBox(height: 10),
                  InkWell(
                    onTap: () => setState(() => _showFloorPlan = !_showFloorPlan),
                    child: Row(children: [
                      const Icon(Icons.layers_outlined,
                          size: 17, color: ArucadColors.primary),
                      const SizedBox(width: 6),
                      const Text('Kat Planı',
                          style: TextStyle(
                              fontWeight: FontWeight.w800, fontSize: 13)),
                      Icon(
                          _showFloorPlan
                              ? Icons.expand_less
                              : Icons.expand_more,
                          size: 18),
                    ]),
                  ),
                  if (_showFloorPlan)
                    Padding(
                      padding: const EdgeInsets.only(top: 6, left: 23),
                      child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            for (final f in floors)
                              Padding(
                                padding: const EdgeInsets.only(bottom: 4),
                                child: Text(f,
                                    style: const TextStyle(
                                        color: ArucadColors.muted)),
                              ),
                          ]),
                    ),
                ],
                if (isWorkshop) ...[
                  const SizedBox(height: 14),
                  const Text('Atölye Durumu',
                      style: TextStyle(
                          fontWeight: FontWeight.w900, fontSize: 13)),
                  const SizedBox(height: 8),
                  for (final eq in _workshopEquipment)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 6),
                      child: Row(children: [
                        Icon(Icons.circle,
                            size: 9,
                            color: eq.available
                                ? ArucadColors.success
                                : ArucadColors.danger),
                        const SizedBox(width: 8),
                        Expanded(child: Text(eq.name)),
                        Text(eq.available ? 'Müsait' : 'Dolu',
                            style: TextStyle(
                                fontWeight: FontWeight.w800,
                                fontSize: 12,
                                color: eq.available
                                    ? ArucadColors.success
                                    : ArucadColors.danger)),
                      ]),
                    ),
                  const SizedBox(height: 6),
                  OutlinedButton.icon(
                    onPressed: () {
                      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
                          content:
                              Text('Ask ARUCAD üzerinden randevu isteği gönderildi')));
                    },
                    icon: const Icon(Icons.calendar_month_outlined),
                    label: const Text('Randevu Al'),
                  ),
                  const SizedBox(height: 6),
                  const Text('İş Birliği Panosu',
                      style: TextStyle(
                          fontWeight: FontWeight.w900, fontSize: 13)),
                  const SizedBox(height: 8),
                  for (final post in _collaborationBoard)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 6),
                      child: Row(children: [
                        const Icon(Icons.push_pin_outlined,
                            size: 15, color: ArucadColors.muted),
                        const SizedBox(width: 6),
                        Expanded(child: Text(post)),
                      ]),
                    ),
                ],
                const SizedBox(height: 6),
                Text(
                    widget.visibility == CampusVisibility.ghost
                        ? 'Hayalet modundasınız · konumunuz kimseyle paylaşılmıyor'
                        : 'Görünürlük: ${widget.visibility.label}',
                    style: const TextStyle(
                        fontSize: 11, color: ArucadColors.muted)),
                const SizedBox(height: 12),
                Row(children: [
                  Expanded(
                    child: FilledButton.icon(
                      onPressed: widget.onNavigate,
                      icon: const Icon(Icons.directions_walk),
                      label: const Text('Navigasyonu Başlat'),
                    ),
                  ),
                  if (widget.onDetails != null) ...[
                    const SizedBox(width: 10),
                    OutlinedButton(
                        onPressed: widget.onDetails,
                        child: const Text('Detay')),
                  ],
                ]),
              ]),
        ),
      ),
    );
  }
}

class _InfoChip extends StatelessWidget {
  final IconData icon;
  final String label;
  final Color color;
  const _InfoChip(
      {required this.icon, required this.label, required this.color});

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        decoration: BoxDecoration(
            color: color.withValues(alpha: .12),
            borderRadius: BorderRadius.circular(999)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, size: 15, color: color),
          const SizedBox(width: 6),
          Text(label,
              style: TextStyle(
                  fontWeight: FontWeight.w800, fontSize: 12, color: color)),
        ]),
      );
}

/// Full-screen version of the same live map — pushed from the small
/// expand button next to Galatea instead of duplicating any map logic.
class CampusMapFullScreen extends StatelessWidget {
  final List<CampusPlace> places;
  final List<CampusEvent> events;
  final CampusRepository repository;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;
  final VoidCallback onOpenGalatea;
  final CampusVisibility initialVisibility;

  const CampusMapFullScreen({
    super.key,
    required this.places,
    required this.events,
    required this.repository,
    required this.mapProvider,
    required this.analyticsTracker,
    required this.onOpenGalatea,
    this.initialVisibility = CampusVisibility.friends,
  });

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('ARUCAD Social Map')),
        body: Padding(
          padding: const EdgeInsets.all(12),
          child: SizedBox.expand(
            child: CampusLiveMap(
              places: places,
              events: events,
              repository: repository,
              mapProvider: mapProvider,
              analyticsTracker: analyticsTracker,
              onOpenGalatea: onOpenGalatea,
              mapHeight: null,
              initialVisibility: initialVisibility,
            ),
          ),
        ),
      );
}
