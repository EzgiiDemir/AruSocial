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
import 'package:arucad_campus_prototype/core/services/tour_launcher.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/map/map_pointer_guard.dart';
import 'package:arucad_campus_prototype/features/map/maplibre_campus_map.dart';
import 'package:arucad_campus_prototype/features/map/navigation_engine.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

const _campusEntrance = GeoPoint(35.337395, 33.321358);

/// In-app campus navigation. Walking is intentionally a flat bird's-eye
/// bearing to the destination; car and bus are drawn only from real road
/// geometry returned by the backend's OSRM-compatible provider.
class InAppNavigationScreen extends StatefulWidget {
  final String destinationName;
  final GeoPoint destination;
  final CampusRepository? repository;
  final MapProvider? mapProvider;
  final AnalyticsTracker? analyticsTracker;

  /// How the student said they were travelling when they started the route.
  /// Walking is the default on a campus this size, but the choice is made
  /// on the map before this screen opens, so it has to arrive with it —
  /// otherwise picking "bus" would still open a walking route first.
  final TravelMode initialMode;

  const InAppNavigationScreen({
    super.key,
    required this.destinationName,
    required this.destination,
    this.repository,
    this.mapProvider,
    this.analyticsTracker,
    this.initialMode = TravelMode.walking,
  });

  @override
  State<InAppNavigationScreen> createState() => _InAppNavigationScreenState();
}

class _InAppNavigationScreenState extends State<InAppNavigationScreen> {
  final _mapController = CampusMapController();
  final _navigationEngine = NavigationEngine();
  GeoPoint? _origin;
  List<GeoPoint> _extentPoints = const [];
  List<CampusPlace> _places = const [];
  GeoPoint? _overrideDestination;
  String? _overrideName;
  RouteResult? _route;
  late TravelMode _mode = widget.initialMode;
  bool _loading = true;
  bool _usedFallbackOrigin = false;
  StreamSubscription<Position>? _positionSub;
  int _routeRequest = 0;

  /// How far ahead along the route the navigation camera aims. Short
  /// enough to react to the next turn, long enough that a cluster of
  /// geometry points does not make the camera swing.
  static const _bearingLookaheadMeters = 25.0;

  /// Keep the camera locked over the student while walking.
  ///
  /// Turned off the moment they recentre on the whole route instead, so
  /// looking ahead at the rest of the walk is not fought by the next GPS
  /// fix snapping the camera back.
  bool _followMe = false;
  bool _navigationStarted = false;

  /// Whether the turn list is expanded. Car and bus are route previews
  /// rather than live-followed navigation — the instructions are the
  /// whole answer there, so they start open; walking starts collapsed
  /// because the followed map is already showing the student what to do.
  bool _showSteps = false;

  /// A provider request is in flight behind the straight-line estimate
  /// already on screen. Without this the directions panel announced "no
  /// turn-by-turn for this route" for the second before the real geometry
  /// landed, on every route that in fact had turns.
  bool _routePending = false;
  CampusUser? _me;
  bool _routeUnavailable = false;
  bool _muted = false;
  bool _matchInFlight = false;
  DateTime? _lastMatchAt;
  GeoPoint? _vehicleRouteOrigin;
  DateTime? _lastVehicleRouteAt;

  GeoPoint get _destination => _overrideDestination ?? widget.destination;
  String get _destinationName => _overrideName ?? widget.destinationName;

  @override
  void initState() {
    super.initState();
    _extentPoints = [widget.destination];
    _places = campusMapPlaces(const []);
    _loadPlaces();
    _loadIdentity();
    _start();
  }

