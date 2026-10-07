import 'dart:async';
import 'dart:math' show Point;
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:maplibre_gl/maplibre_gl.dart';

import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:arucad_campus_prototype/core/network/media_url.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/map/activity_point.dart';

/// Official OpenFreeMap vector style. The temporary 256 px raster fallback
/// became visibly soft on high-density phones and hid pedestrian/building
/// detail that students need while navigating. Positron keeps the campus
/// legible while MapLibre renders vectors sharply at every device scale.
// Liberty preserves the natural land/water/road palette while retaining
// stronger contrast than Positron. It is OpenFreeMap's full vector style,
// so labels stay sharp at every zoom instead of being enlarged raster tiles.
const _openFreeMapStyle = 'https://tiles.openfreemap.org/styles/liberty';

/// OpenFreeMap serves these glyph stacks. MapLibre's default
/// `Open Sans Regular, Arial Unicode MS Regular` 404s on their font host.

LatLng _ll(GeoPoint p) => LatLng(p.lat, p.lng);

String _hex(Color c) {
  final v = c.toARGB32() & 0xFFFFFF;
  return '#${v.toRadixString(16).padLeft(6, '0')}';
}

/// A real, tappable named marker (rendered as a text label with a colored
/// halo — no custom bitmap icons to generate/maintain).
class CampusMapMarker {
  final String id;
  final GeoPoint position;
  final String label;
  final Color color;
  final VoidCallback? onTap;

  /// Larger text for a single "destination"-style marker vs. the smaller
  /// pills used for POI labels.
  final bool emphasized;

  /// Draws a white ring around the core, the way a dropped destination pin
  /// reads on every other map. Used with [emphasized] so the place a
  /// student is walking to is unmistakably not just another POI dot.
  final bool ringed;

  const CampusMapMarker({
    required this.id,
    required this.position,
    required this.label,
    required this.color,
    this.onTap,
    this.emphasized = false,
    this.ringed = false,
  });
}

/// Concentric, fading circles per place — the real "Campus Pulse"
/// density glow, derived from [CampusPlace.density]. [baseRadiusMeters] is
/// historical naming from the old Canvas engine; MapLibre's circle-radius
/// is screen-space pixels, not ground meters, so this is now treated as a
/// small pixel radius directly rather than converted — still a real,
/// data-driven visualization, just not meter-accurate across zoom levels.
class CampusPulseZone {
  final GeoPoint center;
  final double baseRadiusMeters;
  final Color color;
  final double intensity;
  const CampusPulseZone({
    required this.center,
    required this.baseRadiusMeters,
    required this.color,
    required this.intensity,
  });
}

/// Small, non-interactive context dot for a place that doesn't get a full
/// tappable marker — keeps the map from looking empty without cluttering it
/// with 20+ tappable pins.
class CampusMapContextDot {
  final GeoPoint position;
  final Color color;
  const CampusMapContextDot({required this.position, required this.color});
}

/// External control surface for a [CampusMapView] — lets a caller recenter
/// or fit-to-bounds from a button press without reaching into MapLibre's
/// own controller directly.
class CampusMapController {
  MapLibreMapController? _map;

  void _attach(MapLibreMapController map) => _map = map;
  void _detach(MapLibreMapController map) {
    if (identical(_map, map)) _map = null;
  }

  Future<void> centerOn(GeoPoint point, {double zoom = 17}) async {
    await _map?.animateCamera(CameraUpdate.newLatLngZoom(_ll(point), zoom));
  }

  /// A driving-style camera: over [point], turned to face [bearing] and
  /// tilted towards the horizon.
  ///
  /// This is what makes a vehicle route look like road navigation rather
  /// than a map being looked at from above. Walking deliberately does NOT
  /// use it — on foot, a plan view of the paths around you is more useful
  /// than a windscreen.
  Future<void> followAlong(
    GeoPoint point, {
    double zoom = 17.5,
    double bearing = 0,
    double tilt = 55,
  }) async {
    await _map?.animateCamera(CameraUpdate.newCameraPosition(CameraPosition(
      target: _ll(point),
      zoom: zoom,
      bearing: bearing,
      tilt: tilt,
    )));
  }

