import 'dart:async';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/config/campus_sites.dart';
import 'package:arucad_campus_prototype/core/config/place_catalog.dart';
import 'package:arucad_campus_prototype/core/config/place_tour.dart';
import 'package:arucad_campus_prototype/core/config/poi_config.dart';
import 'package:arucad_campus_prototype/core/config/shuttle_config.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/content_moderation.dart';
import 'package:arucad_campus_prototype/core/services/chat_realtime_service.dart';
import 'package:arucad_campus_prototype/core/services/location_service.dart';
import 'package:arucad_campus_prototype/core/services/tour_launcher.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/guide/ask_arucad_bubble.dart';
import 'package:arucad_campus_prototype/features/guide/guide_sheet.dart';
import 'package:arucad_campus_prototype/features/home/shuttle_sheet.dart';
import 'package:arucad_campus_prototype/features/map/heatmap_adapter.dart';
import 'package:arucad_campus_prototype/features/map/in_app_navigation_screen.dart';
import 'package:arucad_campus_prototype/features/map/map_pointer_guard.dart';
import 'package:arucad_campus_prototype/features/map/maplibre_campus_map.dart';
import 'package:arucad_campus_prototype/features/map/tour_360_screen.dart';
import 'package:arucad_campus_prototype/features/place/place_detail_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

