import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/models/geo_point.dart';

/// Real travel modes the app lets a student pick between — [birdseye] is a
/// client-side-only straight-line estimate. This prototype has no real
/// road-network/routing data or external directions API (see
/// `docs/PUBLISH_READINESS.md`), so every mode below produces the same
/// honest straight-line path on our own map, paced differently per mode.
enum TravelMode { walking, driving, transit, birdseye }

extension TravelModeInfo on TravelMode {
  String get label => switch (this) {
        TravelMode.walking => 'Yürüyerek',
        TravelMode.driving => 'Araba',
        TravelMode.transit => 'Otobüs',
        TravelMode.birdseye => 'Kuş Bakışı',
      };
  IconData get icon => switch (this) {
        TravelMode.walking => Icons.directions_walk,
        TravelMode.driving => Icons.directions_car_outlined,
        TravelMode.transit => Icons.directions_bus_outlined,
        TravelMode.birdseye => Icons.explore_outlined,
      };
  /// Rough campus-scale pace used for the straight-line estimate — meters
  /// per minute.
  double get fallbackMetersPerMinute => switch (this) {
        TravelMode.walking => 80,
        TravelMode.driving => 350,
        TravelMode.transit => 220,
        TravelMode.birdseye => 80,
      };
}

/// A route between two points, drawn entirely on our own map.
class RouteResult {
  final List<GeoPoint> points;
  final String distanceText;
  final String durationText;

  const RouteResult({
    required this.points,
    required this.distanceText,
    required this.durationText,
  });
}