  Future<void> _loadIdentity() async {
    try {
      final me = await widget.repository?.getMe();
      if (mounted && me != null) setState(() => _me = me);
    } catch (_) {
      // The location marker falls back to the user's initial.
    }
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
    // Every mode follows now.
    //
    // Car and bus used to be static route previews, which meant a vehicle
    // route was a line seen from directly above — the same overhead look
    // as walking, only zoomed out further. Driving wants the opposite: the
    // camera down at road level, turned the way you are travelling. So all
    // three modes track; what differs is the CAMERA (see _followCamera).
    _positionSub?.cancel();
    _positionSub = const LocationService()
        .positionStream(
            settings: const LocationSettings(
                accuracy: LocationAccuracy.high, distanceFilter: 5))
        .listen((position) {
      if (!mounted) return;
      final filtered = _navigationEngine.accept(position);
      if (filtered == null) return;
      final here = filtered.point;
      _applyRoute(here, live: true);
      final matchDue = _lastMatchAt == null ||
          DateTime.now().difference(_lastMatchAt!) >
              const Duration(milliseconds: 1400);
      if (_navigationEngine.samples.length >= 3 &&
          matchDue &&
          !_matchInFlight) {
        unawaited(_matchCurrentTrace());
      }
      if (_route?.fromProvider == true &&
          _navigationEngine.shouldReroute(here) &&
          !_routePending) {
        unawaited(_requestRoadRoute(here, fit: false));
      }
    }, onError: (_) {});
  }

