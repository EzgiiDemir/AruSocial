import 'dart:async';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/config/place_catalog.dart';
import 'package:arucad_campus_prototype/core/config/poi_config.dart';
import 'package:arucad_campus_prototype/core/config/shuttle_config.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/campus_weather.dart';
import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/content_moderation.dart';
import 'package:arucad_campus_prototype/core/services/chat_realtime_service.dart';
import 'package:arucad_campus_prototype/core/services/location_service.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/guide/ask_arucad_bubble.dart';
import 'package:arucad_campus_prototype/features/guide/guide_sheet.dart';
import 'package:arucad_campus_prototype/features/home/shuttle_sheet.dart';
import 'package:arucad_campus_prototype/features/map/heatmap_adapter.dart';
import 'package:arucad_campus_prototype/features/map/in_app_navigation_screen.dart';
import 'package:arucad_campus_prototype/features/map/map_pointer_guard.dart';
import 'package:arucad_campus_prototype/features/map/maplibre_campus_map.dart';
import 'package:arucad_campus_prototype/features/place/place_detail_screen.dart';
import 'package:arucad_campus_prototype/features/services/building_directory_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

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
  CampusRepository? repository,
  VoidCallback? onOpenDirectory,
  VoidCallback? onDetails,
  VoidCallback? onRequestAppointment,
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
      repository: repository,
      onNavigate: onNavigate,
      onOpenDirectory: onOpenDirectory,
      onDetails: onDetails,
      onRequestAppointment: onRequestAppointment,
    ),
  );
}

// Real check-in entries come from the backend (`PlacePresence`); a place
// with none simply shows an honest empty state instead of fabricated names.
String _checkinEntryLabel(CampusCheckinEntry entry) {
  final at = entry.checkedInAt;
  if (at == null) return '${entry.initial} check-in yaptı';
  final minutesAgo = DateTime.now().difference(at).inMinutes;
  final when = minutesAgo <= 0
      ? 'az önce'
      : minutesAgo < 60
          ? '$minutesAgo dk önce'
          : '${(minutesAgo / 60).floor()} sa önce';
  return '${entry.initial} · $when check-in yaptı';
}

List<String> _floorPlanFor(String category) {
  final c = category.toLowerCase();
  if (c.contains('workshop') ||
      c.contains('studio') ||
      c.contains('gallery') ||
      c.contains('atölye') ||
      c.contains('stüdyo') ||
      c.contains('galeri')) {
    return const [
      'Kat 1: Atölyeler',
      'Kat 2: Tasarım Stüdyoları',
      'Kat 3: Sergi Alanı'
    ];
  }
  if (c.contains('campus') ||
      c.contains('admin') ||
      c.contains('idari') ||
      c.contains('kampüs')) {
    return const ['Kat 1: Danışma & İdari Ofisler', 'Kat 2: Derslikler'];
  }
  if (c.contains('library') || c.contains('kütüphane')) {
    return const [
      'Kat 1: Okuma Salonu',
      'Kat 2: Dijital Kütüphane & Konferans'
    ];
  }
  return const [];
}

bool _isWorkshopCategory(String category) {
  final c = category.toLowerCase();
  return c.contains('workshop') ||
      c.contains('atölye') ||
      c.contains('studio') ||
      c.contains('stüdyo');
}

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
  final GeoPoint? userLocation;

  /// Fixed height for the home-screen preview card. Pass null (used by the
  /// full-screen page) to let the map expand to fill its parent instead.
  final double? mapHeight;

  /// Starting visibility, driven by the student's real "Kampüste beni
  /// göster" setting in Profile — not just a hardcoded default.
  final CampusVisibility initialVisibility;

  /// Optional place to focus when opened from a deep link / pulse chip.
  final String? focusPlaceId;

  const CampusLiveMap({
    super.key,
    required this.places,
    required this.events,
    required this.repository,
    required this.mapProvider,
    required this.analyticsTracker,
    required this.onOpenGalatea,
    this.userLocation,
    this.mapHeight = 400,
    this.initialVisibility = CampusVisibility.friends,
    this.focusPlaceId,
  });

  @override
  State<CampusLiveMap> createState() => _CampusLiveMapState();
}

