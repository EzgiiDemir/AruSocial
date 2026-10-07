import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:arucad_campus_prototype/features/map/navigation_engine.dart';

void main() {
  test('off-route requires consecutive samples and obeys cooldown', () {
    final engine = NavigationEngine(
      offRouteThresholdMeters: 25,
      offRouteConfirmations: 3,
      rerouteCooldown: const Duration(seconds: 20),
    )..setRoute(const [
        GeoPoint(35.3370, 33.3210),
        GeoPoint(35.3380, 33.3210),
      ]);
    const offRoute = GeoPoint(35.3375, 33.3220);
    final now = DateTime.utc(2026, 9, 24, 12);

    expect(engine.shouldReroute(offRoute, now: now), isFalse);
    expect(engine.shouldReroute(offRoute, now: now), isFalse);
    expect(engine.shouldReroute(offRoute, now: now), isTrue);
    expect(engine.shouldReroute(offRoute, now: now), isFalse);
    expect(engine.shouldReroute(offRoute, now: now), isFalse);
    expect(engine.shouldReroute(offRoute, now: now), isFalse);
  });

  test('an on-route point resets the consecutive off-route counter', () {
    final engine = NavigationEngine(offRouteConfirmations: 2)
      ..setRoute(const [
        GeoPoint(35.3370, 33.3210),
        GeoPoint(35.3380, 33.3210),
      ]);

    expect(engine.shouldReroute(const GeoPoint(35.3375, 33.3220)), isFalse);
    expect(engine.shouldReroute(const GeoPoint(35.3375, 33.3210)), isFalse);
    expect(engine.shouldReroute(const GeoPoint(35.3375, 33.3220)), isFalse);
  });
}
