import 'dart:async';

import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';

import 'package:arucad_campus_prototype/core/config/place_catalog.dart';
import 'package:arucad_campus_prototype/core/config/place_tour.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/directions_result.dart';
import 'package:arucad_campus_prototype/core/services/location_service.dart';
import 'package:arucad_campus_prototype/core/services/mock_analytics_tracker.dart';
import 'package:arucad_campus_prototype/core/services/tour_launcher.dart';
import 'package:arucad_campus_prototype/core/services/url_launcher_map_provider.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/guide/ask_arucad_bubble.dart';
import 'package:arucad_campus_prototype/features/guide/guide_sheet.dart';
import 'package:arucad_campus_prototype/features/map/map_pointer_guard.dart';
import 'package:arucad_campus_prototype/features/map/maplibre_campus_map.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

const _campusEntrance = GeoPoint(35.337395, 33.321358);

/// In-app campus navigation. Every mode requests real road/path geometry
/// from the backend's OSRM-compatible provider; a straight line only shows
/// as an honest fallback when that provider is unreachable.
class InAppNavigationScreen extends StatefulWidget {
  final String destinationName;
  final GeoPoint destination;
  final CampusRepository? repository;
  final MapProvider? mapProvider;
  final AnalyticsTracker? analyticsTracker;

  const InAppNavigationScreen({
    super.key,
    required this.destinationName,
    required this.destination,
    this.repository,
    this.mapProvider,
    this.analyticsTracker,
  });

  @override
  State<InAppNavigationScreen> createState() => _InAppNavigationScreenState();
}

class _InAppNavigationScreenState extends State<InAppNavigationScreen> {
  final _mapController = CampusMapController();
  GeoPoint? _origin;
  List<GeoPoint> _extentPoints = const [];
  List<CampusPlace> _places = const [];
  GeoPoint? _overrideDestination;
  String? _overrideName;
  RouteResult? _route;
  TravelMode _mode = TravelMode.walking;
  bool _loading = true;
  bool _usedFallbackOrigin = false;
  StreamSubscription<Position>? _positionSub;

  GeoPoint get _destination => _overrideDestination ?? widget.destination;
  String get _destinationName => _overrideName ?? widget.destinationName;

  @override
  void initState() {
    super.initState();
    _extentPoints = [widget.destination];
    _places = campusMapPlaces(const []);
    _loadPlaces();
    _start();
  }

  Future<void> _loadPlaces() async {
    var fromApi = const <CampusPlace>[];
    try {
      if (widget.repository != null) {
        fromApi = await widget.repository!.getPlaces();
      }
    } catch (_) {}
    if (!mounted) return;
    setState(() => _places = campusMapPlaces(fromApi));
  }

  @override
  void dispose() {
    _positionSub?.cancel();
    super.dispose();
  }

  void _startLiveTracking() {
    _positionSub?.cancel();
    _positionSub = const LocationService()
        .positionStream(
            settings: const LocationSettings(
                accuracy: LocationAccuracy.high, distanceFilter: 5))
        .listen((position) {
      if (!mounted) return;
      final here = GeoPoint(position.latitude, position.longitude);
      _applyRoute(here, live: true);
    }, onError: (_) {});
  }

  RouteResult _estimateRoute(GeoPoint origin, GeoPoint destination) {
    final strings = AppLocale.of(context);
    final meters = Geolocator.distanceBetween(
        origin.lat, origin.lng, destination.lat, destination.lng);
    final minutes = (meters / _mode.fallbackMetersPerMinute).ceil();
    final distance = meters >= 1000
        ? '${(meters / 1000).toStringAsFixed(1)} km'
        : '${meters.round()} m';
    return RouteResult(
      points: [origin, destination],
      distanceText: distance,
      durationText: '~$minutes ${strings.t('nav_min')}',
    );
  }

