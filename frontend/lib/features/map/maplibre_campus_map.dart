import 'package:flutter/material.dart';
import 'package:maplibre_gl/maplibre_gl.dart';

import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// Our map rendering surface — real OpenStreetMap vector tiles served by
/// OpenFreeMap (free, keyless, no account — see openfreemap.org), rendered
/// through MapLibre GL. This replaced an earlier hand-drawn Canvas map:
/// same honesty goal (no Google Maps API/key/billing), but now with a
/// genuine street/building map instead of an illustration. Attribution is
/// shown automatically by MapLibre's own attribution control, as required
/// by OpenFreeMap's terms.
const _openFreeMapStyle = 'https://tiles.openfreemap.org/styles/liberty';

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

  const CampusMapMarker({
    required this.id,
    required this.position,
    required this.label,
    required this.color,
    this.onTap,
    this.emphasized = false,
  });
}

/// Three concentric, fading circles per place — the real "Campus Pulse"
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
  final List<CampusMapMarker> markers;
  final List<CampusPulseZone> pulseZones;
  final List<CampusMapContextDot> contextDots;
  final List<GeoPoint>? routePoints;
  final bool routeDashed;
  final Color routeColor;
  final bool showUserLocation;
  final CampusMapController? controller;
  final ValueChanged<double>? onZoomChanged;

  const CampusMapView({
    super.key,
    required this.extentPoints,
    this.markers = const [],
    this.pulseZones = const [],
    this.contextDots = const [],
    this.routePoints,
    this.routeDashed = false,
    this.routeColor = ArucadColors.blue,
    this.showUserLocation = false,
    this.controller,
    this.onZoomChanged,
  });

  @override
  State<CampusMapView> createState() => _CampusMapViewState();
}

class _CampusMapViewState extends State<CampusMapView> {
  MapLibreMapController? _map;
  bool _styleReady = false;
  final Map<String, VoidCallback> _markerTaps = {};

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
            oldWidget.contextDots != widget.contextDots ||
            oldWidget.routePoints != widget.routePoints)) {
      _syncAnnotations();
    }
  }

  @override
  void dispose() {
    final map = _map;
    if (map != null) {
      widget.controller?._detach(map);
      map.onSymbolTapped.remove(_handleSymbolTap);
    }
    super.dispose();
  }

  void _handleSymbolTap(Symbol symbol) {
    final id = symbol.data?['markerId'] as String?;
    if (id != null) _markerTaps[id]?.call();
  }

  Future<void> _onStyleLoaded() async {
    _styleReady = true;
    await _syncAnnotations();
  }

  Future<void> _syncAnnotations() async {
    final map = _map;
    if (map == null) return;
    await map.clearCircles();
    await map.clearSymbols();
    await map.clearLines();
    _markerTaps.clear();

    for (final dot in widget.contextDots) {
      await map.addCircle(CircleOptions(
        geometry: _ll(dot.position),
        circleRadius: 3.5,
        circleColor: _hex(dot.color),
        circleStrokeColor: '#ffffff',
        circleStrokeWidth: 1.4,
      ));
    }

    for (final zone in widget.pulseZones) {
      final baseRadius = zone.baseRadiusMeters.clamp(4.0, 22.0);
      const rings = 3;
      for (var ring = 0; ring < rings; ring++) {
        final t = ring / rings;
        await map.addCircle(CircleOptions(
          geometry: _ll(zone.center),
          circleRadius: baseRadius * (1 + t * 0.8),
          circleColor: _hex(zone.color),
          circleOpacity: 0.16 * (1 - t) * zone.intensity + 0.03,
        ));
      }
    }

    for (final marker in widget.markers) {
      final symbol = await map.addSymbol(
        SymbolOptions(
          geometry: _ll(marker.position),
          textField: marker.label,
          textColor: '#ffffff',
          textHaloColor: _hex(marker.color),
          textHaloWidth: marker.emphasized ? 3.5 : 2.2,
          textSize: marker.emphasized ? 14 : 12,
        ),
        {'markerId': marker.id},
      );
      if (marker.onTap != null) _markerTaps[marker.id] = marker.onTap!;
      // ignore: unnecessary_statements
      symbol;
    }

    final route = widget.routePoints;
    if (route != null && route.length >= 2) {
      if (!widget.routeDashed) {
        await map.addLine(LineOptions(
          geometry: route.map(_ll).toList(),
          lineColor: _hex(widget.routeColor),
          lineWidth: 5.5,
        ));
      } else {
        for (var i = 0; i < route.length - 1; i++) {
          await _addDashedSegment(map, route[i], route[i + 1]);
        }
      }
    }
  }

  Future<void> _addDashedSegment(
      MapLibreMapController map, GeoPoint a, GeoPoint b) async {
    // MapLibre's simplified LineOptions annotation API has no dash-array
    // property, so a dashed look is built honestly from real short segments
    // rather than faked with a texture.
    const dashFraction = 0.045;
    const gapFraction = 0.03;
    var t = 0.0;
    final lines = <LineOptions>[];
    while (t < 1.0) {
      final segEnd = (t + dashFraction).clamp(0.0, 1.0);
      lines.add(LineOptions(
        geometry: [
          GeoPoint(a.lat + (b.lat - a.lat) * t, a.lng + (b.lng - a.lng) * t),
          GeoPoint(a.lat + (b.lat - a.lat) * segEnd, a.lng + (b.lng - a.lng) * segEnd),
        ].map(_ll).toList(),
        lineColor: _hex(widget.routeColor),
        lineWidth: 4.5,
      ));
      t += dashFraction + gapFraction;
    }
    if (lines.isNotEmpty) await map.addLines(lines);
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

    return MapLibreMap(
      styleString: _openFreeMapStyle,
      initialCameraPosition: CameraPosition(target: _ll(center), zoom: 16.4),
      myLocationEnabled: widget.showUserLocation,
      myLocationTrackingMode: MyLocationTrackingMode.none,
      compassEnabled: false,
      rotateGesturesEnabled: true,
      onMapCreated: (controller) {
        _map = controller;
        widget.controller?._attach(controller);
        controller.onSymbolTapped.add(_handleSymbolTap);
      },
      onCameraIdle: () {
        final zoom = _map?.cameraPosition?.zoom;
        if (zoom != null) widget.onZoomChanged?.call(zoom);
      },
      onStyleLoadedCallback: () {
        _onStyleLoaded();
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted) widget.controller?.fitPoints(extent, padding: 48);
        });
      },
    );
  }
}
