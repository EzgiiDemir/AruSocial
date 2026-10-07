import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/models/geo_point.dart';

/// Travel modes for in-app campus navigation.
/// Walking prefers pedestrian routing; car/bus use the road graph. Every mode
/// keeps an explicit straight-line estimate only as an offline fallback.
enum TravelMode { walking, driving, transit }

extension TravelModeInfo on TravelMode {
  bool get usesRoadNetwork => this != TravelMode.walking;

  bool get usesBirdsEyeLine => this == TravelMode.walking;

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

/// One turn on a route, as the routing provider described it.
///
/// [type] and [modifier] are OSRM's own maneuver fields, which is why the
/// instruction can be phrased in the student's language rather than shown
/// as the provider's English ("left turn"). [instruction] is that raw
/// string and is only used when a maneuver arrives that this does not
/// recognise — an honest passthrough beats an invented turn.
class RouteStep {
  final String instruction;
  final String type;
  final String modifier;

  /// The street or path the step follows, when the provider names one.
  final String roadName;

  final double distanceMeters;
  final double durationSeconds;

  const RouteStep({
    required this.instruction,
    this.type = '',
    this.modifier = '',
    this.roadName = '',
    this.distanceMeters = 0,
    this.durationSeconds = 0,
  });

  factory RouteStep.fromJson(Map<String, dynamic> json) => RouteStep(
        instruction: json['instruction'] as String? ?? '',
        type: json['type'] as String? ?? '',
        modifier: json['modifier'] as String? ?? '',
        roadName: json['name'] as String? ?? '',
        distanceMeters: (json['distanceMeters'] as num?)?.toDouble() ?? 0,
        durationSeconds: (json['durationSeconds'] as num?)?.toDouble() ?? 0,
      );

  /// The maneuver as one lowercase key, whichever fields the provider
  /// filled in — older payloads carried only [instruction].
  String get _key {
    final combined = '$modifier $type'.trim();
    return (combined.isEmpty ? instruction : combined).toLowerCase();
  }

  IconData get icon {
    final key = _key;
    if (key.contains('arrive')) return Icons.place_outlined;
    if (key.contains('depart')) return Icons.trip_origin;
    if (key.contains('roundabout') || key.contains('rotary')) {
      return Icons.roundabout_left;
    }
    if (key.contains('uturn')) return Icons.u_turn_left;
    if (key.contains('sharp left')) return Icons.turn_sharp_left;
    if (key.contains('sharp right')) return Icons.turn_sharp_right;
    if (key.contains('slight left')) return Icons.turn_slight_left;
    if (key.contains('slight right')) return Icons.turn_slight_right;
    if (key.contains('left')) return Icons.turn_left;
    if (key.contains('right')) return Icons.turn_right;
    return Icons.straight;
  }

  /// A localized instruction, or the provider's own wording when the
  /// maneuver is one we have no phrasing for.
  String labelFor(String Function(String key) t) {
    final key = _key;
    final phrase = switch (key) {
      _ when key.contains('arrive') => t('nav_step_arrive'),
      _ when key.contains('depart') => t('nav_step_depart'),
      _ when key.contains('roundabout') || key.contains('rotary') =>
        t('nav_step_roundabout'),
      _ when key.contains('uturn') => t('nav_step_uturn'),
      _ when key.contains('sharp left') => t('nav_step_sharp_left'),
      _ when key.contains('sharp right') => t('nav_step_sharp_right'),
      _ when key.contains('slight left') => t('nav_step_slight_left'),
      _ when key.contains('slight right') => t('nav_step_slight_right'),
      _ when key.contains('left') => t('nav_step_left'),
      _ when key.contains('right') => t('nav_step_right'),
      _ when key.contains('straight') || key.contains('continue') =>
        t('nav_step_straight'),
      _ => instruction,
    };
    final road = roadName.trim();
    if (road.isEmpty || key.contains('arrive')) return phrase;
    return '$phrase · $road';
  }

  String get distanceLabel => distanceMeters >= 1000
      ? '${(distanceMeters / 1000).toStringAsFixed(1)} km'
      : '${distanceMeters.round()} m';
}

/// A route between two points, drawn entirely on our own map.
class RouteResult {
  final List<GeoPoint> points;
  final String distanceText;
  final String durationText;
  final double distanceMeters;
  final double durationSeconds;

  /// Provider turn instructions when a real routing backend answered;
  /// empty for the honest straight-line fallback.
  final List<RouteStep> steps;

  /// True when points came from `POST /routing/directions`.
  final bool fromProvider;

  const RouteResult({
    required this.points,
    required this.distanceText,
    required this.durationText,
    this.distanceMeters = 0,
    this.durationSeconds = 0,
    this.steps = const [],
    this.fromProvider = false,
  });
}

/// Parsed `POST /routing/directions` payload (OSRM-compatible).
class WalkingRoute {
  final List<GeoPoint> points;
  final double distanceMeters;
  final double durationSeconds;
  final List<RouteStep> steps;
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
    final steps = <RouteStep>[];
    for (final step in rawSteps) {
      if (step is Map<String, dynamic>) {
        steps.add(RouteStep.fromJson(step));
      } else if (step is Map) {
        steps.add(RouteStep.fromJson(Map<String, dynamic>.from(step)));
      } else if (step is String) {
        // A provider that only sends prose still gets shown; it simply
        // cannot be translated or given a turn arrow.
        steps.add(RouteStep(instruction: step));
      }
    }
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
      distanceMeters: distanceMeters,
      durationSeconds: durationSeconds,
      steps: steps,
      fromProvider: true,
    );
  }
}
