import 'dart:async';

import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';

import 'package:arucad_campus_prototype/core/config/place_catalog.dart';
import 'package:arucad_campus_prototype/core/config/place_tour.dart';
import 'package:arucad_campus_prototype/core/config/poi_config.dart';
import 'package:arucad_campus_prototype/core/config/shuttle_config.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/campus_weather.dart';
import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/content_moderation.dart';
import 'package:arucad_campus_prototype/core/services/directions_result.dart';
import 'package:arucad_campus_prototype/core/services/chat_realtime_service.dart';
import 'package:arucad_campus_prototype/core/services/location_service.dart';
import 'package:arucad_campus_prototype/core/services/tour_launcher.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/core/utils/relative_time.dart';
import 'package:arucad_campus_prototype/features/guide/guide_sheet.dart';
import 'package:arucad_campus_prototype/features/home/shuttle_sheet.dart';
import 'package:arucad_campus_prototype/features/map/heatmap_adapter.dart';
import 'package:arucad_campus_prototype/features/map/activity_point.dart'
    as activity;
import 'package:arucad_campus_prototype/features/map/in_app_navigation_screen.dart';
import 'package:arucad_campus_prototype/features/map/map_pointer_guard.dart';
import 'package:arucad_campus_prototype/features/map/maplibre_campus_map.dart';
import 'package:arucad_campus_prototype/features/place/place_detail_screen.dart';
import 'package:arucad_campus_prototype/features/services/building_directory_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

/// Konum görünürlüğü — Gizli (ghost) / Arkadaşlarım (friends) / Topluluğum
/// (community) / Herkes (public), en kısıtlıdan en açığa doğru sıralı.
enum CampusVisibility { ghost, friends, community, public }

enum MapContentFilter { all, people, events, places, popular }

List<CampusEvent> _eventsAtMapPlace(
    List<CampusEvent> events, CampusPlace place) {
  return events.where((event) {
    if (event.placeId != null && event.placeId == place.id) return true;
    final eventPlace = event.placeName.trim().toLowerCase();
    final placeName = place.name.trim().toLowerCase();
    if (eventPlace.isEmpty || placeName.isEmpty) return false;
    return eventPlace == placeName ||
        eventPlace.contains(placeName) ||
        placeName.contains(eventPlace);
  }).toList();
}

bool mapPlaceMatchesFilter({
  required MapContentFilter filter,
  required CampusPlace place,
  required List<CampusEvent> events,
  required int liveCount,
}) =>
    switch (filter) {
      MapContentFilter.all || MapContentFilter.places => true,
      MapContentFilter.people || MapContentFilter.popular => liveCount > 0,
      MapContentFilter.events => _eventsAtMapPlace(events, place).isNotEmpty,
    };

String _mapSearchKey(String value) => value
    .toLowerCase()
    .replaceAll('ı', 'i')
    .replaceAll('ğ', 'g')
    .replaceAll('ü', 'u')
    .replaceAll('ş', 's')
    .replaceAll('ö', 'o')
    .replaceAll('ç', 'c')
    .replaceAll(RegExp(r'\s+'), ' ')
    .trim();

Set<String> _directoryIdentityTerms(DirectoryEntry entry) {
  const ignored = {'ofis', 'ofisi', 'office', 'oda', 'room'};
  return _mapSearchKey('${entry.room ?? ''} ${entry.occupantName}')
      .split(RegExp(r'[^a-z0-9]+'))
      .where((word) => word.length > 2 && !ignored.contains(word))
      .toSet();
}

bool _hasAuthoritativeRoomEquivalent(
  DirectoryEntry curated,
  List<DirectoryEntry> directory,
) {
  if (curated.id.startsWith('360-') || curated.relatedServiceId == null) {
    return false;
  }
  final terms = _directoryIdentityTerms(curated);
  if (terms.isEmpty) return false;
  return directory.any((candidate) {
    if (!candidate.id.startsWith('360-') ||
        _mapSearchKey(candidate.building) != _mapSearchKey(curated.building)) {
      return false;
    }
    return terms.intersection(_directoryIdentityTerms(candidate)).length >= 2;
  });
}

class MapSearchTarget {
  final CampusPlace place;
  final CampusEvent? event;
  final DirectoryEntry? directory;

  const MapSearchTarget({required this.place, this.event, this.directory});

  String get title {
    if (event != null) return event!.title;
    final entry = directory;
    if (entry == null) return place.name;
    if ((entry.room ?? '').trim().isNotEmpty) return entry.room!.trim();
    if (entry.occupantName.trim().isNotEmpty) return entry.occupantName.trim();
    return entry.building;
  }

  String get subtitle {
    if (event != null) return '${event!.placeName} · ${event!.category}';
    final entry = directory;
    if (entry == null) return place.category;
    return <String>[
      entry.building,
      if ((entry.floor ?? '').trim().isNotEmpty) entry.floor!.trim(),
      if ((entry.categoryName ?? entry.occupantRole ?? '').trim().isNotEmpty)
        (entry.categoryName ?? entry.occupantRole)!.trim(),
      if (entry.occupantName.trim().isNotEmpty &&
          entry.occupantName.trim() != title)
        entry.occupantName.trim(),
    ].join(' · ');
  }
}

/// Resolve an official directory building onto a verified map pin. The 360
/// API currently has no coordinates, so room searches deliberately inherit
/// their building's curated coordinates instead of inventing room pins.
CampusPlace? campusPlaceForDirectoryBuilding(
  List<CampusPlace> places,
  String building,
) {
  final aliases = <String, String>{
    'iris': 'iris (atelier building)',
    'bandabuliya': 'nicosia bandabuliya campus',
    'bandabuliya kampus': 'nicosia bandabuliya campus',
    'daniede': 'daniele',
  };
  final key = _mapSearchKey(building);
  final target = aliases[key] ?? key;
  for (final place in campusMapPlaces(places)) {
    final placeKey = _mapSearchKey(place.name);
    if (placeKey == key || placeKey == target) return place;
  }
  for (final place in campusMapPlaces(places)) {
    final placeKey = _mapSearchKey(place.name);
    if (placeKey.contains(key) ||
        key.contains(placeKey) ||
        placeKey.contains(target) ||
        target.contains(placeKey)) {
      return place;
    }
  }
  return null;
}