  /// Back to a flat, north-up view. Used when leaving a vehicle mode so a
  /// tilted camera does not persist into the walking view.
  Future<void> levelOut(GeoPoint point, {double zoom = 18.6}) async {
    await _map?.animateCamera(CameraUpdate.newCameraPosition(CameraPosition(
      target: _ll(point),
      zoom: zoom,
      bearing: 0,
      tilt: 0,
    )));
  }

  Future<void> fitPoints(List<GeoPoint> points, {double padding = 60}) async {
    final map = _map;
    if (map == null || points.isEmpty) return;
    if (points.length == 1) {
      await centerOn(points.first);
      return;
    }
    var minLat = points.first.lat, maxLat = points.first.lat;
    var minLng = points.first.lng, maxLng = points.first.lng;
    for (final p in points) {
      if (p.lat < minLat) minLat = p.lat;
      if (p.lat > maxLat) maxLat = p.lat;
      if (p.lng < minLng) minLng = p.lng;
      if (p.lng > maxLng) maxLng = p.lng;
    }
    await map.animateCamera(CameraUpdate.newLatLngBounds(
      LatLngBounds(
          southwest: LatLng(minLat, minLng), northeast: LatLng(maxLat, maxLng)),
      left: padding,
      top: padding,
      right: padding,
      bottom: padding,
    ));
  }
}

class CampusMapView extends StatefulWidget {
  /// Points the camera fits to on first load.
  final List<GeoPoint> extentPoints;
  final GeoPoint? focusPoint;
  final List<CampusMapMarker> markers;
  final List<CampusPulseZone> pulseZones;
  final List<ActivityPoint> activityPoints;
  final List<CampusMapContextDot> contextDots;
  final List<GeoPoint>? routePoints;
  final bool routeDashed;
  final Color routeColor;

  /// Device location rendered by our own annotation layer. Circle radii are
  /// screen pixels, so this stays a normal-size puck at every zoom.
  final GeoPoint? userLocation;
  final bool showUserLocation;
  final String userName;
  final String? userAvatarUrl;

  /// Breathe the accuracy halo around the puck.
  ///
  /// A static dot is indistinguishable from a pin someone dropped, which
  /// is exactly the confusion this removes: the thing that pulses is you,
  /// live, right now. The animation only ever touches the halo's radius
  /// and opacity, so the core stays a stable, precise position marker.
  final bool pulseUserLocation;

  final CampusMapController? controller;

  const CampusMapView({
    super.key,
    required this.extentPoints,
    this.focusPoint,
    this.markers = const [],
    this.pulseZones = const [],
    this.activityPoints = const [],
    this.contextDots = const [],
    this.routePoints,
    this.routeDashed = false,
    this.routeColor = ArucadColors.blue,
    this.userLocation,
    this.showUserLocation = false,
    this.userName = '',
    this.userAvatarUrl,
    this.pulseUserLocation = true,
    this.controller,
  });

  @override
  State<CampusMapView> createState() => _CampusMapViewState();
}