class _CampusLiveMapState extends State<CampusLiveMap> {
  late List<CampusPulseZone> _pulseZones =
      HeatmapAdapter.pulseZones(widget.places);
  late CampusVisibility _visibility = widget.initialVisibility;
  final _mapController = CampusMapController();

  @override
  void didUpdateWidget(covariant CampusLiveMap oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.places != widget.places) {
      _pulseZones = HeatmapAdapter.pulseZones(widget.places);
    }
  }

  CampusPlace? _matchPlace(String name) {
    final target = name.toLowerCase();
    for (final place in campusMapPlaces(widget.places)) {
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

  void _openDirectory() {
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => BuildingDirectoryScreen(
        repository: widget.repository,
        mapProvider: widget.mapProvider,
        analyticsTracker: widget.analyticsTracker,
        onOpenGalatea: widget.onOpenGalatea,
        initialVisibility: _visibility,
      ),
    ));
  }

  @override
  Widget build(BuildContext context) {
    final mapPlaces = campusMapPlaces(widget.places);
    final pins = spreadOverlappingMapPins(mapPlaces);
    final isPreview = widget.mapHeight != null;
    final labelMarkers = <CampusMapMarker>[];
    for (final pin in pins) {
      final place = pin.place;
      labelMarkers.add(CampusMapMarker(
        id: 'place-${place.id}',
        position: pin.display,
        label: place.name,
        color: campusDensityInfo(place).$1,
        // A compact home preview must not layer a modal over the feed. Its
        // interactive POIs always continue in the dedicated map instead.
        onTap: isPreview
            ? () => _openFullMap(focusPlaceId: place.id)
            : () => _openInfo(poiFromPlace(place), display: pin.display),
      ));
    }
    const contextDots = <CampusMapContextDot>[];

    final extentPoints = mainCampusCameraExtent(mapPlaces);
    GeoPoint? focusPoint;
    final focusId = widget.focusPlaceId;
    if (focusId != null) {
      for (final pin in pins) {
        if (pin.place.id == focusId) {
          focusPoint = pin.display;
          break;
        }
      }
    }

    // Campus Pulse is a live map, full stop. The 360 tour used to sit behind
    // a toggle here and fought the map for the same WebGL context while an
    // external player loaded; it now lives only in the building directory,
    // where browsing a building's floors and rooms is the actual reason to
    // open a tour.
    final mapBox = ClipRRect(
      borderRadius: BorderRadius.circular(26),
      child: Stack(children: [
        Positioned.fill(
          child: CampusMapView(
            extentPoints: extentPoints,
            focusPoint: focusPoint,
            markers: labelMarkers,
            contextDots: contextDots,
            pulseZones: _pulseZones,
            userLocation: widget.userLocation,
            showUserLocation: true,
            controller: _mapController,
          ),
        ),
        Positioned(
          left: 14,
          top: 14,
          child: _MapControlButton(
            onlineCount: _totalOnline,
            visibility: _visibility,
            repository: widget.repository,
            onVisibilityChanged: (v) => setState(() => _visibility = v),
            onOpenShuttle: () =>
                showShuttleSheet(context, repository: widget.repository),
            onOpenFullMap: isPreview ? () => _openFullMap() : null,
          ),
        ),
        Positioned(
          right: 14,
          top: 14,
          child: AskArucadBubble(
              onTap: isPreview ? () => _openFullMap() : _openAskArucad),
        ),
        if (widget.mapHeight != null)
          const Positioned(
            left: 14,
            bottom: 14,
            child: _MapPreviewLegend(),
          ),
      ]),
    );

    return widget.mapHeight == null
        ? mapBox
        : SizedBox(height: widget.mapHeight, child: mapBox);
  }

  void _openInfo(Poi poi, {GeoPoint? display}) {
    _mapController.centerOn(display ?? GeoPoint(poi.lat, poi.lng), zoom: 18.2);
    final place = _matchPlace(poi.name);
    final events = eventsAtPlace(widget.events, place?.name ?? poi.name);
    showPlaceInfoSheet(
      context,
      poi: poi,
      place: place,
      events: events,
      visibility: _visibility,
      repository: widget.repository,
      onNavigate: () => _navigate(poi),
      // 360 tours are reached through the building directory now, not from
      // the map sheet — one place to browse buildings, floors and rooms.
      onOpenDirectory: () {
        Navigator.of(context).pop();
        _openDirectory();
      },
      onDetails: place == null ? null : () => _openDetails(place),
      onRequestAppointment: _openAskArucad,
    );
  }

  void _openFullMap({String? focusPlaceId}) {
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => CampusMapFullScreen(
              places: widget.places,
              events: widget.events,
              repository: widget.repository,
              mapProvider: widget.mapProvider,
              analyticsTracker: widget.analyticsTracker,
              onOpenGalatea: widget.onOpenGalatea,
              userLocation: widget.userLocation,
              initialVisibility: _visibility,
              focusPlaceId: focusPlaceId,
            )));
  }

  void _openAskArucad() {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (_) => GuideSheet(
        repository: widget.repository,
        mapProvider: widget.mapProvider,
        analyticsTracker: widget.analyticsTracker,
        onOpenFullChat: () {
          Navigator.of(context).pop();
          if (widget.mapHeight == null && Navigator.of(context).canPop()) {
            Navigator.of(context).pop();
          }
          widget.onOpenGalatea();
        },
      ),
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
            destination: GeoPoint(poi.lat, poi.lng),
            repository: widget.repository,
            mapProvider: widget.mapProvider,
            analyticsTracker: widget.analyticsTracker)));
  }
}