List<MapSearchTarget> mapSearchTargets(
    List<CampusPlace> places, List<CampusEvent> events, String query,
    {List<DirectoryEntry> directory = const []}) {
  final mappedPlaces = campusMapPlaces(places);
  final needle = _mapSearchKey(query);
  bool contains(String value) => _mapSearchKey(value).contains(needle);
  final results = <MapSearchTarget>[
    for (final place in mappedPlaces)
      if (needle.isEmpty ||
          contains(place.name) ||
          contains(place.category) ||
          contains(place.street))
        MapSearchTarget(place: place),
  ];
  for (final event in events) {
    if (needle.isNotEmpty &&
        !contains(event.title) &&
        !contains(event.placeName) &&
        !contains(event.category)) {
      continue;
    }
    CampusPlace? venue;
    for (final place in mappedPlaces) {
      if ((event.placeId != null && event.placeId == place.id) ||
          _eventsAtMapPlace([event], place).isNotEmpty) {
        venue = place;
        break;
      }
    }
    if (venue != null) results.add(MapSearchTarget(place: venue, event: event));
  }
  // The empty search remains a compact list of map POIs. Once a student
  // types, include rooms, services and staff from the live 360 directory.
  if (needle.isNotEmpty) {
    final seen = <String>{};
    for (final entry in directory) {
      // A curated service row and its newly synced official room can both
      // describe the same destination. Show the authoritative room once,
      // including its official code, instead of two confusing results.
      if (_hasAuthoritativeRoomEquivalent(entry, directory)) continue;
      if (![
        entry.building,
        entry.floor ?? '',
        entry.room ?? '',
        entry.occupantName,
        entry.occupantRole ?? '',
        entry.categoryName ?? '',
        entry.roomNumber ?? '',
        entry.campusName ?? ''
      ].any(contains)) {
        continue;
      }
      final place = campusPlaceForDirectoryBuilding(places, entry.building);
      if (place == null || !seen.add(entry.id)) continue;
      results.add(MapSearchTarget(place: place, directory: entry));
    }
  }
  return results;
}

extension CampusVisibilityLabel on CampusVisibility {
  IconData get icon => switch (this) {
        CampusVisibility.public => Icons.public,
        CampusVisibility.friends => Icons.people_alt_outlined,
        CampusVisibility.community => Icons.diversity_3_outlined,
        CampusVisibility.ghost => Icons.visibility_off_outlined,
      };
  String labelFor(AppStrings strings) => switch (this) {
        CampusVisibility.public => strings.t('clm_visibility_public'),
        CampusVisibility.friends => strings.t('clm_visibility_friends'),
        CampusVisibility.community => strings.t('clm_visibility_community'),
        CampusVisibility.ghost => strings.t('clm_visibility_ghost'),
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
  required void Function(TravelMode) onNavigate,
  CampusRepository? repository,
  VoidCallback? onOpenDirectory,
  VoidCallback? onDetails,
  VoidCallback? onRequestAppointment,
  CampusVisibility visibility = CampusVisibility.friends,
  int? liveCount,
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
      liveCount: liveCount,
      onNavigate: onNavigate,
      onOpenDirectory: onOpenDirectory,
      onDetails: onDetails,
      onRequestAppointment: onRequestAppointment,
    ),
  );
}

// Real check-in entries come from the backend (`PlacePresence`); a place
// with none simply shows an honest empty state instead of fabricated names.
/// "AY · 5m ago checked in".
///
/// Was a Turkish sentence with its own inline elapsed-time arithmetic, so
/// an English or Russian reader saw "AY · 5 dk önce check-in yaptı" and the
/// minutes never moved while the sheet stayed open. The elapsed part is now
/// [LiveTimeAgo], which is why this returns a widget rather than a string.
Widget _checkinEntryLabel(BuildContext context, CampusCheckinEntry entry) {
  final strings = AppLocale.of(context);
  const style = TextStyle(fontSize: 13, color: ArucadColors.muted);
  final at = entry.checkedInAt;

  if (at == null) {
    return Text('${entry.initial} ${strings.t('map_checkin_by')}',
        style: style);
  }

  return Row(mainAxisSize: MainAxisSize.min, children: [
    Text('${entry.initial} · ', style: style),
    LiveTimeAgo(at, style: style),
    Text(' ${strings.t('map_checkin_by')}', style: style),
  ]);
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
  final bool followUserLocation;
  final MapContentFilter contentFilter;
  final CampusMapController? controller;

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
    this.followUserLocation = false,
    this.contentFilter = MapContentFilter.all,
    this.controller,
    this.mapHeight = 400,
    this.initialVisibility = CampusVisibility.friends,
    this.focusPlaceId,
  });

  @override
  State<CampusLiveMap> createState() => _CampusLiveMapState();
}

class _CampusLiveMapState extends State<CampusLiveMap> {
  /// How often the map refreshes the crowd counts, and how often it tells
  /// the backend where this phone is. One minute is well inside the
  /// server's presence window (`LiveCrowd.WINDOW_MINUTES`), so a student
  /// standing still never flickers out of the count, and it is slow
  /// enough that an open map is not a radio drain.
  static const _presenceInterval = Duration(minutes: 1);

  /// Don't re-ping for a fix that has barely moved — GPS jitter alone
  /// would otherwise write a row every tick for a phone on a desk.
  static const _presenceMoveMeters = 15.0;

