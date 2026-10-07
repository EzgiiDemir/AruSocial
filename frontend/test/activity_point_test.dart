import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:arucad_campus_prototype/features/map/activity_point.dart';

void main() {
  test('activity weight decays with age', () {
    final now = DateTime.utc(2026, 9, 24, 12);
    final point = ActivityPoint(
      id: 'user-1',
      position: const GeoPoint(35.337, 33.321),
      weight: 1,
      timestamp: now.subtract(const Duration(hours: 6)),
      type: ActivityType.user,
    );

    expect(point.effectiveWeight(now: now), closeTo(0.3679, 0.001));
  });

  test('privacy jitter is deterministic and remains inside its radius', () {
    final point = ActivityPoint(
      id: 'private-user',
      position: const GeoPoint(35.337, 33.321),
      weight: 0.8,
      timestamp: DateTime.utc(2026, 9, 24),
      type: ActivityType.user,
      privacyRadiusMeters: 50,
    );

    expect(point.publicPosition, point.publicPosition);
    expect((point.publicPosition.lat - point.position.lat).abs(), lessThan(0.001));
    expect((point.publicPosition.lng - point.position.lng).abs(), lessThan(0.001));
  });

  test('GeoJSON uses longitude then latitude and carries social metadata', () {
    final point = ActivityPoint(
      id: 'event-1',
      position: const GeoPoint(35.337, 33.321),
      weight: 0.7,
      timestamp: DateTime.utc(2026, 9, 24),
      type: ActivityType.event,
    );
    final feature = point.toGeoJsonFeature(now: DateTime.utc(2026, 9, 24));
    final geometry = feature['geometry'] as Map<String, dynamic>;
    final properties = feature['properties'] as Map<String, dynamic>;

    expect(geometry['coordinates'], [33.321, 35.337]);
    expect(properties['activityType'], 'event');
    expect(properties['weight'], 0.7);
  });
}