  Future<void> _matchCurrentTrace() async {
    final repository = widget.repository;
    if (repository == null || _matchInFlight) return;
    _matchInFlight = true;
    _lastMatchAt = DateTime.now();
    try {
      final matcher = OsrmRouteMatcher((samples, mode) async {
        final json = await repository.matchRouteTrace(
          samples: samples.map((sample) => sample.toJson()).toList(),
          mode: mode,
        );
        return json == null ? null : MatchedLocation.fromJson(json);
      });
      final matched = await matcher.match(_navigationEngine.samples, _mode);
      if (!mounted || matched == null || matched.confidence < 0.35) return;
      await _applyRoute(matched.point, live: true);
    } catch (_) {
      // Matching is an enhancement. Filtered GPS remains a safe fallback.
    } finally {
      _matchInFlight = false;
    }
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
      distanceMeters: meters,
      durationSeconds: minutes * 60,
    );
  }

  RouteResult _routeFromRoad(WalkingRoute walking) {
    final strings = AppLocale.of(context);
    final meters = walking.distanceMeters;
    final minutes = (walking.durationSeconds / 60).ceil().clamp(1, 9999);
    final distance = meters >= 1000
        ? '${(meters / 1000).toStringAsFixed(1)} km'
        : '${meters.round()} m';
    return RouteResult(
      points: walking.points,
      distanceText: distance,
      // Shuttle mode is a route/map aid, not a timetable. Traffic, stops and
      // the live shuttle schedule are not present in OSRM, so displaying a
      // made-up arrival estimate would be misleading.
      durationText: _mode == TravelMode.transit
          ? ''
          : '~$minutes ${strings.t('nav_min')}',
      distanceMeters: walking.distanceMeters,
      durationSeconds: walking.durationSeconds,
      steps: walking.steps,
      fromProvider: true,
    );
  }

  Future<void> _applyRoute(GeoPoint origin, {bool live = false}) async {
    if (live) {
      if (!mounted) return;
      if (_mode.usesBirdsEyeLine) {
        _show(origin, _estimateRoute(origin, _destination));
      } else {
        setState(() => _origin = origin);
        final previous = _vehicleRouteOrigin;
        final moved = previous == null
            ? double.infinity
            : Geolocator.distanceBetween(
                previous.lat, previous.lng, origin.lat, origin.lng);
        final oldEnough = _lastVehicleRouteAt == null ||
            DateTime.now().difference(_lastVehicleRouteAt!) >
                const Duration(seconds: 12);
        if (moved >= 25 && oldEnough && !_routePending) {
          unawaited(_requestRoadRoute(origin, fit: false));
        }
      }
      if (_followMe) _followCamera(origin);
      return;
    }

    if (_mode.usesBirdsEyeLine) {
      _routeRequest++;
      if (mounted) {
        setState(() {
          _routePending = false;
          _routeUnavailable = false;
        });
      }
      _show(origin, _estimateRoute(origin, _destination), fit: true);
      return;
    }

    await _requestRoadRoute(origin, fit: true);
  }

  Future<void> _requestRoadRoute(GeoPoint origin, {required bool fit}) async {
    if (!mounted) return;
    setState(() {
      _origin = origin;
      _routeUnavailable = false;
      _loading = _route == null;
      _extentPoints = [origin, _destination];
    });

    final repo = widget.repository;
    if (repo == null) {
      setState(() {
        _loading = false;
        _routeUnavailable = true;
      });
      return;
    }

    // Tag the request so a slow reply for a route the user has already
    // moved on from (mode switch, destination change) can't overwrite a
    // newer one when it eventually arrives.
    final token = ++_routeRequest;
    if (mounted) setState(() => _routePending = true);
    try {
      final road = await repo
          .getWalkingRoute(
            fromLat: origin.lat,
            fromLng: origin.lng,
            toLat: _destination.lat,
            toLng: _destination.lng,
            mode: _mode,
          )
          // The provider is a best-effort public service. Past a few
          // seconds the straight line already on screen is the better
          // answer than a still-spinning one.
          .timeout(const Duration(seconds: 6));
      if (!mounted || token != _routeRequest) return;
      if (road != null && road.points.length >= 2) {
        _vehicleRouteOrigin = origin;
        _lastVehicleRouteAt = DateTime.now();
        _show(origin, _routeFromRoad(road), fit: fit);
      } else {
        setState(() {
          _loading = false;
          _routeUnavailable = true;
        });
      }
    } catch (_) {
      if (mounted && token == _routeRequest) {
        setState(() {
          _loading = false;
          _routeUnavailable = true;
        });
      }
    } finally {
      if (mounted && token == _routeRequest) {
        setState(() => _routePending = false);
      }
    }
  }

  /// Commit a route to the map. Used for both the instant estimate and the
  /// real geometry that replaces it, so they cannot drift apart.
  void _show(GeoPoint origin, RouteResult route, {bool fit = false}) {
    if (!mounted) return;
    _navigationEngine.setRoute(route.points);
    setState(() {
      _origin = origin;
      _route = route;
      _extentPoints =
          route.points.length >= 2 ? route.points : [origin, _destination];
      _loading = false;
    });
    if (fit && route.points.length >= 2) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _mapController.fitPoints(route.points, padding: 72);
      });
    }
  }

  Future<void> _start() async {
    setState(() {
      _loading = true;
      _route = null;
      _routeUnavailable = false;
    });

    // Start from the fix the app already has. Home and Explore track
    // location live, so this is nearly always populated by the time
    // anyone opens navigation, and it turns a multi-second GPS wait into
    // an immediate first paint.
    var origin = _origin ??
        await const LocationService().lastKnownPosition().then(
              (p) => p == null ? null : GeoPoint(p.latitude, p.longitude),
            );

    if (origin != null) {
      unawaited(_applyRoute(origin));
      // A stale-but-close starting point is fine to draw from, but the
      // route is still refined once a current fix arrives.
      if (!LocationService.lastKnownIsFresh) {
        unawaited(_refineOrigin());
      }
    } else {
      // Genuinely no location on record — this is the only path that has
      // to wait, and it falls back to the campus entrance if it fails.
      origin = await _resolveOrigin();
      if (!mounted) return;
      await _applyRoute(origin);
    }

    _startLiveTracking();
  }

  /// Re-runs the route once a current fix replaces a stale cached one.
  Future<void> _refineOrigin() async {
    final position =
        await const LocationService().getCurrentPositionIfGranted();
    if (!mounted || position == null) return;
    final fresh = GeoPoint(position.latitude, position.longitude);
    final drift = Geolocator.distanceBetween(_origin?.lat ?? fresh.lat,
        _origin?.lng ?? fresh.lng, fresh.lat, fresh.lng);
    // Only worth redrawing if the cached guess was actually off.
    if (drift > 25) await _applyRoute(fresh);
  }

  Future<void> _changeMode(TravelMode mode) async {
    if (mode == _mode) return;
    await _positionSub?.cancel();
    _positionSub = null;
    setState(() {
      _mode = mode;
      // Every mode follows; the camera is what changes. The turn list
      // still opens by default for vehicles, where the instructions are
      // what a passenger reads.
      _followMe = _navigationStarted;
      _showSteps = false;
      _route = null;
      _routeUnavailable = false;
    });
    // Re-aim immediately rather than waiting for the next GPS fix, so
    // switching to Car does not leave a flat overhead camera on screen.
    if (_origin != null) _followCamera(_origin!);
    if (_origin != null) {
      await _applyRoute(_origin!);
    } else {
      await _start();
    }
    _startLiveTracking();
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
    // The destination wears the brand navy and a white ring; the student's
    // own puck is the brighter [ArucadColors.liveLocation] and breathes.
    // Before this they were the same colour and the same shape, so "me"
    // and "where I am going" were told apart only by the label.
    markers.add(CampusMapMarker(
      id: 'destination',
      position: _destination,
      label: _destinationName,
      color: ArucadColors.primary,
      emphasized: true,
      ringed: true,
    ));
    if (_origin != null && _usedFallbackOrigin) {
      // Not a real fix, so it gets a plain marker rather than the live
      // location puck — the puck means "this is where you are".
      markers.add(CampusMapMarker(
        id: 'origin',
        position: _origin!,
        label: strings.t('nav_campus_entrance'),
        color: ArucadColors.slate,
      ));
    }
    return markers;
  }

  /// The camera for the current mode.
  ///
  /// Walking gets a bird's-eye view: straight down, north-up, close in. On
  /// foot the useful thing is the shape of the paths around you, and a
  /// tilted windscreen view hides exactly that.
  ///
  /// Car and bus remain flat as well, but rotate to face the direction of
  /// travel. This keeps the requested 2D building/road view while making the
  /// next turn readable.
  void _followCamera(GeoPoint origin) {
    if (_mode == TravelMode.walking) {
      _mapController.levelOut(origin);

      return;
    }

    _mapController.followAlong(
      origin,
      zoom: 17.5,
      // Taken from the ROUTE, not from the device compass: a phone on a
      // passenger seat points wherever it is lying, and the route is the
      // thing that actually knows which way the road goes.
      bearing: _bearingAlongRoute(origin),
      tilt: 0,
    );
  }

  /// Which way the route goes from here, in degrees clockwise from north.
  ///
  /// Finds the nearest point on the drawn route and looks ahead to the
  /// first point at least [_bearingLookaheadMeters] further on, so a
  /// cluster of closely-spaced geometry points cannot produce a jittery
  /// bearing. Falls back to the straight line to the destination when the
  /// route is too short to look ahead in.
  double _bearingAlongRoute(GeoPoint from) {
    final points = _route?.points ?? const <GeoPoint>[];
    if (points.length < 2) {
      return _bearingBetween(from, _destination);
    }

    var nearest = 0;
    var nearestMeters = double.infinity;
    for (var i = 0; i < points.length; i++) {
      final meters = Geolocator.distanceBetween(
          from.lat, from.lng, points[i].lat, points[i].lng);
      if (meters < nearestMeters) {
        nearestMeters = meters;
        nearest = i;
      }
    }

    for (var i = nearest + 1; i < points.length; i++) {
      final ahead = Geolocator.distanceBetween(
          from.lat, from.lng, points[i].lat, points[i].lng);
      if (ahead >= _bearingLookaheadMeters) {
        return _bearingBetween(from, points[i]);
      }
    }

    return _bearingBetween(from, points.last);
  }

  double _bearingBetween(GeoPoint from, GeoPoint to) {
    final bearing =
        Geolocator.bearingBetween(from.lat, from.lng, to.lat, to.lng);

    // Geolocator returns -180..180; MapLibre wants 0..360.
    return (bearing + 360) % 360;
  }

  /// Snap back over the student and resume following. A long-press
  /// instead frames the whole route and stops following, which is what
  /// someone checking "how much further" actually wants.
  void _recenter() {
    if (_origin != null && !_usedFallbackOrigin) {
      setState(() => _followMe = true);
      _followCamera(_origin!);
    } else {
      _mapController.fitPoints(_extentPoints);
    }
  }

  void _showWholeRoute() {
    setState(() => _followMe = false);
    _mapController.fitPoints(_extentPoints, padding: 72);
  }

  void _startNavigation() {
    setState(() {
      _navigationStarted = true;
      _followMe = true;
      _showSteps = false;
    });
    if (_origin != null) _followCamera(_origin!);
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final scheme = Theme.of(context).colorScheme;
    return Scaffold(
      extendBody: true,
      body: Stack(children: [
        CampusMapView(
          extentPoints: _extentPoints,
          controller: _mapController,
          userLocation: _origin,
          showUserLocation: !_usedFallbackOrigin,
          userName: _me?.name ?? '',
          userAvatarUrl: _me?.avatarUrl,
          routePoints: _route?.points,
          // Solid = real road/path geometry from the provider; dashed only
          // shows when that provider was unavailable for this request.
          routeDashed: _mode.usesBirdsEyeLine,
          routeColor: ArucadColors.primary,
          markers: _markers(),
        ),
        if (!_navigationStarted)
          Positioned(
            top: MediaQuery.paddingOf(context).top + 92,
            left: 12,
            right: 12,
            child: _ModeSelector(
              mode: _mode,
              onChanged: _changeMode,
              labelFor: (m) => m.labelFor(strings.t),
            ),
          ),
        Positioned(
          top: MediaQuery.paddingOf(context).top + 10,
          left: 12,
          right: 12,
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _MapRoundButton(
                icon: Icons.arrow_back,
                tooltip: MaterialLocalizations.of(context).backButtonTooltip,
                onTap: () => Navigator.of(context).pop(),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _NavigationInstructionCard(
                  step:
                      _navigationStarted && (_route?.steps.isNotEmpty ?? false)
                          ? _route!.steps.first
                          : null,
                  destinationName: _destinationName,
                  navigationStarted: _navigationStarted,
                ),
              ),
            ],
          ),
        ),
        Positioned(
          right: 14,
          bottom: _route == null ? 24 : 122,
          child: Column(
            children: [
              if (_navigationStarted) ...[
                _MapRoundButton(
                  icon: _muted ? Icons.volume_off : Icons.volume_up,
                  tooltip: strings.t(_muted ? 'nav_unmute' : 'nav_mute'),
                  onTap: () => setState(() => _muted = !_muted),
                ),
                const SizedBox(height: 10),
              ],
              _MapRoundButton(
                icon: Icons.explore_outlined,
                tooltip: strings.t('nav_overview'),
                onTap: _showWholeRoute,
              ),
              const SizedBox(height: 10),
              _RecenterButton(
                onTap: _recenter,
                onLongPress: _showWholeRoute,
                following: _followMe,
                tooltip:
                    strings.t(_followMe ? 'nav_follow_on' : 'nav_follow_off'),
              ),
            ],
          ),
        ),
        if (!_usedFallbackOrigin)
          Positioned(
            left: 14,
            bottom: _route == null ? 14 : 92,
            child: _RouteLegend(destinationName: _destinationName),
          ),
        if (_loading)
          Positioned(
            top: MediaQuery.paddingOf(context).top + 158,
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
            top: MediaQuery.paddingOf(context).top + 158,
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
        if (_routeUnavailable && !_loading)
          Positioned(
            top: MediaQuery.paddingOf(context).top + 158,
            left: 16,
            right: 16,
            child: Card(
              color: scheme.errorContainer,
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Row(children: [
                  Icon(Icons.route_outlined,
                      size: 19, color: scheme.onErrorContainer),
                  const SizedBox(width: 9),
                  Expanded(
                    child: Text(strings.t('nav_route_unavailable'),
                        style: TextStyle(
                            fontSize: 12.5,
                            fontWeight: FontWeight.w700,
                            color: scheme.onErrorContainer)),
                  ),
                ]),
              ),
            ),
          ),
      ]),
      bottomNavigationBar: _route == null
          ? null
          : SafeArea(
              child: Container(
                margin: const EdgeInsets.fromLTRB(10, 0, 10, 8),
                decoration: BoxDecoration(
                  color: scheme.surface.withValues(alpha: .98),
                  borderRadius: BorderRadius.circular(24),
                  boxShadow: const [
                    BoxShadow(
                      color: Colors.black26,
                      blurRadius: 18,
                      offset: Offset(0, -3),
                    ),
                  ],
                ),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    if (!_navigationStarted)
                      _DirectionsPanel(
                        steps: _route!.steps,
                        pending: _routePending,
                        expanded: _showSteps,
                        onToggle: () =>
                            setState(() => _showSteps = !_showSteps),
                      ),
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 8, 16, 14),
                      child: _navigationStarted
                          ? _ActiveNavigationSummary(
                              route: _route!,
                              mode: _mode,
                              onFinish: () => Navigator.of(context).pop(),
                            )
                          : SizedBox(
                              width: double.infinity,
                              child: FilledButton.icon(
                                onPressed:
                                    _routePending ? null : _startNavigation,
                                icon: const Icon(Icons.navigation_rounded),
                                label: Text(strings.t('nav_start')),
                              ),
                            ),
                    ),
                  ],
                ),
              ),
            ),
    );
  }
}

