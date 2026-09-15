import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:maplibre_gl/maplibre_gl.dart';

import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

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
const _openFreeMapTextFont = ['Noto Sans Regular'];

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
  final GeoPoint? focusPoint;
  final List<CampusMapMarker> markers;
  final List<CampusPulseZone> pulseZones;
  final List<CampusMapContextDot> contextDots;
  final List<GeoPoint>? routePoints;
  final bool routeDashed;
  final Color routeColor;

  /// Device location rendered by our own annotation layer. Circle radii are
  /// screen pixels, so this stays a normal-size blue puck at every zoom.
  final GeoPoint? userLocation;
  final bool showUserLocation;
  final CampusMapController? controller;

  const CampusMapView({
    super.key,
    required this.extentPoints,
    this.focusPoint,
    this.markers = const [],
    this.pulseZones = const [],
    this.contextDots = const [],
    this.routePoints,
    this.routeDashed = false,
    this.routeColor = ArucadColors.blue,
    this.userLocation,
    this.showUserLocation = false,
    this.controller,
  });

  @override
  State<CampusMapView> createState() => _CampusMapViewState();
}

class _CampusMapViewState extends State<CampusMapView> {
  MapLibreMapController? _map;
  bool _styleReady = false;
  bool _pinReady = false;
  bool _symbolFontReady = false;
  double _zoom = 16.4;
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
            oldWidget.routePoints != widget.routePoints ||
            oldWidget.userLocation != widget.userLocation ||
            oldWidget.focusPoint != widget.focusPoint)) {
      _syncAnnotations();
    }
  }

  @override
  void dispose() {
    final map = _map;
    if (map != null) {
      widget.controller?._detach(map);
      map.onSymbolTapped.remove(_handleSymbolTap);
      map.onCircleTapped.remove(_handleCircleTap);
    }
    super.dispose();
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
    _symbolFontReady = false;
    final map = _map;
    if (map != null) {
      await _ensureCampusPin(map);
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

  Future<void> _syncAnnotations() async {
    final map = _map;
    if (map == null) return;
    await map.clearCircles();
    await map.clearSymbols();
    await map.clearLines();
    _markerTaps.clear();

    await _prepareWebSymbolFont(map);

    for (final dot in widget.contextDots) {
      await map.addCircle(_filledCircle(
        geometry: _ll(dot.position),
        radius: 3.5,
        color: _hex(dot.color),
        strokeColor: '#ffffff',
        strokeWidth: 1.4,
      ));
    }

    for (final zone in widget.pulseZones) {
      final scaled = _pulseRadiusPx(zone.baseRadiusMeters);
      // Two rings, same recipe for red / yellow / green. Extra rings used
      // to stack into one blob as soon as the camera pulled back.
      await map.addCircle(_filledCircle(
        geometry: _ll(zone.center),
        radius: scaled,
        color: _hex(zone.color),
        opacity: (0.38 * zone.intensity).clamp(0.12, 0.45),
        blur: 0.55,
      ));
      if (_zoom >= 15.2) {
        await map.addCircle(_filledCircle(
          geometry: _ll(zone.center),
          radius: scaled * 1.45,
          color: _hex(zone.color),
          opacity: (0.16 * zone.intensity).clamp(0.06, 0.2),
          blur: 0.85,
        ));
      }
    }

    final userLocation = widget.showUserLocation ? widget.userLocation : null;
    if (userLocation != null) {
      // Cross-platform Google Maps–style location puck: a soft blue accuracy
      // halo and a compact white-rimmed core. Annotation radii are pixels,
      // keeping the symbol stable while users zoom in or out.
      await map.addCircle(_filledCircle(
        geometry: _ll(userLocation),
        radius: 19,
        color: _hex(ArucadColors.blue),
        opacity: 0.22,
        blur: 0.78,
      ));
      await map.addCircle(_filledCircle(
        geometry: _ll(userLocation),
        radius: 7,
        color: _hex(ArucadColors.blue),
        opacity: 1,
        strokeColor: '#ffffff',
        strokeWidth: 2,
      ));
    }

    for (final marker in widget.markers) {
      // A soft heat halo communicates density at a glance without making the
      // POI itself a sharp target-looking dot. The coloured core stays clear
      // and tappable over the map labels.
      await map.addCircle(
        _filledCircle(
          geometry: _ll(marker.position),
          radius: marker.emphasized ? 22 : 18,
          color: _hex(marker.color),
          opacity: 0.22,
          blur: 0.82,
        ),
        {'markerId': marker.id},
      );
      await map.addCircle(
        _filledCircle(
          geometry: _ll(marker.position),
          radius: marker.emphasized ? 9.5 : 7,
          color: _hex(marker.color),
          opacity: 0.98,
        ),
        {'markerId': marker.id},
      );
      await map.addSymbol(
        _filledLabel(
          geometry: _ll(marker.position),
          text: marker.label,
          size: marker.emphasized ? 14 : 12,
          haloWidth: marker.emphasized ? 2.4 : 1.8,
        ),
        {'markerId': marker.id},
      );
      if (marker.onTap != null) _markerTaps[marker.id] = marker.onTap!;
    }

    final route = widget.routePoints;
    if (route != null && route.length >= 2) {
      final llRoute = route.map(_ll).toList();
      if (!widget.routeDashed) {
        // Google Maps–style route: white casing + coloured stroke on top.
        await map.addLine(_filledLine(
          geometry: llRoute,
          color: '#ffffff',
          width: 9,
        ));
        await map.addLine(_filledLine(
          geometry: llRoute,
          color: _hex(widget.routeColor),
          width: 6,
        ));
      } else {
        for (var i = 0; i < route.length - 1; i++) {
          await _addDashedSegment(map, route[i], route[i + 1]);
        }
      }
    }
  }

  /// `SymbolOptions.fontNames` is ignored by maplibre_gl on web. Without a
  /// layer-level override its generated symbol layer falls back to
  /// `Open Sans Regular, Arial Unicode MS Regular`, a stack OpenFreeMap does
  /// not host. Initialise the manager with an empty label (so no glyph is
  /// requested), then set the real layer font before adding campus labels.
  Future<void> _prepareWebSymbolFont(MapLibreMapController map) async {
    if (_symbolFontReady || widget.markers.isEmpty) return;
    try {
      if (map.symbolManager == null) {
        await map.addSymbol(SymbolOptions(
          geometry: _ll(widget.markers.first.position),
          iconImage: 'campus-pin',
          iconOpacity: 0,
          textField: '',
        ));
      }
      final manager = map.symbolManager;
      if (manager == null) return;
      for (final layerId in manager.layerIds) {
        await map.setLayerProperties(
          layerId,
          const SymbolLayerProperties(textFont: _openFreeMapTextFont),
        );
      }
      await map.clearSymbols();
      _symbolFontReady = true;
    } catch (_) {
      // Map labels remain non-fatal. A later annotation sync retries after
      // the platform-specific symbol manager has completed initialisation.
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
      final segPoints = [
        GeoPoint(a.lat + (b.lat - a.lat) * t, a.lng + (b.lng - a.lng) * t),
        GeoPoint(
            a.lat + (b.lat - a.lat) * segEnd, a.lng + (b.lng - a.lng) * segEnd),
      ].map(_ll).toList();
      lines.add(_filledLine(
        geometry: segPoints,
        color: _hex(widget.routeColor),
        width: 4.5,
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
      // The platform puck differs between Android/iOS/web. We render one
      // shared annotation above instead, so the symbol is identical and
      // never duplicates on native devices.
      myLocationEnabled: false,
      myLocationTrackingMode: MyLocationTrackingMode.none,
      trackCameraPosition: true,
      compassEnabled: false,
      rotateGesturesEnabled: true,
      onMapCreated: (controller) {
        _map = controller;
        widget.controller?._attach(controller);
        controller.onSymbolTapped.add(_handleSymbolTap);
        controller.onCircleTapped.add(_handleCircleTap);
      },
      onCameraIdle: _onCameraIdle,
      onStyleLoadedCallback: () {
        _onStyleLoaded();
        WidgetsBinding.instance.addPostFrameCallback((_) async {
          if (!mounted) return;
          await widget.controller?.fitPoints(extent, padding: 48);
          final focus = widget.focusPoint;
          if (focus != null) {
            await widget.controller?.centerOn(focus, zoom: 18.2);
          }
        });
      },
    );
  }

  void _onCameraIdle() {
    final z = _map?.cameraPosition?.zoom;
    if (z == null || !_styleReady) return;
    if ((z - _zoom).abs() < 0.3) return;
    _zoom = z;
    _syncAnnotations();
  }

  /// Pixel radius shrinks as the camera pulls back so three campus glows
  /// do not merge into one disc over Girne.
  double _pulseRadiusPx(double base) {
    final t = ((_zoom - 13.4) / 3.6).clamp(0.0, 1.0);
    final eased = t * t;
    return 2.4 + (base.clamp(6.0, 10.0) - 2.4) * eased;
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

/// Every SymbolManager paint/layout field is `['get', property]`. Unset
/// GeoJSON keys become null and MapLibre logs type errors on hover/render.
SymbolOptions _filledLabel({
  required LatLng geometry,
  required String text,
  required double size,
  required double haloWidth,
}) {
  return SymbolOptions(
    geometry: geometry,
    iconImage: 'campus-pin',
    iconSize: 1,
    iconRotate: 0,
    iconOffset: Offset.zero,
    iconAnchor: 'center',
    iconOpacity: 0,
    iconColor: '#000000',
    iconHaloColor: '#000000',
    iconHaloWidth: 0,
    iconHaloBlur: 0,
    fontNames: _openFreeMapTextFont,
    textField: text,
    textSize: size,
    textMaxWidth: 9,
    textLetterSpacing: 0,
    textJustify: 'center',
    textAnchor: 'top',
    textRotate: 0,
    textTransform: 'none',
    textOffset: const Offset(0, 1.15),
    textOpacity: 1,
    textColor: '#1a1a1a',
    textHaloColor: '#ffffff',
    textHaloWidth: haloWidth,
    textHaloBlur: 0,
    zIndex: 0,
    draggable: false,
  );
}

LineOptions _filledLine({
  required List<LatLng> geometry,
  required String color,
  required double width,
}) {
  return LineOptions(
    geometry: geometry,
    lineJoin: 'round',
    lineOpacity: 1,
    lineColor: color,
    lineWidth: width,
    lineGapWidth: 0,
    lineOffset: 0,
    lineBlur: 0,
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
