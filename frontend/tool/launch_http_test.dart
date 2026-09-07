import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/network/auth_token_adapter.dart';
import 'package:arucad_campus_prototype/core/services/rest_campus_repository.dart';

class _Token extends AuthTokenAdapter {
  final String value;
  _Token(this.value);
  @override
  Future<String?> getAccessToken() async => value;
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  // This isolated integration fixture intentionally uses the real local API.
  HttpOverrides.global = null;
  test(
      'real HTTP admin edits persist and students read the same place and tour',
      () async {
    // Standalone integration test is kept in tool/ to require explicit execution.
    // ignore: invalid_use_of_visible_for_testing_member
    SharedPreferences.setMockInitialValues({});
    const base = 'http://127.0.0.1:8766/api/v1';
    final client = http.Client();
    addTearDown(client.close);
    Future<RestCampusRepository> login(String account) async {
      final response = await client.post(Uri.parse('$base/auth/session'),
          headers: {'Content-Type': 'application/json'},
          body: jsonEncode({
            'email': 'launch-$account@arucad.edu.tr',
            'password': 'launch-test-only'
          }));
      expect(response.statusCode, 200);
      final token = jsonDecode(response.body)['data']['token'] as String;
      return RestCampusRepository(
          client: ApiClient(baseUrl: base, authTokenAdapter: _Token(token)));
    }

    final admin = await login('admin');
    final student = await login('student');
    const place = CampusPlace(
        id: 'launch-http-place',
        name: 'Launch Place',
        category: 'Social',
        lat: 35.337,
        lng: 33.321,
        description: 'HTTP verification',
        distance: '',
        density: 'quiet',
        street: '',
        tourUrl: 'https://360.arucad.edu.tr/index.htm',
        tourTarget: 'scene=42',
        accessible: true,
        photos: 0,
        rating: 0);
    await admin.upsertPlace(place);
    final saved =
        (await student.getPlaces()).singleWhere((p) => p.id == place.id);
    expect(saved.tourTarget, 'scene=42');
    expect(saved.description, 'HTTP verification');
    await expectLater(
        student.upsertPlace(place), throwsA(isA<ApiClientException>()));
    await admin.upsertDirectoryEntry(const DirectoryEntry(
        id: 'launch-http-room',
        building: 'Launch Place',
        occupantName: 'Reception',
        tourUrl: 'https://360.arucad.edu.tr/index.htm',
        tourTarget: 'scene=43'));
    final floors = await student.getDirectoryFloors('Launch Place');
    expect(floors.single.name, 'Kat belirtilmemiş');
    final rooms =
        await student.getDirectoryRooms('Launch Place', floors.single.name);
    expect(rooms.single.tourTarget, 'scene=43');
    await admin.deleteDirectoryEntry('launch-http-room');
    await admin.deletePlace(place.id);
    expect((await student.getPlaces()).any((p) => p.id == place.id), isFalse);
  });
}