class _NavigationInstructionCard extends StatelessWidget {
  final RouteStep? step;
  final String destinationName;
  final bool navigationStarted;

  const _NavigationInstructionCard({
    required this.step,
    required this.destinationName,
    required this.navigationStarted,
  });

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final scheme = Theme.of(context).colorScheme;
    final current = step;
    return MapPointerGuard(
      child: Container(
        constraints: const BoxConstraints(minHeight: 72),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        decoration: BoxDecoration(
          color: const Color(0xEB10243B),
          borderRadius: BorderRadius.circular(20),
          boxShadow: const [
            BoxShadow(
                color: Colors.black26, blurRadius: 14, offset: Offset(0, 5)),
          ],
        ),
        child: Row(children: [
          Icon(
              current?.icon ??
                  (navigationStarted
                      ? Icons.navigation_rounded
                      : Icons.place_rounded),
              color: Colors.white,
              size: 34),
          const SizedBox(width: 13),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Text(
                  current?.labelFor(strings.t) ??
                      (navigationStarted
                          ? destinationName
                          : strings
                              .t('nav_route_to', {'place': destinationName})),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w900,
                    fontSize: 17,
                  ),
                ),
                if (current != null && current.distanceMeters > 0)
                  Text(
                    current.distanceLabel,
                    style: TextStyle(
                      color: scheme.primaryContainer,
                      fontWeight: FontWeight.w700,
                      fontSize: 12,
                    ),
                  ),
              ],
            ),
          ),
        ]),
      ),
    );
  }
}