  late List<CampusPulseZone> _pulseZones =
      HeatmapAdapter.pulseZones(widget.places);
  DateTime _activitySnapshotAt = DateTime.now();
  late CampusVisibility _visibility = widget.initialVisibility;
  final _internalMapController = CampusMapController();
  CampusMapController get _mapController =>
      widget.controller ?? _internalMapController;

  CampusLiveCrowd _crowd = const CampusLiveCrowd();
  Timer? _presenceTimer;
  GeoPoint? _lastPingedFrom;
  bool _presenceBusy = false;
  CampusUser? _me;

  @override
  void initState() {
    super.initState();
    unawaited(_loadIdentity());
    unawaited(_syncPresence());
    _presenceTimer = Timer.periodic(_presenceInterval, (_) {
      unawaited(_syncPresence());
    });
  }

  Future<void> _loadIdentity() async {
    try {
      final me = await widget.repository.getMe();
      if (mounted) setState(() => _me = me);
    } catch (_) {
      // A map still works without an avatar; the renderer uses an initial.
    }
  }

  @override
  void dispose() {
    _presenceTimer?.cancel();
    super.dispose();
  }

  @override
  void didUpdateWidget(covariant CampusLiveMap oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.places != widget.places) {
      _rebuildPulseZones();
    }
    // A first fix (or a real move) is worth reporting straight away rather
    // than at the next tick — that is the difference between the crowd
    // list being right when the panel opens and being a minute stale.
    if (oldWidget.userLocation != widget.userLocation && _movedEnoughToPing()) {
      unawaited(_syncPresence());
    }
  }

  bool _movedEnoughToPing() {
    final here = widget.userLocation;
    if (here == null) return false;
    final last = _lastPingedFrom;
    if (last == null) return true;
    return Geolocator.distanceBetween(last.lat, last.lng, here.lat, here.lng) >=
        _presenceMoveMeters;
  }

  /// Report where we are (when sharing is on) and read back the anonymous
  /// crowd counts. Both halves are best-effort: the map is still a map
  /// without them, so a failure leaves the last good snapshot on screen
  /// rather than throwing an error banner over the campus.
  Future<void> _syncPresence() async {
    if (_presenceBusy) return;
    _presenceBusy = true;
    try {
      final here = widget.userLocation;
      // Ghost mode is enforced server-side too (it deletes any row this
      // account has); not sending is simply the honest client half of the
      // same promise.
      if (_visibility == CampusVisibility.ghost) {
        if (_lastPingedFrom != null) {
          _lastPingedFrom = null;
          await widget.repository.forgetPresence();
        }
      } else if (here != null) {
        await widget.repository
            .pingPresence(latitude: here.lat, longitude: here.lng);
        _lastPingedFrom = here;
      }

      final crowd = await widget.repository.getLiveCrowd();
      if (!mounted) return;
      setState(() {
        _crowd = crowd;
        // The glow is the Campus Pulse. Rebuilt here so it shows where
        // people actually are, rather than where the few who check in
        // chose to announce themselves.
        _rebuildPulseZones();
      });
    } catch (_) {
      // Keep whatever counts are already on screen.
    } finally {
      _presenceBusy = false;
    }
  }

  void _rebuildPulseZones() {
    _activitySnapshotAt = DateTime.now();
    _pulseZones = HeatmapAdapter.pulseZones(
      widget.places,
      live: {for (final entry in _crowd.places) entry.placeId: entry.count},
    );
  }

  /// Live head count at [place] from location pings, or null when this
  /// place is not in the current snapshot — which the place sheet shows
  /// as its own honest fallback rather than as zero.
  int? _liveCountAt(CampusPlace? place) {
    if (place == null) return null;
    for (final entry in _crowd.places) {
      if (entry.placeId == place.id) return entry.count;
    }
    return null;
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
      final liveCount = _liveCountAt(place) ?? 0;
      final visible = mapPlaceMatchesFilter(
        filter: widget.contentFilter,
        place: place,
        events: widget.events,
        liveCount: liveCount,
      );
      if (!visible) continue;
      labelMarkers.add(CampusMapMarker(
        id: 'place-${place.id}',
        position: pin.display,
        label: place.name,
        color: _liveCountAt(place) != null
            ? crowdColorForCount(_liveCountAt(place)!)
            : campusDensityInfo(place).$1,
        // A compact home preview must not layer a modal over the feed. Its
        // interactive POIs always continue in the dedicated map instead.
        onTap: isPreview
            ? () => _openFullMap(focusPlaceId: place.id)
            : () => _openInfo(poiFromPlace(place), display: pin.display),
      ));
    }
    const contextDots = <CampusMapContextDot>[];
    final activityPoints = _activityPointsForFilter(pins);

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
    if (widget.followUserLocation && widget.userLocation != null) {
      focusPoint = widget.userLocation;
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
            activityPoints: activityPoints,
            userLocation: widget.userLocation,
            showUserLocation: true,
            userName: _me?.name ?? '',
            userAvatarUrl: _me?.avatarUrl,
            controller: _mapController,
          ),
        ),
        Positioned(
          left: 14,
          top: isPreview ? 14 : 154,
          child: _MapControlButton(
            crowd: _crowd,
            visibility: _visibility,
            repository: widget.repository,
            onVisibilityChanged: (v) {
              setState(() => _visibility = v);
              // Going hidden has to take effect now, not at the next
              // tick — "I am invisible" is not a promise to keep for
              // up to a minute.
              unawaited(_syncPresence());
            },
            onOpenShuttle: () =>
                showShuttleSheet(context, repository: widget.repository),
            onOpenFullMap: isPreview ? () => _openFullMap() : null,
          ),
        ),
        if (widget.mapHeight != null)
          const Positioned(
            left: 14,
            bottom: 14,
            child: _MapPreviewLegend(),
          ),
        if (!isPreview)
          Positioned(
            left: 14,
            right: 14,
            bottom: 18,
            child: _NearbyActivityCard(
              people: _crowd.total,
              events: widget.events.length,
              places: widget.places.length,
            ),
          ),
      ]),
    );

    return widget.mapHeight == null
        ? mapBox
        : SizedBox(height: widget.mapHeight, child: mapBox);
  }

  List<activity.ActivityPoint> _activityPointsForFilter(
      List<CampusMapPin> pins) {
    final now = _activitySnapshotAt;
    if (widget.contentFilter == MapContentFilter.events) {
      return [
        for (final pin in pins)
          if (_eventsAtMapPlace(widget.events, pin.place).isNotEmpty)
            activity.ActivityPoint(
              id: 'event-${pin.place.id}',
              position: pin.display,
              weight: (_eventsAtMapPlace(widget.events, pin.place)
                          .fold<int>(0, (sum, event) => sum + event.attendees) /
                      40)
                  .clamp(.35, 1.0)
                  .toDouble(),
              timestamp: now,
              type: activity.ActivityType.event,
              privacyRadiusMeters: 0,
            ),
      ];
    }
    if (widget.contentFilter == MapContentFilter.places) {
      return [
        for (final pin in pins)
          activity.ActivityPoint(
            id: 'place-${pin.place.id}',
            position: pin.display,
            weight: .3,
            timestamp: now,
            type: activity.ActivityType.place,
            privacyRadiusMeters: 0,
          ),
      ];
    }

    final minimumWeight =
        widget.contentFilter == MapContentFilter.popular ? .55 : 0.0;
    return [
      for (var i = 0; i < _pulseZones.length; i++)
        if (_pulseZones[i].intensity >= minimumWeight)
          activity.ActivityPoint(
            id: 'campus-activity-$i',
            position: _pulseZones[i].center,
            weight: _pulseZones[i].intensity,
            timestamp: now,
            type: activity.ActivityType.user,
            // Aggregated crowd data should never reveal an exact person's fix.
            privacyRadiusMeters: 8,
          ),
    ];
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
      liveCount: _liveCountAt(place),
      onNavigate: (mode) => _navigate(poi, mode),
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

  void _navigate(Poi poi, [TravelMode mode = TravelMode.walking]) {
    Navigator.of(context).pop();
    widget.analyticsTracker
        .track('route_started', {'place': poi.name, 'mode': mode.name});
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => InAppNavigationScreen(
            destinationName: poi.name,
            destination: GeoPoint(poi.lat, poi.lng),
            repository: widget.repository,
            mapProvider: widget.mapProvider,
            analyticsTracker: widget.analyticsTracker,
            initialMode: mode)));
  }
}