class _CampusMapViewState extends State<CampusMapView>
    with SingleTickerProviderStateMixin {
  MapLibreMapController? _map;
  bool _styleReady = false;
  bool _pinReady = false;
  bool _placeholdersReady = false;
  bool _activityHeatmapReady = false;
  bool _routeGeoJsonReady = false;
  bool _annotationSyncRunning = false;
  bool _annotationSyncRequested = false;
  Offset? _userScreenPosition;
  int _userProjectionRequest = 0;
  List<_MapPlaceLabelOverlay> _placeLabelOverlays = const [];
  bool _labelProjectionRunning = false;
  bool _labelProjectionRequested = false;
  double _zoom = 16.4;
  final Map<String, VoidCallback> _markerTaps = {};

  /// The breathing halo under the location puck. MapLibre's annotation API
  /// has no animated properties, so the radius/opacity are pushed with
  /// updateCircle on a ticker. That is a platform-channel call per frame,
  /// which is why it is deliberately throttled to [_pulseFrame] rather
  /// than run at display refresh rate — a two-second breath reads exactly
  /// the same at 14fps and costs a fraction as much.
  late final AnimationController _pulse = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 2000),
  );
  static const _pulseFrame = Duration(milliseconds: 70);
  Circle? _userHalo;
  Duration _lastPulseFrame = Duration.zero;

  @override
  void initState() {
    super.initState();
    _pulse.addListener(_onPulseTick);
    _syncPulseState();
  }

  bool get _shouldPulse =>
      widget.pulseUserLocation &&
      widget.showUserLocation &&
      widget.userLocation != null;

  void _onPulseTick() {
    final map = _map;
    final halo = _userHalo;
    if (map == null || halo == null) return;

    final elapsed = _pulse.lastElapsedDuration ?? Duration.zero;
    if (elapsed - _lastPulseFrame < _pulseFrame) return;
    _lastPulseFrame = elapsed;

    // One outward breath per cycle: the halo grows and fades, then resets.
    final t = _pulse.value;
    final eased = Curves.easeOutCubic.transform(t);
    map.updateCircle(
      halo,
      CircleOptions(
        circleRadius: 10 + 8 * eased,
        circleOpacity: 0.22 * (1 - eased),
      ),
    );
  }

  @override
  void didUpdateWidget(covariant CampusMapView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.controller != widget.controller) {
      final map = _map;
      if (map != null) {
        oldWidget.controller?._detach(map);
        widget.controller?._attach(map);
      }
    }
    if (_styleReady &&
        (oldWidget.markers != widget.markers ||
            oldWidget.pulseZones != widget.pulseZones ||
            oldWidget.activityPoints != widget.activityPoints ||
            oldWidget.contextDots != widget.contextDots ||
            oldWidget.routePoints != widget.routePoints ||
            oldWidget.userLocation != widget.userLocation ||
            oldWidget.userName != widget.userName ||
            oldWidget.userAvatarUrl != widget.userAvatarUrl ||
            oldWidget.focusPoint != widget.focusPoint)) {
      _syncAnnotations();
    }
    if (oldWidget.userLocation != widget.userLocation ||
        oldWidget.showUserLocation != widget.showUserLocation) {
      unawaited(_updateUserOverlayPosition());
    }
    final focus = widget.focusPoint;
    if (_styleReady &&
        focus != null &&
        oldWidget.focusPoint != widget.focusPoint) {
      unawaited(_map?.animateCamera(
        CameraUpdate.newLatLngZoom(_ll(focus), 17.8),
      ));
    }
    _syncPulseState();
  }

  @override
  void dispose() {
    _pulse.removeListener(_onPulseTick);
    _pulse.dispose();
    final map = _map;
    if (map != null) {
      widget.controller?._detach(map);
      map.onSymbolTapped.remove(_handleSymbolTap);
      map.onCircleTapped.remove(_handleCircleTap);
    }
    super.dispose();
  }

  /// Only run the ticker while there is actually a puck to breathe.
  void _syncPulseState() {
    if (_shouldPulse) {
      if (!_pulse.isAnimating) {
        // lastElapsedDuration restarts from zero with the ticker, so the
        // throttle's high-water mark has to restart with it. Left alone,
        // every tick after a restart looked like it had arrived too soon
        // and the halo froze at whatever size it stopped on.
        _lastPulseFrame = Duration.zero;
        _pulse.repeat();
      }
    } else if (_pulse.isAnimating) {
      _pulse.stop();
    }
  }

  void _handleSymbolTap(Symbol symbol) {
    final id = symbol.data?['markerId'] as String?;
    if (id != null) _markerTaps[id]?.call();
  }

  void _handleCircleTap(Circle circle) {
    final id = circle.data?['markerId'] as String?;
    if (id != null) _markerTaps[id]?.call();
  }

  Future<void> _onStyleLoaded() async {
    _styleReady = true;
    _pinReady = false;
    _activityHeatmapReady = false;
    _routeGeoJsonReady = false;
    final map = _map;
    if (map != null) {
      await _ensureCampusPin(map);
      await _registerPoiIconPlaceholders(map);
      await _syncActivityHeatmap(map);
      await _syncRouteGeoJson(map);
      // Deliberately NOT forcing allow-overlap/ignore-placement here —
      // that used to force every campus label to render regardless of
      // collision, which is exactly what made labels run into each other
      // once zoomed out far enough to bring many POIs into the same
      // screen area. Leaving SymbolManager at its own default (collision
      // detection on) lets MapLibre hide/thin out crowded labels the way
      // every other map does, instead of drawing all of them on top of
      // each other.
    }
    await _syncAnnotations();
  }

  Map<String, dynamic> _activityFeatureCollection() => {
        'type': 'FeatureCollection',
        'features': widget.activityPoints.isNotEmpty
            ? [
                for (final point in widget.activityPoints)
                  point.toGeoJsonFeature()
              ]
            : [
                for (var i = 0; i < widget.pulseZones.length; i++)
                  {
                    'type': 'Feature',
                    'id': i,
                    'properties': {
                      'weight': widget.pulseZones[i].intensity.clamp(0.0, 1.0),
                    },
                    'geometry': {
                      'type': 'Point',
                      'coordinates': [
                        widget.pulseZones[i].center.lng,
                        widget.pulseZones[i].center.lat,
                      ],
                    },
                  },
              ],
      };

  /// A native MapLibre heatmap, backed by GeoJSON rather than stacked
  /// annotation circles. It remains the only activity representation at
  /// every distant/medium zoom. At zoom 16 it hands over directly to named
  /// individual POIs; there is deliberately no numeric cluster-badge stage.
  Future<void> _syncActivityHeatmap(MapLibreMapController map) async {
    const sourceId = 'aruverse-activity';
    const layerId = 'aruverse-activity-heat';
    final data = _activityFeatureCollection();
    try {
      if (_activityHeatmapReady) {
        await map.setGeoJsonSource(sourceId, data);
        return;
      }
      await map.addGeoJsonSource(sourceId, data);
      await map.addHeatmapLayer(
        sourceId,
        layerId,
        const HeatmapLayerProperties(
          heatmapWeight: ['get', 'weight'],
          heatmapRadius: [
            'interpolate',
            ['linear'],
            ['zoom'],
            9,
            15,
            13,
            27,
            15.9,
            38,
          ],
          heatmapIntensity: [
            'interpolate',
            ['linear'],
            ['zoom'],
            9,
            0.65,
            13,
            0.95,
            15.9,
            1.12,
          ],
          heatmapColor: [
            'interpolate',
            ['linear'],
            ['heatmap-density'],
            0,
            'rgba(38,166,91,0)',
            0.2,
            'rgba(78,190,105,0.42)',
            0.45,
            'rgba(246,211,64,0.52)',
            0.7,
            'rgba(246,139,31,0.62)',
            1,
            'rgba(220,53,69,0.72)',
          ],
          heatmapOpacity: 0.72,
        ),
        minzoom: 9,
        maxzoom: 16,
      );
      _activityHeatmapReady = true;
    } catch (_) {
      // A style reload can briefly leave the source registered before the
      // layer. Retrying on the next annotation sync is safe.
      try {
        await map.setGeoJsonSource(sourceId, data);
        _activityHeatmapReady = true;
      } catch (_) {}
    }
  }

  Map<String, dynamic> _routeFeatureCollection() => {
        'type': 'FeatureCollection',
        'features': [
          if ((widget.routePoints?.length ?? 0) >= 2)
            {
              'type': 'Feature',
              'properties': const {},
              'geometry': {
                'type': 'LineString',
                'coordinates': [
                  for (final point in widget.routePoints!)
                    [point.lng, point.lat],
                ],
              },
            },
        ],
      };

  Future<void> _syncRouteGeoJson(MapLibreMapController map) async {
    const sourceId = 'aruverse-route';
    const casingId = 'aruverse-route-casing';
    const lineId = 'aruverse-route-line';
    final data = _routeFeatureCollection();
    final foreground = LineLayerProperties(
      lineColor: _hex(widget.routeColor),
      lineWidth: widget.routeDashed ? 4.5 : 6,
      lineOpacity: 1,
      lineJoin: 'round',
      lineCap: 'round',
      lineDasharray: widget.routeDashed ? const [1.2, 1.1] : null,
    );
    try {
      if (!_routeGeoJsonReady) {
        await map.addGeoJsonSource(sourceId, data);
        await map.addLineLayer(
          sourceId,
          casingId,
          const LineLayerProperties(
            lineColor: '#ffffff',
            lineWidth: 9,
            lineOpacity: 0.9,
            lineJoin: 'round',
            lineCap: 'round',
          ),
        );
        await map.addLineLayer(sourceId, lineId, foreground);
        _routeGeoJsonReady = true;
      } else {
        await map.setGeoJsonSource(sourceId, data);
        await map.setLayerProperties(lineId, foreground);
      }
    } catch (_) {
      try {
        await map.setGeoJsonSource(sourceId, data);
      } catch (_) {}
    }
  }

  Future<void> _ensureCampusPin(MapLibreMapController map) async {
    if (_pinReady) return;
    try {
      await map.addImage('campus-pin', await _campusPinPng());
      _pinReady = true;
    } catch (_) {
      // Labels still render as text; the icon is only a stand-in so
      // MapLibre does not look up a missing sprite id.
    }
  }

  /// The OpenFreeMap Liberty style's POI layers reference icon ids
  /// (`ferry_terminal`, `gate`, `office`, `swimming_pool`, …) that its sprite
  /// does not always ship. MapLibre then logs an "Image X could not be loaded"
  /// warning per id — harmless (the icon just doesn't draw) but noisy in the
  /// diagnostics. MapLibre's own advice is to supply the missing images with
  /// addImage(); a 1×1 transparent placeholder satisfies the lookup silently
  /// without drawing anything the campus map does not need.
  Future<void> _registerPoiIconPlaceholders(MapLibreMapController map) async {
    if (_placeholdersReady) return;

    final Uint8List transparent;
    try {
      transparent = await _transparentPng();
    } catch (_) {
      return; // Retried on the next sync.
    }

    // One try/catch PER IMAGE, not around the loop.
    //
    // A single addImage throwing used to abort the whole registration and
    // leave _placeholdersReady false, so every id after the failing one
    // stayed unregistered and kept warning — which is why names that were
    // already in this list ("gate", "office", "ferry_terminal") still
    // showed up in the console. One unsupported id must not disable the
    // other forty-nine.
    for (final name in _missingPoiIconNames) {
      try {
        await map.addImage(name, transparent);
      } catch (_) {
        // Cosmetic only: this id keeps warning, the rest are fixed.
      }
    }
    _placeholdersReady = true;
  }

  /// Identity, GPS and live-crowd updates can arrive in the same frame. A
  /// concurrent sync clears symbols created by the other sync, which used to
  /// make the freshly-added user avatar disappear. Drain all requests in one
  /// serial loop so the final map state always matches the latest widget.
  Future<void> _syncAnnotations() async {
    _annotationSyncRequested = true;
    if (_annotationSyncRunning) return;
    _annotationSyncRunning = true;
    try {
      while (_annotationSyncRequested) {
        _annotationSyncRequested = false;
        await _performAnnotationSync();
      }
    } finally {
      _annotationSyncRunning = false;
    }
  }

  Future<void> _performAnnotationSync() async {
    final map = _map;
    if (map == null) return;
    await _syncActivityHeatmap(map);
    await _syncRouteGeoJson(map);
    // Drop the halo reference before anything is cleared, and do it
    // synchronously: the pulse ticker runs between these awaits, and
    // updating a circle that clearCircles() has already removed is an
    // error thrown from inside an animation callback.
    _userHalo = null;
    await map.clearCircles();
    await map.clearSymbols();
    await map.clearLines();
    _markerTaps.clear();

    for (final dot in widget.contextDots) {
      await map.addCircle(_filledCircle(
        geometry: _ll(dot.position),
        radius: 2.8,
        color: _hex(dot.color),
        opacity: 0.62,
        blur: 0.35,
        strokeColor: '#ffffff',
        strokeWidth: 0.8,
        strokeOpacity: 0.6,
      ));
    }

    final userLocation = widget.showUserLocation ? widget.userLocation : null;
    if (userLocation != null) {
      // The live-location pulse remains, but the anonymous blue core is
      // replaced by the signed-in student's own circular profile image.
      // A generated initial is used only when no photo has been uploaded.
      _userHalo = await map.addCircle(_filledCircle(
        geometry: _ll(userLocation),
        radius: 14,
        color: _hex(ArucadColors.liveLocation),
        opacity: 0.18,
        blur: 0.86,
      ));
    }
    _syncPulseState();

    if (_zoom >= 16) {
      for (final marker in widget.markers) {
        // A soft heat halo communicates density at a glance without making the
        // POI itself a sharp target-looking dot. The coloured core stays clear
        // and tappable over the map labels.
        await map.addCircle(
          _filledCircle(
            geometry: _ll(marker.position),
            radius: _markerHaloRadius(marker.emphasized),
            color: _hex(marker.color),
            opacity: 0.22,
            blur: 0.84,
          ),
          {'markerId': marker.id},
        );
        await map.addCircle(
          _filledCircle(
            geometry: _ll(marker.position),
            radius: marker.emphasized ? 6 : 4.5,
            color: _hex(marker.color),
            opacity: 0.68,
            blur: 0.28,
            strokeColor: '#ffffff',
            strokeWidth: marker.ringed ? 1.5 : 0,
            strokeOpacity: 0.72,
          ),
          {'markerId': marker.id},
        );
        if (marker.onTap != null) _markerTaps[marker.id] = marker.onTap!;
      }
    }

    unawaited(_updatePlaceLabelOverlays());
  }

  @override
  Widget build(BuildContext context) {
    final extent = widget.extentPoints.isEmpty
        ? const [GeoPoint(35.33715, 33.32135)]
        : widget.extentPoints;
    final center = GeoPoint(
      extent.map((p) => p.lat).reduce((a, b) => a + b) / extent.length,
      extent.map((p) => p.lng).reduce((a, b) => a + b) / extent.length,
    );

    final avatarPosition = _userScreenPosition;
    final showAvatar = widget.showUserLocation &&
        widget.userLocation != null &&
        avatarPosition != null;

    return Stack(
      clipBehavior: Clip.hardEdge,
      children: [
        Positioned.fill(
          child: MapLibreMap(
            styleString: _openFreeMapStyle,
            initialCameraPosition:
                CameraPosition(target: _ll(center), zoom: 16.4),
            // A single Flutter overlay renders the same profile photo on web,
            // Android and iOS. The native platform puck remains disabled.
            myLocationEnabled: false,
            myLocationTrackingMode: MyLocationTrackingMode.none,
            trackCameraPosition: true,
            compassEnabled: false,
            rotateGesturesEnabled: false,
            tiltGesturesEnabled: false,
            onMapCreated: (controller) {
              _map = controller;
              widget.controller?._attach(controller);
              controller.onSymbolTapped.add(_handleSymbolTap);
              controller.onCircleTapped.add(_handleCircleTap);
            },
            onCameraIdle: _onCameraIdle,
            onCameraMove: (_) {
              unawaited(_updateUserOverlayPosition());
              unawaited(_updatePlaceLabelOverlays());
            },
            onStyleLoadedCallback: () {
              _onStyleLoaded();
              WidgetsBinding.instance.addPostFrameCallback((_) async {
                if (!mounted) return;
                await widget.controller?.fitPoints(extent, padding: 48);
                final focus = widget.focusPoint;
                if (focus != null) {
                  await widget.controller?.centerOn(focus, zoom: 18.2);
                }
                await _updateUserOverlayPosition();
                await _updatePlaceLabelOverlays();
              });
            },
          ),
        ),
        if (showAvatar)
          Positioned(
            left: avatarPosition.dx - 16,
            top: avatarPosition.dy - 16,
            child: IgnorePointer(
              child: _UserLocationAvatar(
                name: widget.userName,
                avatarUrl: widget.userAvatarUrl,
              ),
            ),
          ),
        for (final label in _placeLabelOverlays)
          Positioned(
            left: label.rect.left,
            top: label.rect.top,
            width: label.rect.width,
            height: label.rect.height,
            child: GestureDetector(
              behavior: HitTestBehavior.opaque,
              onTap: label.marker.onTap,
              child: DecoratedBox(
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: 0.86),
                  borderRadius: BorderRadius.circular(5),
                  boxShadow: const [
                    BoxShadow(color: Colors.black12, blurRadius: 3),
                  ],
                ),
                child: Center(
                  child: Text(
                    label.marker.label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      color: Color(0xFF242424),
                      fontSize: 9.5,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }

  void _onCameraIdle() {
    unawaited(_updateUserOverlayPosition());
    final z = _map?.cameraPosition?.zoom;
    if (z == null || !_styleReady) return;
    if ((z - _zoom).abs() >= 0.3) {
      _zoom = z;
      _syncAnnotations();
    } else {
      unawaited(_updatePlaceLabelOverlays());
    }
  }

  Future<void> _updateUserOverlayPosition() async {
    final request = ++_userProjectionRequest;
    final map = _map;
    final location = widget.showUserLocation ? widget.userLocation : null;
    if (map == null || location == null) {
      if (mounted && _userScreenPosition != null) {
        setState(() => _userScreenPosition = null);
      }
      return;
    }
    try {
      final point = await map.toScreenLocation(_ll(location));
      if (!mounted || request != _userProjectionRequest) return;
      final measured = Offset(point.x.toDouble(), point.y.toDouble());
      final size = context.size;
      final visible = size != null &&
          measured.dx >= -18 &&
          measured.dy >= -18 &&
          measured.dx <= size.width + 18 &&
          measured.dy <= size.height + 18;
      if (!visible) {
        if (_userScreenPosition != null) {
          setState(() => _userScreenPosition = null);
        }
        return;
      }
      final current = _userScreenPosition;
      if (current == null) {
        setState(() => _userScreenPosition = measured);
        return;
      }

      final distance = (measured - current).distance;
      // The source coordinate is already GPS-smoothed. Interpolating its
      // SCREEN coordinate made the avatar lag behind a map drag and appear
      // glued to the viewport. Project directly on every camera frame: it now
      // stays on the geographic fix and disappears when that fix is offscreen.
      if (distance >= 0.5 && _userScreenPosition != measured) {
        setState(() => _userScreenPosition = measured);
      }
    } catch (_) {
      // Style/camera initialisation is transient; camera-idle retries it.
    }
  }

  /// Keep campus names visible independently of the basemap's own label
  /// collision index. The labels use a fixed screen-space size and this pass
  /// discards overlaps before Flutter paints them.
  ///
  /// Only one pass runs at a time, and a request that arrives while one is in
  /// flight is coalesced into a single re-run afterwards. Projection is an
  /// async platform-channel round trip, so overlapping passes used to resolve
  /// in whatever order the channel returned them and the LAST one to finish —
  /// not the most recent one — decided where the labels sat. During a quick
  /// pinch that is a pass built against a camera the map has already left.
  Future<void> _updatePlaceLabelOverlays() async {
    if (_labelProjectionRunning) {
      _labelProjectionRequested = true;

      return;
    }
    _labelProjectionRunning = true;
    try {
      await _projectPlaceLabelOverlays();
      while (_labelProjectionRequested && mounted) {
        _labelProjectionRequested = false;
        await _projectPlaceLabelOverlays();
      }
    } finally {
      _labelProjectionRunning = false;
      _labelProjectionRequested = false;
    }
  }

  Future<void> _projectPlaceLabelOverlays() async {
    if (!mounted) return;   // context.size below is invalid after dispose
    final map = _map;
    // `_zoom` only advances on camera idle, so mid-gesture it describes the
    // zoom the user started from. The live camera is what decides whether
    // labels belong on screen at all.
    final zoom = map?.cameraPosition?.zoom ?? _zoom;
    if (map == null || !_styleReady || zoom < 16) {
      if (mounted && _placeLabelOverlays.isNotEmpty) {
        setState(() => _placeLabelOverlays = const []);
      }
      return;
    }

    final size = context.size;
    if (size == null) return;

    final markers = [...widget.markers]
      ..sort((a, b) => (b.emphasized ? 1 : 0).compareTo(a.emphasized ? 1 : 0));
    if (markers.isEmpty) {
      if (mounted && _placeLabelOverlays.isNotEmpty) {
        setState(() => _placeLabelOverlays = const []);
      }
      return;
    }

    // One round trip for every marker, so all of them are projected through
    // the SAME camera transform. Projecting them one await at a time let the
    // camera move between markers, which placed each label against a
    // different zoom level — the labels scattered rather than moved.
    final List<Point<num>> points;
    try {
      points = await map.toScreenLocationBatch(
        [for (final marker in markers) _ll(marker.position)],
      );
    } catch (_) {
      // A style/camera transition can invalidate the projection. The next
      // camera frame or camera-idle callback retries with a settled transform.
      return;
    }
    if (!mounted || points.length != markers.length) return;

    final occupied = <Rect>[];
    final user = _userScreenPosition;
    if (user != null) {
      occupied.add(Rect.fromCenter(center: user, width: 42, height: 42));
    }
    final next = <_MapPlaceLabelOverlay>[];

    for (var i = 0; i < markers.length; i++) {
      final marker = markers[i];
      final center = Offset(points[i].x.toDouble(), points[i].y.toDouble());
      final width = (marker.label.runes.length * 6.2).clamp(48.0, 132.0);
      final rect = Rect.fromLTWH(
        (center.dx - width / 2).clamp(4.0, size.width - width - 4),
        center.dy + 9,
        width,
        20,
      );
      if (rect.bottom > size.height - 4 ||
          occupied.any((other) => other.inflate(3).overlaps(rect))) {
        continue;
      }
      occupied.add(rect);
      next.add(_MapPlaceLabelOverlay(marker: marker, rect: rect));
    }

    setState(() => _placeLabelOverlays = next);
  }

  double _markerHaloRadius(bool emphasized) {
    final t = ((_zoom - 13.4) / 3.6).clamp(0.0, 1.0);
    final eased = t * t;
    final closeRadius = emphasized ? 26.0 : 22.0;
    return 6 + (closeRadius - 6) * eased;
  }
}

class _MapPlaceLabelOverlay {
  final CampusMapMarker marker;
  final Rect rect;

  const _MapPlaceLabelOverlay({required this.marker, required this.rect});
}

class _UserLocationAvatar extends StatelessWidget {
  final String name;
  final String? avatarUrl;

  const _UserLocationAvatar({required this.name, this.avatarUrl});

  @override
  Widget build(BuildContext context) {
    final initial = name.trim().isEmpty ? '?' : name.trim()[0].toUpperCase();
    final fallback = ColoredBox(
      color: ArucadColors.primary,
      child: Center(
        child: Text(
          initial,
          style: const TextStyle(
            color: Colors.white,
            fontSize: 14,
            fontWeight: FontWeight.w800,
          ),
        ),
      ),
    );
    final rawUrl = avatarUrl?.trim();
    final resolved = rawUrl == null || rawUrl.isEmpty
        ? null
        : (MediaUrl.resolve(rawUrl) ?? rawUrl);

    return Semantics(
      image: true,
      label: name.trim().isEmpty ? 'Canlı konumum' : '$name canlı konumu',
      child: Container(
        width: 32,
        height: 32,
        padding: const EdgeInsets.all(2),
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: Colors.white,
          border: Border.all(color: ArucadColors.liveLocation, width: 1.5),
          boxShadow: const [
            BoxShadow(
                color: Colors.black26, blurRadius: 6, offset: Offset(0, 1.5)),
          ],
        ),
        child: ClipOval(
          child: resolved == null
              ? fallback
              : Image.network(
                  resolved,
                  fit: BoxFit.cover,
                  errorBuilder: (_, __, ___) => fallback,
                  loadingBuilder: (context, child, progress) =>
                      progress == null ? child : fallback,
                ),
        ),
      ),
    );
  }
}

CircleOptions _filledCircle({
  required LatLng geometry,
  required double radius,
  required String color,
  double opacity = 1,
  double blur = 0,
  String strokeColor = '#000000',
  double strokeWidth = 0,
  double strokeOpacity = 1,
}) {
  return CircleOptions(
    geometry: geometry,
    circleRadius: radius,
    circleColor: color,
    circleOpacity: opacity,
    circleBlur: blur,
    circleStrokeColor: strokeColor,
    circleStrokeWidth: strokeWidth,
    circleStrokeOpacity: strokeWidth <= 0 ? 0 : strokeOpacity,
    draggable: false,
  );
}

Future<Uint8List> _campusPinPng() async {
  const size = 16;
  final recorder = ui.PictureRecorder();
  final canvas = Canvas(
    recorder,
    Rect.fromLTWH(0, 0, size.toDouble(), size.toDouble()),
  );
  canvas.drawCircle(
    const Offset(size / 2, size / 2),
    4,
    Paint()..color = ArucadColors.primary,
  );
  final image = await recorder.endRecording().toImage(size, size);
  final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
  return bytes!.buffer.asUint8List();
}

/// A 1×1 fully transparent PNG, used as a silent placeholder for POI icon ids
/// the basemap style references but does not ship in its sprite.
Future<Uint8List> _transparentPng() async {
  final recorder = ui.PictureRecorder();
  Canvas(recorder, const Rect.fromLTWH(0, 0, 1, 1)); // draw nothing
  final image = await recorder.endRecording().toImage(1, 1);
  final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
  return bytes!.buffer.asUint8List();
}

/// POI icon ids the OpenFreeMap Liberty style references but whose sprite is
/// frequently missing them, producing benign "Image X could not be loaded"
/// warnings. Registered as transparent placeholders so the lookup succeeds
/// silently. Not exhaustive — a new id simply logs once until added here.
const _missingPoiIconNames = <String>[
  'ferry_terminal',
  'lift_gate',
  'gate',
  'swimming_pool',
  'office',
];
