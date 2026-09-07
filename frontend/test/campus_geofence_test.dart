import 'package:arucad_campus_prototype/core/config/campus_geofence.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('main campus garden is inside the envelope', () {
    expect(CampusGeofence.contains(35.33715, 33.32135), isTrue);
  });

  test('a Kyrenia street well outside the envelope is rejected', () {
    expect(CampusGeofence.contains(35.35, 33.35), isFalse);
  });
}