class _NearbyActivityCard extends StatelessWidget {
  final int people;
  final int events;
  final int places;

  const _NearbyActivityCard({
    required this.people,
    required this.events,
    required this.places,
  });

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final scheme = Theme.of(context).colorScheme;
    return MapPointerGuard(
      child: Container(
        padding: const EdgeInsets.fromLTRB(16, 13, 16, 13),
        decoration: BoxDecoration(
          color: scheme.surface.withValues(alpha: .96),
          borderRadius: BorderRadius.circular(20),
          boxShadow: const [
            BoxShadow(
                color: Colors.black26, blurRadius: 16, offset: Offset(0, 5)),
          ],
        ),
        child: Row(children: [
          Expanded(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(strings.t('clm_nearby_activity'),
                    style: const TextStyle(
                        fontWeight: FontWeight.w900, fontSize: 14)),
                const SizedBox(height: 4),
                Text(
                  strings.t('clm_activity_summary', {
                    'people': people,
                    'events': events,
                    'places': places,
                  }),
                  style:
                      TextStyle(fontSize: 12, color: scheme.onSurfaceVariant),
                ),
              ],
            ),
          ),
          Container(
            width: 9,
            height: 9,
            decoration: const BoxDecoration(
              color: Color(0xFF16A34A),
              shape: BoxShape.circle,
            ),
          ),
          const SizedBox(width: 6),
          Text(strings.t('clm_live_now'),
              style:
                  const TextStyle(fontSize: 11, fontWeight: FontWeight.w800)),
        ]),
      ),
    );
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
            _DensityDot(
                color: ArucadColors.campusGreen,
                label: AppLocale.of(context).t('clm_quiet')),
            const SizedBox(width: 6),
            _DensityDot(
                color: ArucadColors.yellow,
                label: AppLocale.of(context).t('clm_moderate')),
            const SizedBox(width: 6),
            _DensityDot(
                color: ArucadColors.danger,
                label: AppLocale.of(context).t('clm_busy')),
          ]),
        ),
      );
}

/// Everything the map knows right now, in one panel: who is actually on
/// campus and where the crowd is, today's weather, and every shuttle line
/// with its stops and departure times.
///
/// Public for the same reason [PlaceInfoSheet] is: the map itself cannot
/// be pumped in a widget test (MapLibre needs a platform view), so this
/// panel's behaviour is tested by building it directly.
///
/// Two things it deliberately does NOT do. It does not count down to the
/// next departure: the timetable is the useful fact on a map panel, and a
/// live countdown implied a vehicle feed that does not exist (the
/// dedicated shuttle sheet behind "Tümü" still does the arithmetic for
/// anyone actually catching one). And the crowd list is not built from
/// check-ins — see [CampusLiveCrowd].
class MapInfoSheet extends StatefulWidget {
  final CampusLiveCrowd crowd;
  final CampusVisibility visibility;
  final CampusRepository repository;
  final VoidCallback onOpenShuttle;
  final VoidCallback onChangeVisibility;

  const MapInfoSheet({
    super.key,
    required this.crowd,
    required this.visibility,
    required this.repository,
    required this.onOpenShuttle,
    required this.onChangeVisibility,
  });

  @override
  State<MapInfoSheet> createState() => _MapInfoSheetState();
}