  RouteResult _routeFromRoad(WalkingRoute walking) {
    final strings = AppLocale.of(context);
    final meters = walking.distanceMeters;
    // Pace by selected mode even when geometry is road-network based.
    final minutes = (meters / _mode.fallbackMetersPerMinute).ceil().clamp(1, 9999);
    final distance = meters >= 1000
        ? '${(meters / 1000).toStringAsFixed(1)} km'
        : '${meters.round()} m';
    return RouteResult(
      points: walking.points,
      distanceText: distance,
      durationText: '~$minutes ${strings.t('nav_min')}',
      steps: walking.steps,
      fromProvider: true,
    );
  }

  Future<void> _applyRoute(GeoPoint origin, {bool live = false}) async {
    if (live) {
      if (!mounted) return;
      final strings = AppLocale.of(context);
      final meters = Geolocator.distanceBetween(
          origin.lat, origin.lng, _destination.lat, _destination.lng);
      final minutes =
          (meters / _mode.fallbackMetersPerMinute).ceil().clamp(1, 9999);
      final distance = meters >= 1000
          ? '${(meters / 1000).toStringAsFixed(1)} km'
          : '${meters.round()} m';
      setState(() {
        _origin = origin;
        if (_route != null) {
          _route = RouteResult(
            points: _route!.points,
            distanceText: distance,
            durationText: '~$minutes ${strings.t('nav_min')}',
            steps: _route!.steps,
            fromProvider: _route!.fromProvider,
          );
        }
      });
      return;
    }

    // Real fix: walking is the mode almost every student actually uses on
    // a small campus, and the backend's OSRM/FOSSGIS provider is pedestrian
    // geometry first — it was walking that stayed on the honest-fallback
    // straight line while car/bus (rarer here) got the real path. All
    // three modes now request the real route; a straight line only shows
    // when the provider itself is unavailable (see the catch below).
    RouteResult route = _estimateRoute(origin, _destination);
    final repo = widget.repository;
    if (repo != null) {
      try {
        final road = await repo.getWalkingRoute(
          fromLat: origin.lat,
          fromLng: origin.lng,
          toLat: _destination.lat,
          toLng: _destination.lng,
        );
        if (road != null && road.points.length >= 2) {
          route = _routeFromRoad(road);
        }
      } catch (_) {}
    }

    if (!mounted) return;
    setState(() {
      _origin = origin;
      _route = route;
      _extentPoints =
          route.points.length >= 2 ? route.points : [origin, _destination];
      _loading = false;
    });
    if (route.points.length >= 2) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _mapController.fitPoints(route.points, padding: 72);
      });
    }
  }

  Future<void> _start() async {
    setState(() {
      _loading = true;
      _route = null;
    });
    final origin = _origin ?? await _resolveOrigin();
    if (!mounted) return;
    await _applyRoute(origin);
    _startLiveTracking();
  }

  Future<void> _changeMode(TravelMode mode) async {
    if (mode == _mode) return;
    setState(() => _mode = mode);
    if (_origin != null) {
      await _applyRoute(_origin!);
    } else {
      await _start();
    }
  }

  Future<GeoPoint> _resolveOrigin() async {
    try {
      final position = await const LocationService().getCurrentPosition(
          settings: const LocationSettings(accuracy: LocationAccuracy.high));
      if (position == null) {
        _usedFallbackOrigin = true;
        return _campusEntrance;
      }
      return GeoPoint(position.latitude, position.longitude);
    } catch (_) {
      _usedFallbackOrigin = true;
      return _campusEntrance;
    }
  }

  void _openPoi(CampusPlace place, GeoPoint display) {
    final strings = AppLocale.of(context);
    final scheme = Theme.of(context).colorScheme;
    _mapController.centerOn(display, zoom: 18.2);
    showModalBottomSheet<void>(
      context: context,
      backgroundColor: scheme.surface,
      builder: (_) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 18, 20, 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(place.name,
                  style: TextStyle(
                      fontWeight: FontWeight.w900,
                      fontSize: 20,
                      color: scheme.onSurface)),
              const SizedBox(height: 4),
              Text(normalizeCategory(place.category),
                  style: TextStyle(
                      color: scheme.onSurfaceVariant,
                      fontWeight: FontWeight.w700)),
              if (place.description.trim().isNotEmpty) ...[
                const SizedBox(height: 10),
                Text(
                    place.description
                        .replaceAll(
                            RegExp(r'\s*\(legacy id:[^)]*\)',
                                caseSensitive: false),
                            '')
                        .trim(),
                    style: TextStyle(
                        fontSize: 14, height: 1.45, color: scheme.onSurface)),
              ],
              const SizedBox(height: 16),
              Row(children: [
                Expanded(
                  child: FilledButton.icon(
                    onPressed: () {
                      Navigator.of(context).pop();
                      setState(() {
                        _overrideDestination = GeoPoint(place.lat, place.lng);
                        _overrideName = place.name;
                      });
                      _start();
                    },
                    icon: const Icon(Icons.directions_walk),
                    label: Text(strings.t('nav_start')),
                  ),
                ),
                const SizedBox(width: 10),
                OutlinedButton.icon(
                  onPressed: () {
                    // Real fix: navigating and wanting "what does this place
                    // actually look like" used to be two disconnected flows
                    // — this opens THIS place's own resolved 360 scene
                    // directly, the same way the main map's info sheet does.
                    Navigator.of(context).pop();
                    final tour = resolvePlaceTour(place);
                    open360Tour(context, tour.url,
                        tourTarget: tour.target, title: place.name);
                  },
                  icon: const Icon(Icons.threed_rotation, size: 18),
                  label: const Text('360°'),
                ),
              ]),
            ],
          ),
        ),
      ),
    );
  }

  List<CampusMapMarker> _markers() {
    final strings = AppLocale.of(context);
    final destName = _destinationName.toLowerCase();
    final markers = <CampusMapMarker>[];
    for (final pin in spreadOverlappingMapPins(_places)) {
      final isDest = pin.place.name.toLowerCase() == destName;
      if (isDest) continue;
      markers.add(CampusMapMarker(
        id: 'place-${pin.place.id}',
        position: pin.display,
        label: pin.place.name,
        color: campusDensityInfo(pin.place).$1,
        onTap: () => _openPoi(pin.place, pin.display),
      ));
    }
    markers.add(CampusMapMarker(
      id: 'destination',
      position: _destination,
      label: _destinationName,
      color: ArucadColors.primary,
      emphasized: true,
    ));
    if (_origin != null && _usedFallbackOrigin) {
      markers.add(CampusMapMarker(
        id: 'origin',
        position: _origin!,
        label: strings.t('nav_campus_entrance'),
        color: ArucadColors.blue,
      ));
    }
    return markers;
  }

  void _openAskArucad() {
    final strings = AppLocale.of(context);
    final repository = widget.repository;
    if (repository == null) {
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(strings.t('nav_ask_needs_session'))));
      return;
    }
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (_) => GuideSheet(
        repository: repository,
        mapProvider: widget.mapProvider ?? const UrlLauncherMapProvider(),
        analyticsTracker: widget.analyticsTracker ?? MockAnalyticsTracker(),
      ),
    );
  }

  void _recenter() {
    if (_origin != null && !_usedFallbackOrigin) {
      _mapController.centerOn(_origin!, zoom: 18);
    } else {
      _mapController.fitPoints(_extentPoints);
    }
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final scheme = Theme.of(context).colorScheme;
    final onRoad = _route?.fromProvider == true;
    return Scaffold(
      appBar: AppBar(title: Text(_destinationName)),
      body: Stack(children: [
        CampusMapView(
          extentPoints: _extentPoints,
          controller: _mapController,
          userLocation: _origin,
          showUserLocation: !_usedFallbackOrigin,
          routePoints: _route?.points,
          // Solid = real road/path geometry from the provider; dashed only
          // shows when that provider was unavailable for this request.
          routeDashed: !onRoad,
          routeColor: ArucadColors.blue,
          markers: _markers(),
        ),
        Positioned(
          top: 12,
          left: 12,
          right: 12,
          child: _ModeSelector(
            mode: _mode,
            onChanged: _changeMode,
            labelFor: (m) => m.labelFor(strings.t),
          ),
        ),
        Positioned(
          right: 14,
          top: 82,
          child: AskArucadBubble(onTap: _openAskArucad),
        ),
        Positioned(
          right: 14,
          bottom: _route == null ? 14 : 92,
          child: _RecenterButton(onTap: _recenter),
        ),
        if (_loading)
          Positioned(
            top: 62,
            left: 16,
            right: 16,
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Row(children: [
                  SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(
                          strokeWidth: 2, color: scheme.primary)),
                  const SizedBox(width: 12),
                  Expanded(
                      child: Text(strings
                          .t('nav_calculating')
                          .replaceAll('{mode}', _mode.labelFor(strings.t)))),
                ]),
              ),
            ),
          ),
        if (_usedFallbackOrigin && !_loading)
          Positioned(
            top: 62,
            left: 16,
            right: 16,
            child: Card(
              color: scheme.surfaceContainerHighest,
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Row(children: [
                  Icon(Icons.info_outline,
                      size: 18, color: scheme.onSurfaceVariant),
                  const SizedBox(width: 8),
                  Expanded(
                      child: Text(strings.t('nav_fallback_origin'),
                          style: TextStyle(
                              fontSize: 12, color: scheme.onSurfaceVariant))),
                ]),
              ),
            ),
          ),
      ]),
      bottomNavigationBar: _route == null
          ? null
          : SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 10, 16, 12),
                child: Row(
                  children: [
                    Icon(_mode.icon, color: ArucadColors.blue),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        '${_route!.durationText} · ${_route!.distanceText}',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                            fontWeight: FontWeight.w900,
                            fontSize: 16,
                            color: scheme.onSurface),
                      ),
                    ),
                    const SizedBox(width: 8),
                    FilledButton.icon(
                        onPressed: () => Navigator.of(context).pop(),
                        icon: const Icon(Icons.close, size: 18),
                        label: Text(strings.t('nav_finish'))),
                  ],
                ),
              ),
            ),
    );
  }
}