enum _MapViewMode { map, tour360 }

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
  VoidCallback? onTour,
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
      onTour: onTour,
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
  _MapViewMode _viewMode = _MapViewMode.map;
  CampusPlace? _selectedPlace;
  CampusSite? _selectedSite;

  @override
  void initState() {
    super.initState();
    _syncFocusPlace();
  }

  @override
  void didUpdateWidget(covariant CampusLiveMap oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.places != widget.places) {
      _pulseZones = HeatmapAdapter.pulseZones(widget.places);
    }
    if (oldWidget.focusPlaceId != widget.focusPlaceId ||
        oldWidget.places != widget.places) {
      _syncFocusPlace();
    }
  }

  void _syncFocusPlace() {
    final focusId = widget.focusPlaceId;
    if (focusId == null) return;
    for (final place in campusMapPlaces(widget.places)) {
      if (place.id == focusId) {
        _selectedPlace = place;
        _selectedSite = nearestSite(place.lat, place.lng);
        return;
      }
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

  CampusSite get _tourSite {
    final selected = _selectedSite;
    if (selected != null) return selected;
    final place = _selectedPlace;
    if (place != null) return nearestSite(place.lat, place.lng);
    final user = widget.userLocation;
    if (user != null) return nearestSite(user.lat, user.lng);
    return campusSites.first;
  }

  String get _tourTitle {
    final place = _selectedPlace;
    if (place != null) return place.name;
    return _tourSite.name;
  }

  Future<void> _openTourForPlace(CampusPlace? place, {CampusSite? site}) async {
    // Real fix for "the 360 preview is slow / never opens": the mini
    // in-map 360 panel (_MapTour360Panel, shown in tour360 view mode) stays
    // mounted and its player keeps running underneath the full-screen
    // Tour360Screen pushed on top of it — two full 3DVista players loading
    // the same tour's scripts/media at once, fighting over bandwidth and
    // the WebGL/WebView context. Switching away from tour360 mode disposes
    // the mini panel's player before the full-screen one starts; switching
    // back after it's dismissed restores the toggle exactly as the student
    // left it.
    final restoreMode = _viewMode;
    if (_viewMode == _MapViewMode.tour360) {
      setState(() => _viewMode = _MapViewMode.map);
    }
    try {
      if (place != null) {
        final tour = resolvePlaceTour(place);
        widget.analyticsTracker.track(
            'tour_opened', {'place': place.name, 'tourUrl': tour.url});
        await open360Tour(
          context,
          tour.url,
          tourTarget: tour.target,
          title: place.name,
        );
        return;
      }
      final resolved = site ?? _tourSite;
      widget.analyticsTracker.track('tour_opened',
          {'place': resolved.name, 'tourUrl': resolved.tourUrl});
      await open360Tour(context, resolved.tourUrl, title: resolved.name);
    } finally {
      if (mounted && restoreMode != _viewMode) {
        setState(() => _viewMode = restoreMode);
      }
    }
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

    final mapBox = ClipRRect(
      borderRadius: BorderRadius.circular(26),
      child: Stack(children: [
        Positioned.fill(
          child: _viewMode == _MapViewMode.map
              ? CampusMapView(
                  extentPoints: extentPoints,
                  focusPoint: focusPoint,
                  markers: labelMarkers,
                  contextDots: contextDots,
                  pulseZones: _pulseZones,
                  userLocation: widget.userLocation,
                  showUserLocation: true,
                  controller: _mapController,
                )
              : _MapTour360Panel(
                  title: _tourTitle,
                  tourUrl: _selectedPlace != null
                      ? resolvePlaceTour(_selectedPlace!).url
                      : _tourSite.tourUrl,
                  tourTarget: _selectedPlace != null
                      ? resolvePlaceTour(_selectedPlace!).target
                      : null,
                  selectedSiteId: _tourSite.id,
                  onOpenFullscreen: () => _openTourForPlace(
                    _selectedPlace,
                    site: _tourSite,
                  ),
                  onSelectSite: (site) => setState(() {
                    _selectedSite = site;
                    _selectedPlace = null;
                  }),
                ),
        ),
        if (_viewMode == _MapViewMode.map)
          Positioned(
            left: 14,
            top: 14,
            child: _MapControlButton(
              onlineCount: _totalOnline,
              visibility: _visibility,
              onVisibilityChanged: (v) => setState(() => _visibility = v),
              onOpenShuttle: () =>
                  showShuttleSheet(context, repository: widget.repository),
              onOpenFullMap: isPreview ? () => _openFullMap() : null,
            ),
          ),
        if (_viewMode == _MapViewMode.map)
          Positioned(
            right: 14,
            top: 14,
            child: AskArucadBubble(
                onTap: isPreview ? () => _openFullMap() : _openAskArucad),
          ),
        Positioned(
          right: 14,
          bottom: widget.mapHeight != null ? 54 : 14,
          child: _MapViewModeToggle(
            mode: _viewMode,
            onChanged: (mode) => setState(() => _viewMode = mode),
          ),
        ),
        if (widget.mapHeight != null && _viewMode == _MapViewMode.map)
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
    setState(() {
      _selectedPlace = place;
      _selectedSite = nearestSite(poi.lat, poi.lng);
    });
    final events = eventsAtPlace(widget.events, place?.name ?? poi.name);
    showPlaceInfoSheet(
      context,
      poi: poi,
      place: place,
      events: events,
      visibility: _visibility,
      repository: widget.repository,
      onNavigate: () => _navigate(poi),
      onTour: () {
        Navigator.of(context).pop();
        unawaited(
            _openTourForPlace(place, site: nearestSite(poi.lat, poi.lng)));
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
            const Text('Bölgeler',
                style: TextStyle(fontSize: 11, fontWeight: FontWeight.w800)),
            const SizedBox(width: 9),
            _DensityDot(color: ArucadColors.campusGreen, label: 'Sakin'),
            const SizedBox(width: 6),
            _DensityDot(color: ArucadColors.yellow, label: 'Orta'),
            const SizedBox(width: 6),
            _DensityDot(color: ArucadColors.danger, label: 'Yoğun'),
          ]),
        ),
      );
}

class _MapViewModeToggle extends StatelessWidget {
  final _MapViewMode mode;
  final ValueChanged<_MapViewMode> onChanged;

  const _MapViewModeToggle({required this.mode, required this.onChanged});

  @override
  Widget build(BuildContext context) {
    final surface = Theme.of(context).colorScheme.surface;
    return MapPointerGuard(
      child: Material(
        color: surface.withValues(alpha: .96),
        elevation: 3,
        shadowColor: Colors.black26,
        borderRadius: BorderRadius.circular(14),
        child: Padding(
          padding: const EdgeInsets.all(3),
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            _ModeChip(
              label: 'Harita',
              selected: mode == _MapViewMode.map,
              onTap: () => onChanged(_MapViewMode.map),
            ),
            _ModeChip(
              label: '360°',
              selected: mode == _MapViewMode.tour360,
              onTap: () => onChanged(_MapViewMode.tour360),
            ),
          ]),
        ),
      ),
    );
  }
}

class _ModeChip extends StatelessWidget {
  final String label;
  final bool selected;
  final VoidCallback onTap;

  const _ModeChip({
    required this.label,
    required this.selected,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(11),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 160),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        decoration: BoxDecoration(
          color: selected ? ArucadColors.primary : Colors.transparent,
          borderRadius: BorderRadius.circular(11),
        ),
        child: Text(
          label,
          style: TextStyle(
            fontSize: 12,
            fontWeight: FontWeight.w800,
            color: selected ? Colors.white : ArucadColors.ink,
          ),
        ),
      ),
    );
  }
}