class _MapInfoSheetState extends State<MapInfoSheet> {
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
    final strings = AppLocale.of(context);
    final crowd = widget.crowd;
    return SafeArea(
      child: ConstrainedBox(
        constraints:
            BoxConstraints(maxHeight: MediaQuery.of(context).size.height * .78),
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 18, 20, 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(strings.t('clm_map_information'),
                  style: const TextStyle(
                      fontWeight: FontWeight.w900, fontSize: 18)),
              const SizedBox(height: 14),
              Row(children: [
                const Icon(Icons.circle, size: 10, color: ArucadColors.success),
                const SizedBox(width: 10),
                Text(strings.t('clm_people_on_campus', {'count': crowd.total}),
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
              _CrowdSection(
                crowd: crowd,
                hidden: widget.visibility == CampusVisibility.ghost,
              ),
              const Divider(height: 28),
              Row(children: [
                Expanded(
                  child: Text(strings.t('clm_shuttle_times'),
                      style: const TextStyle(
                          fontWeight: FontWeight.w900, fontSize: 14)),
                ),
                TextButton(
                    onPressed: widget.onOpenShuttle,
                    child: Text(strings.t('clm_all'))),
              ]),
              const SizedBox(height: 4),
              for (final route in _routes) _ShuttleLineRow(route: route),
              const Divider(height: 28),
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading:
                    Icon(widget.visibility.icon, color: ArucadColors.primary),
                title: Text(strings.t('clm_visibility')),
                subtitle: Text(widget.visibility.labelFor(strings)),
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
      return weather.isDay
          ? Icons.wb_sunny_outlined
          : Icons.nightlight_outlined;
    }
    if (weather.code <= 3) return Icons.cloud_outlined;
    if (weather.code <= 48) return Icons.foggy;
    if (weather.code <= 67) return Icons.water_drop_outlined;
    if (weather.code <= 86) return Icons.ac_unit;
    return Icons.thunderstorm_outlined;
  }
}

/// Where the busiest places are right now, and how many people are at
/// each — from real location pings (`GET /presence/live`), not from the
/// few students who deliberately check in.
///
/// An empty list is shown as an empty list. Nobody sharing is a real state
/// of the campus at 3am, and inventing a crowd to fill the panel would
/// make every other number here untrustworthy too.
class _CrowdSection extends StatelessWidget {
  final CampusLiveCrowd crowd;

  /// The reader is in ghost mode, so they are not part of these counts.
  final bool hidden;

  const _CrowdSection({required this.crowd, required this.hidden});

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final busiest = crowd.places.isEmpty ? 1 : crowd.places.first.count;

    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Row(children: [
        Expanded(
          child: Text(strings.t('clm_busiest_now'),
              style:
                  const TextStyle(fontWeight: FontWeight.w900, fontSize: 14)),
        ),
        Text(
          strings.t('clm_crowd_window', {'minutes': crowd.windowMinutes}),
          style: const TextStyle(color: ArucadColors.muted, fontSize: 11),
        ),
      ]),
      const SizedBox(height: 8),
      if (crowd.places.isEmpty)
        Text(strings.t('clm_crowd_empty'),
            style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5))
      else
        for (final place in crowd.places)
          _CrowdRow(place: place, busiest: busiest),
      if (hidden) ...[
        const SizedBox(height: 8),
        Row(children: [
          const Icon(Icons.visibility_off_outlined,
              size: 14, color: ArucadColors.muted),
          const SizedBox(width: 6),
          Expanded(
            child: Text(strings.t('clm_crowd_ghost_hint'),
                style:
                    const TextStyle(color: ArucadColors.muted, fontSize: 11.5)),
          ),
        ]),
      ],
    ]);
  }
}

/// One place in the crowd list: a bar scaled against the busiest place, so
/// the ranking is readable at a glance rather than by comparing numbers.
class _CrowdRow extends StatelessWidget {
  final LivePlaceCrowd place;
  final int busiest;

  const _CrowdRow({required this.place, required this.busiest});

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final fraction =
        busiest <= 0 ? 0.0 : (place.count / busiest).clamp(0.0, 1.0);
    // The same three bands the density legend uses, so a red bar here
    // means what a red glow means on the map itself.
    final color = fraction >= 0.67
        ? ArucadColors.danger
        : fraction >= 0.34
            ? ArucadColors.yellow
            : ArucadColors.campusGreen;

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(
            child: Text(place.name,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style:
                    const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
          ),
          const SizedBox(width: 8),
          Text(strings.t('clm_people_count', {'count': place.count}),
              style: TextStyle(
                  fontWeight: FontWeight.w800, fontSize: 12.5, color: color)),
        ]),
        const SizedBox(height: 4),
        ClipRRect(
          borderRadius: BorderRadius.circular(999),
          child: LinearProgressIndicator(
            value: fraction,
            minHeight: 6,
            backgroundColor: color.withValues(alpha: .15),
            valueColor: AlwaysStoppedAnimation<Color>(color),
          ),
        ),
      ]),
    );
  }
}

/// One shuttle line: colour and ordered stops only. No minute/hour estimate,
/// countdown, or departure timetable is rendered in the map information UI.
class _ShuttleLineRow extends StatelessWidget {
  final ShuttleRoute route;