class _ActiveNavigationSummary extends StatelessWidget {
  final RouteResult route;
  final TravelMode mode;
  final VoidCallback onFinish;

  const _ActiveNavigationSummary({
    required this.route,
    required this.mode,
    required this.onFinish,
  });

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final scheme = Theme.of(context).colorScheme;
    final arrival = route.durationSeconds <= 0 || route.durationText.isEmpty
        ? null
        : TimeOfDay.fromDateTime(
            DateTime.now()
                .add(Duration(seconds: route.durationSeconds.round())),
          ).format(context);
    return Row(children: [
      Icon(mode.icon, color: ArucadColors.primary, size: 26),
      const SizedBox(width: 10),
      Expanded(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
                route.durationText.isEmpty
                    ? route.distanceText
                    : route.durationText,
                style: TextStyle(
                    fontWeight: FontWeight.w900,
                    fontSize: 24,
                    color: scheme.onSurface)),
            Text(
              arrival == null
                  ? strings.t('nav_route_ready')
                  : '${route.distanceText} · ${strings.t('nav_arrival', {
                          'time': arrival
                        })}',
              style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant),
            ),
          ],
        ),
      ),
      OutlinedButton.icon(
        onPressed: onFinish,
        icon: const Icon(Icons.close, size: 18),
        label: Text(strings.t('nav_finish')),
      ),
    ]);
  }
}

