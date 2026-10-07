import 'dart:math' as math;

import 'package:arucad_campus_prototype/core/models/geo_point.dart';

enum ActivityType { user, event, place, post }

/// One privacy-aware social signal consumed by the native MapLibre GeoJSON
/// heatmap. Raw coordinates can stay exact inside the app while public map
/// output receives a deterministic radius offset.
class ActivityPoint {
  final String id;
  final GeoPoint position;
  final double weight;
  final DateTime timestamp;
  final ActivityType type;
  final double privacyRadiusMeters;

  const ActivityPoint({
    required this.id,
    required this.position,
    required this.weight,
    required this.timestamp,
    required this.type,
    this.privacyRadiusMeters = 0,
  });

  double effectiveWeight({
    DateTime? now,
    double decayHours = 6,
  }) {
    final age = (now ?? DateTime.now()).difference(timestamp).inSeconds / 3600;
    if (age <= 0) return weight.clamp(0, 1);
    return (weight * math.exp(-age / decayHours)).clamp(0, 1);
  }

  GeoPoint get publicPosition {
    if (privacyRadiusMeters <= 0) return position;
    final random = math.Random(id.hashCode);
    final distance = privacyRadiusMeters * math.sqrt(random.nextDouble());
    final angle = random.nextDouble() * math.pi * 2;
    final latOffset = (distance * math.cos(angle)) / 111320;
    final lngScale = 111320 * math.cos(position.lat * math.pi / 180).abs();
    final lngOffset = lngScale < 1 ? 0 : distance * math.sin(angle) / lngScale;
    return GeoPoint(position.lat + latOffset, position.lng + lngOffset);
  }

  Map<String, dynamic> toGeoJsonFeature({DateTime? now}) {
    final point = publicPosition;
    return {
      'type': 'Feature',
      'id': id,
      'properties': {
        'weight': effectiveWeight(now: now),
        'timestamp': timestamp.toUtc().toIso8601String(),
        'activityType': type.name,
      },
      'geometry': {
        'type': 'Point',
        'coordinates': [point.lng, point.lat],
      },
    };
  }
}
