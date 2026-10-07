import 'dart:collection';
import 'dart:math' as math;

import 'package:geolocator/geolocator.dart';

import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:arucad_campus_prototype/core/services/directions_result.dart';

enum NavigationCameraMode { free, follow, navigation, overview }

class LocationSample {
  final GeoPoint point;
  final double accuracy;
  final double speed;
  final double heading;
  final DateTime timestamp;

  const LocationSample({
    required this.point,
    required this.accuracy,
    required this.speed,
    required this.heading,
    required this.timestamp,
  });

  factory LocationSample.fromPosition(Position position) => LocationSample(
        point: GeoPoint(position.latitude, position.longitude),
        accuracy: position.accuracy,
        speed: position.speed,
        heading: position.heading,
        timestamp: position.timestamp,
      );

  Map<String, dynamic> toJson() => {
        'lat': point.lat,
        'lng': point.lng,
        'accuracy': accuracy,
        'speed': speed,
        'heading': heading,
        'timestamp': timestamp.toUtc().millisecondsSinceEpoch ~/ 1000,
      };
}

class MatchedLocation {
  final GeoPoint point;
  final double confidence;
  final List<GeoPoint> trace;

  const MatchedLocation({
    required this.point,
    required this.confidence,
    this.trace = const [],
  });

  factory MatchedLocation.fromJson(Map<String, dynamic> json) {
    final rawTrace = json['points'] as List<dynamic>? ?? const [];
    return MatchedLocation(
      point: GeoPoint(
        (json['lat'] as num).toDouble(),
        (json['lng'] as num).toDouble(),
      ),
      confidence: (json['confidence'] as num?)?.toDouble() ?? 0,
      trace: [
        for (final raw in rawTrace)
          if (raw is Map)
            GeoPoint(
              (raw['lat'] as num).toDouble(),
              (raw['lng'] as num).toDouble(),
            ),
      ],
    );
  }
}

abstract class RouteMatcher {
  Future<MatchedLocation?> match(
    List<LocationSample> samples,
    TravelMode mode,
  );
}

class OsrmRouteMatcher implements RouteMatcher {
  final Future<MatchedLocation?> Function(
    List<LocationSample> samples,
    TravelMode mode,
  ) request;

  const OsrmRouteMatcher(this.request);

  @override
  Future<MatchedLocation?> match(
          List<LocationSample> samples, TravelMode mode) =>
      request(samples, mode);
}

class NavigationEngine {
  final Queue<LocationSample> _samples = Queue<LocationSample>();
  GeoPoint? _smoothed;
  List<GeoPoint> _route = const [];
  int _offRouteSamples = 0;
  DateTime? _lastRerouteAt;

  NavigationCameraMode cameraMode = NavigationCameraMode.follow;
  double maxAcceptedAccuracyMeters;
  double offRouteThresholdMeters;
  int offRouteConfirmations;
  Duration rerouteCooldown;

  NavigationEngine({
    this.maxAcceptedAccuracyMeters = 45,
    this.offRouteThresholdMeters = 35,
    this.offRouteConfirmations = 3,
    this.rerouteCooldown = const Duration(seconds: 12),
  });

  List<LocationSample> get samples => List.unmodifiable(_samples);

  LocationSample? accept(Position position) {
    final sample = LocationSample.fromPosition(position);
    if (!sample.accuracy.isFinite ||
        sample.accuracy <= 0 ||
        sample.accuracy > maxAcceptedAccuracyMeters) {
      return null;
    }
    if (_samples.isNotEmpty &&
        !sample.timestamp.isAfter(_samples.last.timestamp)) {
      return null;
    }
    _samples.add(sample);
    while (_samples.length > 8) {
      _samples.removeFirst();
    }

    final current = _smoothed;
    final alpha = sample.speed > 6 ? 0.62 : 0.34;
    _smoothed = current == null
        ? sample.point
        : GeoPoint(
            current.lat + (sample.point.lat - current.lat) * alpha,
            current.lng + (sample.point.lng - current.lng) * alpha,
          );
    return LocationSample(
      point: _smoothed!,
      accuracy: sample.accuracy,
      speed: sample.speed,
      heading: sample.heading,
      timestamp: sample.timestamp,
    );
  }

  void setRoute(List<GeoPoint> route) {
    _route = List.unmodifiable(route);
    _offRouteSamples = 0;
  }

  bool shouldReroute(GeoPoint point, {DateTime? now}) {
    if (_route.length < 2) return false;
    final distance = _distanceToPolyline(point, _route);
    _offRouteSamples = distance > offRouteThresholdMeters
        ? _offRouteSamples + 1
        : 0;
    if (_offRouteSamples < offRouteConfirmations) return false;

    final clock = now ?? DateTime.now();
    if (_lastRerouteAt != null &&
        clock.difference(_lastRerouteAt!) < rerouteCooldown) {
      return false;
    }
    _lastRerouteAt = clock;
    _offRouteSamples = 0;
    return true;
  }

  double _distanceToPolyline(GeoPoint point, List<GeoPoint> line) {
    var best = double.infinity;
    for (var i = 0; i < line.length - 1; i++) {
      best = math.min(best, _distanceToSegment(point, line[i], line[i + 1]));
    }
    return best;
  }

  double _distanceToSegment(GeoPoint p, GeoPoint a, GeoPoint b) {
    final latScale = 111320.0;
    final lngScale = latScale * math.cos(p.lat * math.pi / 180).abs();
    final ax = (a.lng - p.lng) * lngScale;
    final ay = (a.lat - p.lat) * latScale;
    final bx = (b.lng - p.lng) * lngScale;
    final by = (b.lat - p.lat) * latScale;
    final dx = bx - ax;
    final dy = by - ay;
    final length2 = dx * dx + dy * dy;
    if (length2 == 0) return math.sqrt(ax * ax + ay * ay);
    final t = (-(ax * dx + ay * dy) / length2).clamp(0.0, 1.0);
    final x = ax + dx * t;
    final y = ay + dy * t;
    return math.sqrt(x * x + y * y);
  }
}