/// Home map previews stay compact but still expose the live map's essential
/// reading tools: campus regions plus the green/yellow/red density scale.
class _MapPreviewLegend extends StatelessWidget {
  const _MapPreviewLegend();

  @override
  Widget build(BuildContext context) => MapPointerGuard(
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
          decoration: BoxDecoration(
            color: Theme.of(context).colorScheme.surface.withValues(alpha: .94),
            borderRadius: BorderRadius.circular(14),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withValues(alpha: .12),
                blurRadius: 8,
                offset: const Offset(0, 3),
              ),
            ],
          ),
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            const Icon(Icons.layers_outlined, size: 15),
            const SizedBox(width: 5),
            Text(AppLocale.of(context).t('clm_zones'),
                style: TextStyle(fontSize: 11, fontWeight: FontWeight.w800)),
            const SizedBox(width: 9),
            _DensityDot(color: ArucadColors.campusGreen, label: 'Sakin'),
            const SizedBox(width: 6),
            _DensityDot(color: ArucadColors.yellow, label: 'Orta'),
            const SizedBox(width: 6),
            _DensityDot(color: ArucadColors.danger, label: AppLocale.of(context).t('clm_busy')),
          ]),
        ),
      );
}

/// Everything the map knows right now, in one panel: live presence, today's
/// weather over campus, and the next departure on every shuttle line with
/// its stops. This replaced a sheet that only showed a single "next service"
/// countdown and made the rest of the timetable a separate journey.
class _MapInfoSheet extends StatefulWidget {
  final int onlineCount;
  final CampusVisibility visibility;
  final CampusRepository repository;
  final VoidCallback onOpenShuttle;
  final VoidCallback onChangeVisibility;

  const _MapInfoSheet({
    required this.onlineCount,
    required this.visibility,
    required this.repository,
    required this.onOpenShuttle,
    required this.onChangeVisibility,
  });

  @override
  State<_MapInfoSheet> createState() => _MapInfoSheetState();
}

class _MapInfoSheetState extends State<_MapInfoSheet> {
  CampusWeather? _weather;
  List<ShuttleRoute> _routes = shuttleRoutes;

  @override
  void initState() {
    super.initState();
    unawaited(_load());
  }

  Future<void> _load() async {
    final weather = await widget.repository.getWeather();
    List<ShuttleRoute>? routes;
    try {
      routes = await widget.repository.getShuttleRoutes();
    } catch (_) {
      // The bundled timetable is a fine fallback — it is the same schedule
      // the backend seeds from.
    }
    if (!mounted) return;
    setState(() {
      _weather = weather;
      if (routes != null && routes.isNotEmpty) _routes = routes;
    });
  }

