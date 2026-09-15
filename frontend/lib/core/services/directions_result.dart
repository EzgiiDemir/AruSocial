import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/models/geo_point.dart';

/// Travel modes for in-app campus navigation.
/// Walking prefers pedestrian routing; car/bus use the road graph. Every mode
/// keeps an explicit straight-line estimate only as an offline fallback.
enum TravelMode { walking, driving, transit }

extension TravelModeInfo on TravelMode {
  String get label => switch (this) {
        TravelMode.walking => 'Yürüyerek',
        TravelMode.driving => 'Araba',
        TravelMode.transit => 'Otobüs',
      };

  String labelFor(String Function(String key) t) => switch (this) {
        TravelMode.walking => t('nav_mode_walking'),
        TravelMode.driving => t('nav_mode_driving'),
        TravelMode.transit => t('nav_mode_transit'),
      };

  IconData get icon => switch (this) {
        TravelMode.walking => Icons.directions_walk,
        TravelMode.driving => Icons.directions_car_outlined,
        TravelMode.transit => Icons.directions_bus_outlined,
      };

  /// Rough campus-scale pace used for the straight-line estimate — meters
  /// per minute.
  double get fallbackMetersPerMinute => switch (this) {
        TravelMode.walking => 80,
        TravelMode.driving => 350,
        TravelMode.transit => 220,
      };
}

/// A route between two points, drawn entirely on our own map.
class RouteResult {
  final List<GeoPoint> points;
  final String distanceText;
  final String durationText;

  /// Provider turn instructions when a real routing backend answered;
  /// empty for the honest straight-line fallback.
  final List<String> steps;

  /// True when points came from `POST /routing/directions`.
  final bool fromProvider;

  const RouteResult({
    required this.points,
    required this.distanceText,
    required this.durationText,
    this.steps = const [],
    this.fromProvider = false,
  });
}

/// Parsed `POST /routing/directions` payload (OSRM-compatible).
class WalkingRoute {
  final List<GeoPoint> points;
  final double distanceMeters;
  final double durationSeconds;
  final List<String> steps;
  final String provider;

  const WalkingRoute({
    required this.points,
    required this.distanceMeters,
    required this.durationSeconds,
    this.steps = const [],
    this.provider = 'osrm',
  });

  factory WalkingRoute.fromJson(Map<String, dynamic> json) {
    final rawPoints = json['points'] as List<dynamic>? ?? const [];
    final points = <GeoPoint>[];
    for (final p in rawPoints) {
      if (p is List && p.length >= 2) {
        points
            .add(GeoPoint((p[0] as num).toDouble(), (p[1] as num).toDouble()));
      } else if (p is Map) {
        points.add(GeoPoint(
          (p['lat'] as num).toDouble(),
          (p['lng'] as num).toDouble(),
        ));
      }
    }
    final rawSteps = json['steps'] as List<dynamic>? ?? const [];
    final steps = rawSteps.map((s) {
      if (s is String) return s;
      if (s is Map && s['instruction'] != null) return '${s['instruction']}';
      return '$s';
    }).toList();
    return WalkingRoute(
      points: points,
      distanceMeters: (json['distanceMeters'] as num?)?.toDouble() ?? 0,
      durationSeconds: (json['durationSeconds'] as num?)?.toDouble() ?? 0,
      steps: steps,
      provider: json['provider'] as String? ?? 'osrm',
    );
  }

  RouteResult toRouteResult({String Function(String key)? t}) {
    final meters = distanceMeters.round();
    final minutes = (durationSeconds / 60).ceil().clamp(1, 9999);
    final minLabel = t?.call('nav_min') ?? 'dk';
    return RouteResult(
      points: points,
      distanceText: meters >= 1000
          ? '${(meters / 1000).toStringAsFixed(1)} km'
          : '$meters m',
      durationText: '~$minutes $minLabel',
      steps: steps,
      fromProvider: true,
    );
  }
}
