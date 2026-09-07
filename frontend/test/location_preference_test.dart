import 'package:flutter_test/flutter_test.dart';
import 'package:geolocator/geolocator.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/auth/session_store.dart';
import 'package:arucad_campus_prototype/core/services/location_service.dart';

class _LocationPlatform extends GeolocatorPlatform {
  int requests = 0;

  @override
  Future<bool> isLocationServiceEnabled() async => true;

  @override
  Future<LocationPermission> checkPermission() async => LocationPermission.denied;

  @override
  Future<LocationPermission> requestPermission() async {
    requests++;
    return LocationPermission.denied;
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('a denied location choice survives logout and does not prompt again', () async {
    SharedPreferences.setMockInitialValues({});
    final original = GeolocatorPlatform.instance;
    final platform = _LocationPlatform();
    GeolocatorPlatform.instance = platform;
    addTearDown(() => GeolocatorPlatform.instance = original);
    const location = LocationService();

    expect(await location.checkAndRequestPermission(), LocationAccessStatus.denied);
    expect(platform.requests, 1);
    await SessionStore.save(token: 'test', email: 'test@example.com');
    await SessionStore.clear();
    expect(await const LocationService().isPermissionHandled(), isTrue);
    expect(await const LocationService().checkAndRequestPermission(),
        LocationAccessStatus.denied);
    expect(platform.requests, 1);
  });
}