  @override
  Widget build(BuildContext context) {
    final now = DateTime.now();
    return SafeArea(
      child: ConstrainedBox(
        constraints: BoxConstraints(
            maxHeight: MediaQuery.of(context).size.height * .78),
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 18, 20, 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('Harita Bilgileri',
                  style: TextStyle(fontWeight: FontWeight.w900, fontSize: 18)),
              const SizedBox(height: 14),
              Row(children: [
                const Icon(Icons.circle, size: 10, color: ArucadColors.success),
                const SizedBox(width: 10),
                Text('${widget.onlineCount} çevrimiçi',
                    style: const TextStyle(fontWeight: FontWeight.w700)),
              ]),
              if (_weather case final weather?) ...[
                const SizedBox(height: 12),
                Row(children: [
                  Icon(_weatherIcon(weather),
                      size: 20, color: ArucadColors.primary),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      '${weather.temperatureLabel} · ${weather.summary}'
                      ' · ${weather.feelsLikeLabel} · ${weather.windLabel}',
                      style: const TextStyle(fontWeight: FontWeight.w600),
                    ),
                  ),
                ]),
              ],
              const Divider(height: 28),
              Row(children: [
                const Expanded(
                  child: Text('Servis Saatleri',
                      style:
                          TextStyle(fontWeight: FontWeight.w900, fontSize: 14)),
                ),
                TextButton(
                    onPressed: widget.onOpenShuttle,
                    child: Text(AppLocale.of(context).t('clm_all'))),
              ]),
              const SizedBox(height: 4),
              for (final route in _routes)
                _ShuttleLineRow(route: route, now: now),
              const Divider(height: 28),
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading:
                    Icon(widget.visibility.icon, color: ArucadColors.primary),
                title: Text(AppLocale.of(context).t('clm_visibility')),
                subtitle: Text(widget.visibility.label),
                trailing: const Icon(Icons.chevron_right),
                onTap: widget.onChangeVisibility,
              ),
            ],
          ),
        ),
      ),
    );
  }

  IconData _weatherIcon(CampusWeather weather) {
    if (weather.code == 0) {
      return weather.isDay ? Icons.wb_sunny_outlined : Icons.nightlight_outlined;
    }
    if (weather.code <= 3) return Icons.cloud_outlined;
    if (weather.code <= 48) return Icons.foggy;
    if (weather.code <= 67) return Icons.water_drop_outlined;
    if (weather.code <= 86) return Icons.ac_unit;
    return Icons.thunderstorm_outlined;
  }
}

/// One shuttle line: its colour, next departure countdown, and the stops it
/// actually calls at — the detail students were previously sent to another
/// screen to find.
class _ShuttleLineRow extends StatelessWidget {
  final ShuttleRoute route;
  final DateTime now;

  const _ShuttleLineRow({required this.route, required this.now});

  @override
  Widget build(BuildContext context) {
    final next = nextDeparture(route.departures, now);
    final back = route.returns == null
        ? null
        : nextDeparture(route.returns!, now);

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Container(
          width: 10,
          height: 10,
          margin: const EdgeInsets.only(top: 5, right: 10),
          decoration:
              BoxDecoration(color: route.color, shape: BoxShape.circle),
        ),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(route.name,
                style: const TextStyle(
                    fontWeight: FontWeight.w800, fontSize: 13.5)),
            const SizedBox(height: 2),
            Text(
              back == null
                  ? 'Sıradaki ${next.label} · ${formatCountdown(next.until)}'
                  : 'Gidiş ${next.label} · Dönüş ${back.label}',
              style: const TextStyle(
                  color: ArucadColors.muted, fontSize: 11.5),
            ),
            const SizedBox(height: 2),
            Text(
              route.stops.join(' → '),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(color: ArucadColors.muted, fontSize: 11),
            ),
          ]),
        ),
      ]),
    );
  }
}

class _DensityDot extends StatelessWidget {
  final Color color;
  final String label;
  const _DensityDot({required this.color, required this.label});

  @override
  Widget build(BuildContext context) =>
      Row(mainAxisSize: MainAxisSize.min, children: [
        Container(
          width: 7,
          height: 7,
          decoration: BoxDecoration(color: color, shape: BoxShape.circle),
        ),
        const SizedBox(width: 3),
        Text(label,
            style: const TextStyle(fontSize: 10, fontWeight: FontWeight.w700)),
      ]);
}