class _MapRoundButton extends StatelessWidget {
  final IconData icon;
  final String tooltip;
  final VoidCallback onTap;

  const _MapRoundButton({
    required this.icon,
    required this.tooltip,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) => MapPointerGuard(
        child: Material(
          color: Theme.of(context).colorScheme.surface,
          shape: const CircleBorder(),
          elevation: 5,
          child: IconButton(
            tooltip: tooltip,
            onPressed: onTap,
            icon: Icon(icon),
          ),
        ),
      );
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

/// Tap to snap back over the student and resume bird's-eye follow; hold to
/// frame the whole route instead. The icon says which of the two the map
/// is doing right now, so a camera that has stopped following is visible
/// rather than something the student has to work out.
class _RecenterButton extends StatelessWidget {
  final VoidCallback onTap;
  final VoidCallback onLongPress;
  final bool following;
  final String tooltip;

  const _RecenterButton({
    required this.onTap,
    required this.onLongPress,
    required this.following,
    required this.tooltip,
  });

  @override
  Widget build(BuildContext context) => MapPointerGuard(
        child: Tooltip(
          message: tooltip,
          child: Material(
            color: Theme.of(context).colorScheme.surface,
            shape: const CircleBorder(),
            elevation: 3,
            child: InkWell(
              customBorder: const CircleBorder(),
              onTap: onTap,
              onLongPress: onLongPress,
              child: Padding(
                padding: const EdgeInsets.all(10),
                child: Icon(
                  following ? Icons.my_location : Icons.location_searching,
                  color: following
                      ? ArucadColors.liveLocation
                      : ArucadColors.muted,
                  size: 20,
                ),
              ),
            ),
          ),
        ),
      );
}

/// Which mark on the map is which.
///
/// The student's puck and the destination pin used to be the same brand
/// navy, so on a route between two campus buildings there was no way to
/// tell at a glance which end was you. They are now a bright pulsing blue
/// and a white-ringed navy pin, and this names both.
class _RouteLegend extends StatelessWidget {
  final String destinationName;

  const _RouteLegend({required this.destinationName});

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    return MapPointerGuard(
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
        constraints: const BoxConstraints(maxWidth: 210),
        decoration: BoxDecoration(
          color: Theme.of(context).colorScheme.surface.withValues(alpha: .94),
          borderRadius: BorderRadius.circular(14),
          boxShadow: [
            BoxShadow(
                color: Colors.black.withValues(alpha: .12),
                blurRadius: 8,
                offset: const Offset(0, 3)),
          ],
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _LegendRow(
              color: ArucadColors.liveLocation,
              label: strings.t('nav_legend_you'),
              ringed: true,
            ),
            const SizedBox(height: 5),
            _LegendRow(
              color: ArucadColors.primary,
              label: strings.t('nav_legend_route'),
              asLine: true,
            ),
            const SizedBox(height: 5),
            _LegendRow(
              color: ArucadColors.primary,
              label: destinationName,
              ringed: true,
            ),
          ],
        ),
      ),
    );
  }
}