class _ModeSelector extends StatelessWidget {
  final TravelMode mode;
  final ValueChanged<TravelMode> onChanged;
  final String Function(TravelMode) labelFor;
  const _ModeSelector({
    required this.mode,
    required this.onChanged,
    required this.labelFor,
  });

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return MapPointerGuard(
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 6),
        decoration: BoxDecoration(
          color: scheme.surface,
          borderRadius: BorderRadius.circular(ArucadRadius.pill),
          boxShadow: [
            BoxShadow(
                color: Colors.black.withValues(alpha: .12), blurRadius: 10),
          ],
        ),
        child: Row(
          children: [
            for (final m in TravelMode.values)
              Expanded(
                child: GestureDetector(
                  onTap: () => onChanged(m),
                  child: Container(
                    margin: const EdgeInsets.symmetric(horizontal: 2),
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    decoration: BoxDecoration(
                      color: m == mode ? ArucadColors.primary : null,
                      borderRadius: BorderRadius.circular(ArucadRadius.pill),
                    ),
                    child: Column(mainAxisSize: MainAxisSize.min, children: [
                      Icon(m.icon,
                          size: 18,
                          color: m == mode
                              ? Colors.white
                              : scheme.onSurfaceVariant),
                      const SizedBox(height: 2),
                      Text(labelFor(m),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                              fontSize: 9.5,
                              fontWeight: FontWeight.w700,
                              color: m == mode
                                  ? Colors.white
                                  : scheme.onSurfaceVariant)),
                    ]),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _RecenterButton extends StatelessWidget {
  final VoidCallback onTap;
  const _RecenterButton({required this.onTap});

  @override
  Widget build(BuildContext context) => MapPointerGuard(
        child: Material(
          color: Theme.of(context).colorScheme.surface,
          shape: const CircleBorder(),
          elevation: 3,
          child: InkWell(
            customBorder: const CircleBorder(),
            onTap: onTap,
            child: const Padding(
              padding: EdgeInsets.all(10),
              child: Icon(Icons.my_location,
                  color: ArucadColors.primary, size: 20),
            ),
          ),
        ),
      );
}