/// Single compact entry point for everything that used to be a row of chips
/// on top of the map — tapping it pops up all the map's live details in one
/// place, so the map surface itself stays uncluttered.
class _MapControlButton extends StatelessWidget {
  final int onlineCount;
  final CampusVisibility visibility;
  final ValueChanged<CampusVisibility> onVisibilityChanged;
  final VoidCallback onOpenShuttle;
  final VoidCallback? onOpenFullMap;
  final CampusRepository repository;

  const _MapControlButton({
    required this.onlineCount,
    required this.visibility,
    required this.onVisibilityChanged,
    required this.onOpenShuttle,
    required this.repository,
    this.onOpenFullMap,
  });

  @override
  Widget build(BuildContext context) => MapPointerGuard(
        child: GestureDetector(
          onTap: onOpenFullMap ?? () => _openSheet(context),
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
            decoration: BoxDecoration(
              color:
                  Theme.of(context).colorScheme.surface.withValues(alpha: .95),
              borderRadius: BorderRadius.circular(999),
              boxShadow: [
                BoxShadow(
                    color: Colors.black.withValues(alpha: .15),
                    blurRadius: 8,
                    offset: const Offset(0, 3)),
              ],
            ),
            child: Row(mainAxisSize: MainAxisSize.min, children: [
              const Icon(Icons.info_outline,
                  size: 16, color: ArucadColors.primary),
              const SizedBox(width: 5),
              const Text('Harita bilgisi',
                  style: TextStyle(fontWeight: FontWeight.w800, fontSize: 12)),
              const SizedBox(width: 4),
              const Icon(Icons.expand_more,
                  size: 16, color: ArucadColors.muted),
            ]),
          ),
        ),
      );

  void _openSheet(BuildContext context) {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (sheetContext) => _MapInfoSheet(
        onlineCount: onlineCount,
        visibility: visibility,
        repository: repository,
        onOpenShuttle: () {
          Navigator.pop(sheetContext);
          onOpenShuttle();
        },
        onChangeVisibility: () {
          Navigator.pop(sheetContext);
          _openVisibilityPicker(context);
        },
      ),
    );
  }

  void _openVisibilityPicker(BuildContext context) {
    showModalBottomSheet<void>(
      context: context,
      builder: (_) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
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

class PlaceInfoSheet extends StatefulWidget {
  final Poi poi;
  final CampusPlace? place;
  final List<CampusEvent> events;
  final CampusVisibility visibility;
  final VoidCallback onNavigate;
  final CampusRepository? repository;
  final VoidCallback? onOpenDirectory;
  final VoidCallback? onDetails;
  final VoidCallback? onRequestAppointment;

  const PlaceInfoSheet({
    super.key,
    required this.poi,
    required this.place,
    required this.events,
    required this.visibility,
    required this.onNavigate,
    this.repository,
    this.onOpenDirectory,
    required this.onDetails,
    this.onRequestAppointment,
  });

  @override
  State<PlaceInfoSheet> createState() => _PlaceInfoSheetState();
}

class _PlaceInfoSheetState extends State<PlaceInfoSheet> {
  bool _showFloorPlan = false;
  WorkshopInfo? _workshop;
  bool _postingCollaboration = false;
  final _collaborationController = TextEditingController();

  String get _aboutText {
    final fromPlace = widget.place?.description.trim() ?? '';
    if (fromPlace.isNotEmpty) return fromPlace;
    return widget.poi.note?.trim() ?? '';
  }

  @override
  void initState() {
    super.initState();
    final repository = widget.repository;
    final place = widget.place;
    if (repository != null &&
        place != null &&
        _isWorkshopCategory(widget.poi.category)) {
      unawaited(repository.getWorkshopInfo(place.id).then((info) {
        if (mounted) setState(() => _workshop = info);
      }).catchError((_) {
        // No backend data yet for this place is an honest empty state,
        // handled by _workshop staying null — nothing to show as an error.
      }));
    }
  }

  @override
  void dispose() {
    _collaborationController.dispose();
    super.dispose();
  }

  Future<void> _postCollaboration() async {
    final repository = widget.repository;
    final place = widget.place;
    final text = _collaborationController.text.trim();
    if (repository == null || place == null || text.isEmpty) return;
    setState(() => _postingCollaboration = true);
    try {
      final post = await repository.addCollaborationPost(place.id, text);
      if (!mounted) return;
      setState(() {
        final current = _workshop ?? const WorkshopInfo();
        _workshop = WorkshopInfo(
          equipment: current.equipment,
          posts: [post, ...current.posts],
        );
        _collaborationController.clear();
        _postingCollaboration = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() => _postingCollaboration = false);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(e is ContentModerationException
              ? e.reason
              : 'İlan paylaşılamadı. Tekrar dene.')));
    }
  }

  @override
  Widget build(BuildContext context) {
    final (densityColor, densityLabel) = campusDensityInfo(widget.place);
    final activeCount =
        widget.events.fold<int>(0, (sum, e) => sum + e.attendees);
    final onlineHere = widget.place != null
        ? campusPresenceCount(widget.place!)
        : campusOnlineCount(widget.poi.name);
    final checkins = widget.place?.recentCheckinEntries ?? const [];
    final floors = _floorPlanFor(widget.poi.category);
    final isWorkshop = _isWorkshopCategory(widget.poi.category);

    return Container(
      padding: const EdgeInsets.fromLTRB(20, 18, 20, 28),
      constraints:
          BoxConstraints(maxHeight: MediaQuery.of(context).size.height * .85),
      decoration: BoxDecoration(
          color: Theme.of(context).colorScheme.surface,
          borderRadius: const BorderRadius.vertical(top: Radius.circular(28))),
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
                Text(normalizeCategory(widget.poi.category),
                    style: const TextStyle(
                        color: ArucadColors.muted,
                        fontWeight: FontWeight.w700)),
                if (_aboutText.isNotEmpty) ...[
                  const SizedBox(height: 10),
                  Text(_aboutText,
                      style: const TextStyle(fontSize: 14, height: 1.45)),
                ],
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
                Text(AppLocale.of(context).t('clm_just_now'),
                    style:
                        TextStyle(fontWeight: FontWeight.w900, fontSize: 13)),
                const SizedBox(height: 8),
                if (checkins.isEmpty)
                  Padding(
                    padding: EdgeInsets.only(bottom: 6),
                    child: Text(AppLocale.of(context).t('clm_no_checkins'),
                        style: TextStyle(
                            fontSize: 13, color: ArucadColors.muted)),
                  )
                else
                  for (final c in checkins)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 6),
                      child: Row(children: [
                        const Icon(Icons.photo_camera_back_outlined,
                            size: 15, color: ArucadColors.muted),
                        const SizedBox(width: 6),
                        Expanded(
                            child: Text(_checkinEntryLabel(c),
                                style: const TextStyle(
                                    fontSize: 13, color: ArucadColors.muted))),
                      ]),
                    ),
                if (floors.isNotEmpty) ...[
                  const SizedBox(height: 10),
                  InkWell(
                    onTap: () =>
                        setState(() => _showFloorPlan = !_showFloorPlan),
                    child: Row(children: [
                      const Icon(Icons.layers_outlined,
                          size: 17, color: ArucadColors.primary),
                      const SizedBox(width: 6),
                      Text(AppLocale.of(context).t('clm_floor_plan'),
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
                  Text(AppLocale.of(context).t('clm_workshop_status'),
                      style:
                          TextStyle(fontWeight: FontWeight.w900, fontSize: 13)),
                  const SizedBox(height: 8),
                  if (_workshop == null || _workshop!.equipment.isEmpty)
                    Padding(
                      padding: EdgeInsets.only(bottom: 6),
                      child: Text(AppLocale.of(context).t('clm_no_equipment'),
                          style: TextStyle(
                              fontSize: 13, color: ArucadColors.muted)),
                    )
                  else
                    for (final eq in _workshop!.equipment)
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
                  if (widget.onRequestAppointment != null)
                    OutlinedButton.icon(
                      onPressed: () {
                        Navigator.pop(context);
                        widget.onRequestAppointment!();
                      },
                      icon: const Icon(Icons.calendar_month_outlined),
                      label: const Text('Ask ARUCAD\'a Sor'),
                    ),
                  const SizedBox(height: 6),
                  Text(AppLocale.of(context).t('clm_collab_board'),
                      style:
                          TextStyle(fontWeight: FontWeight.w900, fontSize: 13)),
                  const SizedBox(height: 8),
                  if (_workshop == null || _workshop!.posts.isEmpty)
                    Padding(
                      padding: EdgeInsets.only(bottom: 6),
                      child: Text(AppLocale.of(context).t('clm_no_listings'),
                          style: TextStyle(
                              fontSize: 13, color: ArucadColors.muted)),
                    )
                  else
                    for (final post in _workshop!.posts)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 6),
                        child: Row(children: [
                          const Icon(Icons.push_pin_outlined,
                              size: 15, color: ArucadColors.muted),
                          const SizedBox(width: 6),
                          Expanded(child: Text(post.text)),
                        ]),
                      ),
                  if (widget.repository != null && widget.place != null) ...[
                    const SizedBox(height: 4),
                    Row(children: [
                      Expanded(
                        child: TextField(
                          controller: _collaborationController,
                          enabled: !_postingCollaboration,
                          decoration: InputDecoration(
                            isDense: true,
                            hintText: AppLocale.of(context).t('clm_collab_hint'),
                          ),
                          onSubmitted: (_) => _postCollaboration(),
                        ),
                      ),
                      const SizedBox(width: 8),
                      IconButton(
                        onPressed:
                            _postingCollaboration ? null : _postCollaboration,
                        icon: _postingCollaboration
                            ? const SizedBox(
                                width: 16,
                                height: 16,
                                child: CircularProgressIndicator(
                                    strokeWidth: 2))
                            : const Icon(Icons.send_outlined),
                      ),
                    ]),
                  ],
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
                      label: Text(AppLocale.of(context).t('clm_start_nav')),
                    ),
                  ),
                  if (widget.onOpenDirectory != null) ...[
                    const SizedBox(width: 10),
                    OutlinedButton.icon(
                      onPressed: widget.onOpenDirectory,
                      icon: const Icon(Icons.apartment_outlined, size: 18),
                      label: const Text('Binalar'),
                    ),
                  ],
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
          Icon(icon, size: 15, color: densityForeground(color)),
          const SizedBox(width: 6),
          Text(label,
              style: TextStyle(
                  fontWeight: FontWeight.w800,
                  fontSize: 12,
                  color: densityForeground(color))),
        ]),
      );
}