class _LegendRow extends StatelessWidget {
  final Color color;
  final String label;
  final bool ringed;
  final bool asLine;

  const _LegendRow({
    required this.color,
    required this.label,
    this.ringed = false,
    this.asLine = false,
  });

  @override
  Widget build(BuildContext context) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: asLine ? 14 : 11,
            height: asLine ? 4 : 11,
            decoration: BoxDecoration(
              color: color,
              shape: asLine ? BoxShape.rectangle : BoxShape.circle,
              borderRadius: asLine ? BorderRadius.circular(2) : null,
              border: ringed ? Border.all(color: Colors.white, width: 2) : null,
            ),
          ),
          const SizedBox(width: 7),
          Flexible(
            child: Text(label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                    fontSize: 10.5, fontWeight: FontWeight.w800)),
          ),
        ],
      );
}

/// The provider's turn-by-turn list.
///
/// The backend has always returned these and nothing ever displayed them,
/// so a car or bus route was a coloured line and a duration with no
/// instructions at all. Collapsible, because walking is followed live on
/// the map and does not need the list in the way; car and bus open it by
/// default, since the instructions are the whole point of a preview.
class _DirectionsPanel extends StatelessWidget {
  final List<RouteStep> steps;

  /// The real geometry has not arrived yet, so "this route has no turns"
  /// is not yet a true statement to make.
  final bool pending;