class _MapTour360Panel extends StatelessWidget {
  final String title;
  final String tourUrl;
  final String? tourTarget;
  final String selectedSiteId;
  final VoidCallback onOpenFullscreen;
  final ValueChanged<CampusSite> onSelectSite;

  const _MapTour360Panel({
    required this.title,
    required this.tourUrl,
    required this.selectedSiteId,
    required this.onOpenFullscreen,
    required this.onSelectSite,
    this.tourTarget,
  });

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final resolved = composeTourUrl(tourUrl, tourTarget);
    return ColoredBox(
      color: scheme.surface,
      child: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 10, 12, 8),
              child: Column(
                children: [
                  Text(
                    title,
                    textAlign: TextAlign.center,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                        fontWeight: FontWeight.w900, fontSize: 16),
                  ),
                  const SizedBox(height: 8),
                  SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: Row(
                      children: [
                        for (final site in campusSites) ...[
                          Padding(
                            padding: const EdgeInsets.only(right: 6),
                            child: FilterChip(
                              label: Text(site.name.split(' ').first),
                              selected: site.id == selectedSiteId,
                              selectedColor:
                                  ArucadColors.primary.withValues(alpha: .16),
                              checkmarkColor: ArucadColors.primary,
                              onSelected: (_) => onSelectSite(site),
                            ),
                          ),
                        ],
                      ],
                    ),
                  ),
                ],
              ),
            ),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(10, 0, 10, 8),
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(16),
                  child: Tour360View(url: resolved, expand: true),
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
              child: FilledButton.icon(
                onPressed: onOpenFullscreen,
                icon: const Icon(Icons.fullscreen),
                label: const Text('Tam ekran 360°'),
                style: FilledButton.styleFrom(
                  backgroundColor: ArucadColors.primary,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 12),
                  textStyle: const TextStyle(
                      fontWeight: FontWeight.w800, fontSize: 14),
                ),
              ),
            ),
          ],
        ),
      ),
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

  const _MapControlButton({
    required this.onlineCount,
    required this.visibility,
    required this.onVisibilityChanged,
    required this.onOpenShuttle,
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
  final VoidCallback? onTour;
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
    this.onTour,
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
                const Text('Az önce',
                    style:
                        TextStyle(fontWeight: FontWeight.w900, fontSize: 13)),
                const SizedBox(height: 8),
                if (checkins.isEmpty)
                  const Padding(
                    padding: EdgeInsets.only(bottom: 6),
                    child: Text('Henüz check-in yok',
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
                      style:
                          TextStyle(fontWeight: FontWeight.w900, fontSize: 13)),
                  const SizedBox(height: 8),
                  if (_workshop == null || _workshop!.equipment.isEmpty)
                    const Padding(
                      padding: EdgeInsets.only(bottom: 6),
                      child: Text('Ekipman bilgisi henüz eklenmedi',
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
                  const Text('İş Birliği Panosu',
                      style:
                          TextStyle(fontWeight: FontWeight.w900, fontSize: 13)),
                  const SizedBox(height: 8),
                  if (_workshop == null || _workshop!.posts.isEmpty)
                    const Padding(
                      padding: EdgeInsets.only(bottom: 6),
                      child: Text('Henüz ilan yok',
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
                          decoration: const InputDecoration(
                            isDense: true,
                            hintText: 'Malzeme takası, model arama...',
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
                      label: const Text('Navigasyonu Başlat'),
                    ),
                  ),
                  if (widget.onTour != null) ...[
                    const SizedBox(width: 10),
                    OutlinedButton.icon(
                      onPressed: widget.onTour,
                      icon: const Icon(Icons.threed_rotation, size: 18),
                      label: const Text('360°'),
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
        appBar: AppBar(title: const Text('ARUCAD Social Map')),
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
              focusPlaceId: widget.focusPlaceId,
              userLocation: _userLocation,
            ),
          ),
        ),
      );
}