/// Full-screen version of the same live map — pushed from the small
/// expand button next to Galatea instead of duplicating any map logic.
class CampusMapFullScreen extends StatefulWidget {
  final List<CampusPlace> places;
  final List<CampusEvent> events;
  final CampusRepository repository;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;
  final VoidCallback onOpenGalatea;
  final CampusVisibility initialVisibility;
  final String? focusPlaceId;
  final GeoPoint? userLocation;

  const CampusMapFullScreen({
    super.key,
    required this.places,
    required this.events,
    required this.repository,
    required this.mapProvider,
    required this.analyticsTracker,
    required this.onOpenGalatea,
    this.initialVisibility = CampusVisibility.friends,
    this.focusPlaceId,
    this.userLocation,
  });

  @override
  State<CampusMapFullScreen> createState() => _CampusMapFullScreenState();
}

class _CampusMapFullScreenState extends State<CampusMapFullScreen> {
  late List<CampusPlace> _places = widget.places;
  late List<CampusEvent> _events = widget.events;
  late String? _focusPlaceId = widget.focusPlaceId;
  ChatRealtimeService? _realtime;
  StreamSubscription<List<String>>? _campusChanges;
  bool _refreshing = false;
  GeoPoint? _userLocation;

  @override
  void initState() {
    super.initState();
    _userLocation = widget.userLocation;
    unawaited(_startRealtime());
    unawaited(_resolveUserLocation());
  }