  final bool expanded;
  final VoidCallback onToggle;

  const _DirectionsPanel({
    required this.steps,
    required this.pending,
    required this.expanded,
    required this.onToggle,
  });

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final scheme = Theme.of(context).colorScheme;

    if (steps.isEmpty && pending) return const SizedBox.shrink();

    if (steps.isEmpty) {
      // A straight-line fallback has no turns to list. Saying so beats a
      // button that opens an empty panel.
      return Padding(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
        child: Row(children: [
          Icon(Icons.info_outline, size: 15, color: scheme.onSurfaceVariant),
          const SizedBox(width: 8),
          Expanded(
            child: Text(strings.t('nav_steps_unavailable'),
                style:
                    TextStyle(fontSize: 11.5, color: scheme.onSurfaceVariant)),
          ),
        ]),
      );
    }

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        InkWell(
          onTap: onToggle,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 6),
            child: Row(children: [
              Icon(Icons.turn_right, size: 18, color: ArucadColors.primary),
              const SizedBox(width: 8),
              Text(strings.t('nav_directions'),
                  style: const TextStyle(
                      fontWeight: FontWeight.w900, fontSize: 13)),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  strings.t('nav_steps_count', {'count': steps.length}),
                  style:
                      TextStyle(fontSize: 11.5, color: scheme.onSurfaceVariant),
                ),
              ),
              Icon(expanded ? Icons.expand_less : Icons.expand_more,
                  size: 20, color: scheme.onSurfaceVariant),
            ]),
          ),
        ),
        if (expanded)
          ConstrainedBox(
            constraints: BoxConstraints(
              maxHeight: MediaQuery.of(context).size.height * .26,
            ),
            child: ListView.builder(
              shrinkWrap: true,
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 4),
              itemCount: steps.length,
              itemBuilder: (context, index) {
                final step = steps[index];
                return Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Icon(step.icon, size: 18, color: ArucadColors.primary),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(step.labelFor(strings.t),
                            style: const TextStyle(
                                fontSize: 12.5, fontWeight: FontWeight.w600)),
                      ),
                      const SizedBox(width: 8),
                      Text(step.distanceLabel,
                          style: TextStyle(
                              fontSize: 11.5,
                              fontWeight: FontWeight.w700,
                              color: scheme.onSurfaceVariant)),
                    ],
                  ),
                );
              },
            ),
          ),
      ],
    );
  }
}