  const _ShuttleLineRow({required this.route});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Container(
          width: 10,
          height: 10,
          margin: const EdgeInsets.only(top: 5, right: 10),
          decoration: BoxDecoration(color: route.color, shape: BoxShape.circle),
        ),
        Expanded(
          child:
              Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(route.name,
                style: const TextStyle(
                    fontWeight: FontWeight.w800, fontSize: 13.5)),
            const SizedBox(height: 4),
            Text(
              route.stops.join(' → '),
              maxLines: 3,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(
                  color: ArucadColors.muted, fontSize: 11, height: 1.4),
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
  final CampusLiveCrowd crowd;
  final CampusVisibility visibility;
  final ValueChanged<CampusVisibility> onVisibilityChanged;
  final VoidCallback onOpenShuttle;
  final VoidCallback? onOpenFullMap;
  final CampusRepository repository;

  const _MapControlButton({
    required this.crowd,
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
              Text(AppLocale.of(context).t('clm_map_info'),
                  style: const TextStyle(
                      fontWeight: FontWeight.w800, fontSize: 12)),
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
      builder: (sheetContext) => MapInfoSheet(
        crowd: crowd,
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
              title: Text(v.labelFor(AppLocale.of(context))),
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
  final void Function(TravelMode) onNavigate;
  final CampusRepository? repository;
  final VoidCallback? onOpenDirectory;
  final VoidCallback? onDetails;
  final VoidCallback? onRequestAppointment;

  /// People here right now from real location pings (`GET /presence/live`),
  /// when the caller has a current snapshot. Null means "not counted in
  /// this snapshot", which falls back to the check-in count rather than
  /// claiming the place is empty.
  final int? liveCount;

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
    this.liveCount,
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
    // Where people actually are beats who announced themselves: prefer
    // the live ping count, fall back to recent check-ins, and only then
    // to the name-derived estimate for a POI with no place row at all.
    final onlineHere = widget.liveCount ??
        (widget.place != null
            ? campusPresenceCount(widget.place!)
            : campusOnlineCount(widget.poi.name));
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
                      label: AppLocale.of(context)
                          .t('clm_people_here', {'count': onlineHere}),
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
                        style:
                            TextStyle(fontSize: 13, color: ArucadColors.muted)),
                  )
                else
                  for (final c in checkins)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 6),
                      child: Row(children: [
                        const Icon(Icons.photo_camera_back_outlined,
                            size: 15, color: ArucadColors.muted),
                        const SizedBox(width: 6),
                        Expanded(child: _checkinEntryLabel(context, c)),
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
                          Text(
                              AppLocale.of(context).t(eq.available
                                  ? 'clm_available'
                                  : 'clm_unavailable'),
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
                      label: Text(AppLocale.of(context).t('clm_ask_aicad')),
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
                            hintText:
                                AppLocale.of(context).t('clm_collab_hint'),
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
                                child:
                                    CircularProgressIndicator(strokeWidth: 2))
                            : const Icon(Icons.send_outlined),
                      ),
                    ]),
                  ],
                ],
                const SizedBox(height: 6),
                Text(
                    widget.visibility == CampusVisibility.ghost
                        ? AppLocale.of(context).t('clm_ghost_status')
                        : AppLocale.of(context).t('clm_visibility_status', {
                            'visibility': widget.visibility
                                .labelFor(AppLocale.of(context))
                          }),
                    style: const TextStyle(
                        fontSize: 11, color: ArucadColors.muted)),
                const SizedBox(height: 12),
                // How to get there is asked here, not after the route has
                // already been drawn: a student heading for the shuttle
                // should not have to open a walking route first and then
                // switch. Each mode is one tap from the pin.
                Text(AppLocale.of(context).t('clm_how_to_get_there'),
                    style: const TextStyle(
                        fontWeight: FontWeight.w800, fontSize: 12.5)),
                const SizedBox(height: 8),
                Row(children: [
                  for (final mode in TravelMode.values) ...[
                    Expanded(
                      child: Padding(
                        padding: EdgeInsets.only(
                            right: mode == TravelMode.values.last ? 0 : 8),
                        child: mode == TravelMode.walking
                            ? FilledButton.icon(
                                onPressed: () => widget.onNavigate(mode),
                                icon: Icon(mode.icon, size: 18),
                                label: Text(
                                    mode.labelFor(AppLocale.of(context).t),
                                    style: const TextStyle(fontSize: 12)),
                              )
                            : OutlinedButton.icon(
                                onPressed: () => widget.onNavigate(mode),
                                icon: Icon(mode.icon, size: 18),
                                label: Text(
                                    mode.labelFor(AppLocale.of(context).t),
                                    style: const TextStyle(fontSize: 12)),
                              ),
                      ),
                    ),
                  ],
                ]),
                const SizedBox(height: 10),
                Wrap(spacing: 10, runSpacing: 8, children: [
                  if (widget.place != null)
                    OutlinedButton.icon(
                      onPressed: () {
                        final tour = resolvePlaceTour(widget.place!);
                        open360Tour(
                          context,
                          tour.url,
                          tourTarget: tour.target,
                          title: widget.place!.name,
                        );
                      },
                      icon: const Icon(Icons.threesixty_rounded, size: 18),
                      label: Text(AppLocale.of(context).t('explore_tour')),
                    ),
                  if (widget.onOpenDirectory != null)
                    OutlinedButton.icon(
                      onPressed: widget.onOpenDirectory,
                      icon: const Icon(Icons.apartment_outlined, size: 18),
                      label: Text(AppLocale.of(context).t('clm_buildings')),
                    ),
                  if (widget.onDetails != null)
                    OutlinedButton(
                        onPressed: widget.onDetails,
                        child: Text(AppLocale.of(context).t('clm_details'))),
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
  List<DirectoryEntry> _directory = const [];
  late String? _focusPlaceId = widget.focusPlaceId;
  ChatRealtimeService? _realtime;
  StreamSubscription<List<String>>? _campusChanges;
  StreamSubscription<Position>? _positionSub;
  final _mapController = CampusMapController();
  bool _refreshing = false;
  GeoPoint? _userLocation;
  bool _followUserLocation = false;
  MapContentFilter _contentFilter = MapContentFilter.all;

  @override
  void initState() {
    super.initState();
    final cached = LocationService.lastKnown;
    _userLocation = widget.userLocation ??
        (cached == null ? null : GeoPoint(cached.latitude, cached.longitude));
    unawaited(_startRealtime());
    unawaited(_loadDirectory());
    if (_userLocation != null) {
      unawaited(_startLocationTracking());
    } else {
      unawaited(_resolveUserLocation());
    }
  }

  Future<void> _loadDirectory() async {
    try {
      final entries = await widget.repository.getDirectoryEntries();
      if (mounted) setState(() => _directory = entries);
    } catch (_) {
      // Place/event search remains available during a directory outage.
    }
  }

  // Someone who doesn't know a building's name can still find it — a
  // student typing "kütüphane" or "atölye" jumps straight to it instead of
  // having to recognize a pin among 20+ on the map.
  Future<void> _openSearch() async {
    var query = '';
    final selected = await showModalBottomSheet<MapSearchTarget>(
      context: context,
      isScrollControlled: true,
      builder: (sheetContext) {
        return StatefulBuilder(builder: (sheetContext, setSheetState) {
          final matches = mapSearchTargets(
            _places,
            _events,
            query,
            directory: _directory,
          );
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
                        ? Center(
                            child:
                                Text(AppLocale.of(context).t('clm_no_results')))
                        : ListView.builder(
                            itemCount: matches.length,
                            itemBuilder: (context, i) => ListTile(
                              leading: const Icon(Icons.location_on_outlined),
                              title: Text(matches[i].title),
                              subtitle: matches[i].subtitle.isEmpty
                                  ? null
                                  : Text(matches[i].subtitle),
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
      GeoPoint? display;
      for (final pin in spreadOverlappingMapPins(campusMapPlaces(_places))) {
        if (pin.place.id == selected.place.id) {
          display = pin.display;
          break;
        }
      }
      setState(() {
        _followUserLocation = false;
        _contentFilter = selected.event == null
            ? MapContentFilter.places
            : MapContentFilter.events;
        _focusPlaceId = selected.place.id;
      });
      // Imperative camera movement makes repeated selection of the same
      // result work too; relying only on a changed widget value meant the
      // second selection was ignored because the id had not changed.
      await _mapController.centerOn(
        display ?? GeoPoint(selected.place.lat, selected.place.lng),
        zoom: 18.2,
      );
      if (selected.directory != null && mounted) {
        await _showDirectoryTarget(selected);
      }
    }
  }

  Future<void> _showDirectoryTarget(MapSearchTarget target) async {
    final entry = target.directory!;
    final tourUrl = entry.tourUrl ?? entry.splatSceneUrl;
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (sheetContext) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Container(
              width: 42,
              height: 4,
              decoration: BoxDecoration(
                color: Theme.of(sheetContext).colorScheme.outlineVariant,
                borderRadius: BorderRadius.circular(99),
              ),
            ),
            const SizedBox(height: 18),
            Align(
              alignment: Alignment.centerLeft,
              child: Text(target.title,
                  style: Theme.of(sheetContext).textTheme.titleLarge?.copyWith(
                        fontWeight: FontWeight.w900,
                      )),
            ),
            const SizedBox(height: 6),
            Align(
              alignment: Alignment.centerLeft,
              child: Text(target.subtitle,
                  style: TextStyle(
                      color:
                          Theme.of(sheetContext).colorScheme.onSurfaceVariant)),
            ),
            const SizedBox(height: 10),
            const Align(
              alignment: Alignment.centerLeft,
              child: Text(
                'Harita rotası doğrulanmış bina girişine gider. 360° düğmesi seçtiğiniz oda veya hizmet noktasını açar.',
                style: TextStyle(color: ArucadColors.muted, fontSize: 12),
              ),
            ),
            const SizedBox(height: 18),
            Row(children: [
              Expanded(
                child: FilledButton.icon(
                  icon: const Icon(Icons.directions),
                  label: const Text('Bina girişine rota'),
                  onPressed: () {
                    Navigator.of(sheetContext).pop();
                    Navigator.of(context).push(MaterialPageRoute(
                      builder: (_) => InAppNavigationScreen(
                        destinationName: target.title,
                        destination:
                            GeoPoint(target.place.lat, target.place.lng),
                        repository: widget.repository,
                      ),
                    ));
                  },
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: OutlinedButton.icon(
                  icon: const Icon(Icons.threesixty),
                  label: const Text('Odayı 360° aç'),
                  onPressed: tourUrl == null
                      ? null
                      : () => open360Tour(
                            sheetContext,
                            tourUrl,
                            tourTarget: entry.tourTarget,
                            title: target.title,
                          ),
                ),
              ),
            ]),
          ]),
        ),
      ),
    );
  }

  Future<void> _resolveUserLocation({bool requestAgain = false}) async {
    try {
      final position = await const LocationService().getCurrentPosition(
        requestAgain: requestAgain,
        allowOutsideCampus: true,
        settings: const LocationSettings(accuracy: LocationAccuracy.high),
      );
      if (position == null || !mounted) {
        if (requestAgain && mounted) {
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
            content: Text(AppLocale.of(context).t('clm_location_unavailable')),
          ));
        }
        return;
      }
      setState(() {
        _focusPlaceId = null;
        // Opening the map shows the whole campus heat field. Only the
        // explicit location button opts into the close, live-follow camera.
        _followUserLocation = requestAgain;
        _userLocation = GeoPoint(position.latitude, position.longitude);
      });
      if (requestAgain) {
        await _mapController.centerOn(_userLocation!, zoom: 18.2);
      }
      await _startLocationTracking();
    } catch (_) {
      // The map remains usable without an OS location permission.
    }
  }

  Future<void> _startLocationTracking() async {
    await _positionSub?.cancel();
    _positionSub = const LocationService()
        .positionStream(
      allowOutsideCampus: true,
      settings: const LocationSettings(
        accuracy: LocationAccuracy.high,
        distanceFilter: 2,
      ),
    )
        .listen((live) {
      if (!mounted) return;
      setState(() {
        _focusPlaceId = null;
        _userLocation = GeoPoint(live.latitude, live.longitude);
      });
    });
  }

  Future<void> _startRealtime() async {
    try {
      final me = await widget.repository.getMe();
      if (!mounted) return;
      final realtime = ChatRealtimeService.forRepository(widget.repository);
      _realtime = realtime;
      _campusChanges = realtime.campusChanged.listen((resources) {
        if (resources.contains('events') ||
            resources.contains('places') ||
            resources.contains('directory')) {
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
      final wantsDirectory = resources.contains('directory');
      final values = await Future.wait([
        if (wantsPlaces) widget.repository.getPlaces(),
        if (wantsEvents) widget.repository.getEvents(),
        if (wantsDirectory) widget.repository.getDirectoryEntries(),
      ]);
      if (!mounted) return;
      var offset = 0;
      setState(() {
        if (wantsPlaces) _places = values[offset++] as List<CampusPlace>;
        if (wantsEvents) _events = values[offset++] as List<CampusEvent>;
        if (wantsDirectory) {
          _directory = values[offset++] as List<DirectoryEntry>;
        }
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
    unawaited(_positionSub?.cancel() ?? Future.value());
    unawaited(_realtime?.dispose() ?? Future.value());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    return Scaffold(
      extendBodyBehindAppBar: true,
      body: Stack(children: [
        Positioned.fill(
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
            followUserLocation: _followUserLocation,
            contentFilter: _contentFilter,
            controller: _mapController,
          ),
        ),
        Positioned(
          top: MediaQuery.paddingOf(context).top + 10,
          left: 12,
          right: 12,
          child: Column(children: [
            Row(children: [
              _FullMapCircleButton(
                icon: Icons.arrow_back,
                tooltip: MaterialLocalizations.of(context).backButtonTooltip,
                onTap: () => Navigator.of(context).pop(),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Material(
                  color: Theme.of(context)
                      .colorScheme
                      .surface
                      .withValues(alpha: .97),
                  elevation: 5,
                  borderRadius: BorderRadius.circular(18),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(18),
                    onTap: _openSearch,
                    child: Padding(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 14, vertical: 13),
                      child: Row(children: [
                        const Icon(Icons.search, size: 21),
                        const SizedBox(width: 9),
                        Expanded(
                          child: Text(strings.t('clm_search_everything'),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: TextStyle(
                                  color: Theme.of(context)
                                      .colorScheme
                                      .onSurfaceVariant,
                                  fontWeight: FontWeight.w600)),
                        ),
                      ]),
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 10),
              _FullMapCircleButton(
                icon: Icons.my_location,
                tooltip: strings.t('clm_show_my_location'),
                onTap: () => _resolveUserLocation(requestAgain: true),
              ),
            ]),
            const SizedBox(height: 9),
            SizedBox(
              height: 38,
              child: ListView(
                scrollDirection: Axis.horizontal,
                children: [
                  _MapFilterChip(
                    icon: Icons.people_alt_outlined,
                    label: strings.t('clm_people'),
                    selected: _contentFilter == MapContentFilter.people,
                    onTap: () => setState(() => _contentFilter =
                        _contentFilter == MapContentFilter.people
                            ? MapContentFilter.all
                            : MapContentFilter.people),
                  ),
                  _MapFilterChip(
                    icon: Icons.event_outlined,
                    label: strings.t('clm_events'),
                    selected: _contentFilter == MapContentFilter.events,
                    onTap: () => setState(() => _contentFilter =
                        _contentFilter == MapContentFilter.events
                            ? MapContentFilter.all
                            : MapContentFilter.events),
                  ),
                  _MapFilterChip(
                    icon: Icons.place_outlined,
                    label: strings.t('clm_places'),
                    selected: _contentFilter == MapContentFilter.places,
                    onTap: () => setState(() => _contentFilter =
                        _contentFilter == MapContentFilter.places
                            ? MapContentFilter.all
                            : MapContentFilter.places),
                  ),
                  _MapFilterChip(
                    icon: Icons.local_fire_department_outlined,
                    label: strings.t('clm_popular'),
                    selected: _contentFilter == MapContentFilter.popular,
                    onTap: () => setState(() => _contentFilter =
                        _contentFilter == MapContentFilter.popular
                            ? MapContentFilter.all
                            : MapContentFilter.popular),
                  ),
                ],
              ),
            ),
          ]),
        ),
      ]),
    );
  }
}

class _FullMapCircleButton extends StatelessWidget {
  final IconData icon;
  final String tooltip;
  final VoidCallback onTap;

  const _FullMapCircleButton(
      {required this.icon, required this.tooltip, required this.onTap});

  @override
  Widget build(BuildContext context) => MapPointerGuard(
        child: Material(
          color: Theme.of(context).colorScheme.surface.withValues(alpha: .97),
          elevation: 5,
          shape: const CircleBorder(),
          child:
              IconButton(icon: Icon(icon), tooltip: tooltip, onPressed: onTap),
        ),
      );
}

class _MapFilterChip extends StatelessWidget {
  final IconData icon;
  final String label;
  final bool selected;
  final VoidCallback onTap;

  const _MapFilterChip({
    required this.icon,
    required this.label,
    required this.selected,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(right: 8),
        child: Material(
          color: selected
              ? ArucadColors.primary
              : Theme.of(context).colorScheme.surface.withValues(alpha: .96),
          elevation: 3,
          borderRadius: BorderRadius.circular(999),
          child: InkWell(
            borderRadius: BorderRadius.circular(999),
            onTap: onTap,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              child: Row(children: [
                Icon(icon,
                    size: 16,
                    color: selected ? Colors.white : ArucadColors.primary),
                const SizedBox(width: 6),
                Text(label,
                    style: TextStyle(
                        color: selected ? Colors.white : null,
                        fontSize: 12,
                        fontWeight: FontWeight.w800)),
              ]),
            ),
          ),
        ),
      );
}