  // Someone who doesn't know a building's name can still find it — a
  // student typing "kütüphane" or "atölye" jumps straight to it instead of
  // having to recognize a pin among 20+ on the map.
  Future<void> _openSearch() async {
    var query = '';
    final selected = await showModalBottomSheet<CampusPlace>(
      context: context,
      isScrollControlled: true,
      builder: (sheetContext) {
        return StatefulBuilder(builder: (sheetContext, setSheetState) {
          final matches = query.trim().isEmpty
              ? _places
              : _places
                  .where((p) =>
                      p.name.toLowerCase().contains(query.toLowerCase()))
                  .toList();
          return SafeArea(
            child: Padding(
              padding: EdgeInsets.only(
                  bottom: MediaQuery.of(sheetContext).viewInsets.bottom),
              child: SizedBox(
                height: MediaQuery.of(sheetContext).size.height * .75,
                child: Column(children: [
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
                    child: TextField(
                      autofocus: true,
                      textInputAction: TextInputAction.search,
                      onChanged: (v) => setSheetState(() => query = v),
                      decoration: InputDecoration(
                        hintText: AppLocale.of(context).t('clm_search_hint'),
                        prefixIcon: Icon(Icons.search),
                        border: OutlineInputBorder(),
                      ),
                    ),
                  ),
                  Expanded(
                    child: matches.isEmpty
                        ? Center(child: Text(AppLocale.of(context).t('clm_no_results')))
                        : ListView.builder(
                            itemCount: matches.length,
                            itemBuilder: (context, i) => ListTile(
                              leading: const Icon(Icons.location_on_outlined),
                              title: Text(matches[i].name),
                              subtitle: matches[i].category.isEmpty
                                  ? null
                                  : Text(matches[i].category),
                              onTap: () =>
                                  Navigator.of(sheetContext).pop(matches[i]),
                            ),
                          ),
                  ),
                ]),
              ),
            ),
          );
        });
      },
    );
    if (selected != null && mounted) {
      setState(() => _focusPlaceId = selected.id);
    }
  }

  Future<void> _resolveUserLocation() async {
    try {
      final position =
          await const LocationService().getCurrentPositionIfGranted();
      if (position == null || !mounted) return;
      setState(() =>
          _userLocation = GeoPoint(position.latitude, position.longitude));
    } catch (_) {
      // The map remains usable without an OS location permission.
    }
  }

  Future<void> _startRealtime() async {
    try {
      final me = await widget.repository.getMe();
      if (!mounted) return;
      final realtime = ChatRealtimeService.forRepository(widget.repository);
      _realtime = realtime;
      _campusChanges = realtime.campusChanged.listen((resources) {
        if (resources.contains('events') || resources.contains('places')) {
          unawaited(_refreshMapData(resources));
        }
      });
      await realtime.start(userId: me.id, userName: me.name);
    } catch (_) {
      // The map remains usable with the snapshot that opened it.
    }
  }

  Future<void> _refreshMapData(List<String> resources) async {
    if (_refreshing) return;
    _refreshing = true;
    try {
      final wantsPlaces = resources.contains('places');
      final wantsEvents = resources.contains('events');
      final values = await Future.wait([
        if (wantsPlaces) widget.repository.getPlaces(),
        if (wantsEvents) widget.repository.getEvents(),
      ]);
      if (!mounted) return;
      var offset = 0;
      setState(() {
        if (wantsPlaces) _places = values[offset++] as List<CampusPlace>;
        if (wantsEvents) _events = values[offset++] as List<CampusEvent>;
      });
    } catch (_) {
      // REST data is authoritative; keep the existing view on a transient failure.
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

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: const Text('ARUCAD Social Map'),
          actions: [
            IconButton(
              tooltip: 'Yer ara',
              icon: const Icon(Icons.search),
              onPressed: _openSearch,
            ),
          ],
        ),
        body: Padding(
          padding: const EdgeInsets.all(12),
          child: SizedBox.expand(
            child: CampusLiveMap(
              places: _places,
              events: _events,
              repository: widget.repository,
              mapProvider: widget.mapProvider,
              analyticsTracker: widget.analyticsTracker,
              onOpenGalatea: widget.onOpenGalatea,
              mapHeight: null,
              initialVisibility: widget.initialVisibility,
              focusPlaceId: _focusPlaceId,
              userLocation: _userLocation,
            ),
          ),
        ),
      );
}
