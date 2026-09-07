import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/core/services/rest_campus_repository.dart';

Map<String, dynamic> _userJson({
  String? department,
  String? year,
  String? university,
  List<String> clubs = const [],
  List<String> achievements = const [],
  List<String> projects = const [],
}) =>
    {
      'id': '1',
      'name': 'Ezgi',
      'role': 'Student',
      'level': 1,
      'xp': 0,
      'places': 0,
      'events': 0,
      'memories': 0,
      'interests': <String>[],
      'avatarUrl': null,
      'department': department,
      'year': year,
      'university': university,
      'clubs': clubs,
      'achievements': achievements,
      'projects': projects,
    };

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  group('MockCampusRepository profile bio', () {
    test('getMe returns seed bio fields before any edit', () async {
      final repo = MockCampusRepository();

      final me = await repo.getMe();

      expect(me.department, isNotNull);
      expect(me.clubs, isNotEmpty);
    });

    test('updateProfileBio persists and getMe reflects the change', () async {
      final repo = MockCampusRepository();

      final updated = await repo.updateProfileBio(
        department: 'Yeni Bölüm',
        clubs: ['Yeni Kulüp'],
      );

      expect(updated.department, 'Yeni Bölüm');
      expect(updated.clubs, ['Yeni Kulüp']);

      final me = await repo.getMe();
      expect(me.department, 'Yeni Bölüm');
      expect(me.clubs, ['Yeni Kulüp']);
    });

    test('omitted fields keep their previous value across two updates', () async {
      final repo = MockCampusRepository();

      await repo.updateProfileBio(department: 'Bölüm A', university: 'ARUCAD');
      final second = await repo.updateProfileBio(year: '2');

      expect(second.department, 'Bölüm A');
      expect(second.university, 'ARUCAD');
      expect(second.year, '2');
    });

    test('updateProfileBio avatarUrl is returned by getMe', () async {
      final repo = MockCampusRepository();

      await repo.updateProfileBio(avatarUrl: 'https://example.com/a.png');

      expect((await repo.getMe()).avatarUrl, 'https://example.com/a.png');
    });
  });

  group('RestCampusRepository profile bio', () {
    test('getMe parses bio fields from GET /me', () async {
      final mock = MockClient((request) async => http.Response(
            jsonEncode({
              'data': _userJson(department: 'CS', clubs: ['Robotik']),
              'meta': {'request_id': 'req-test'},
              'error': null,
            }),
            200,
          ));
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      final me = await repo.getMe();

      expect(me.department, 'CS');
      expect(me.clubs, ['Robotik']);
    });

    test('updateProfileBio posts avatarUrl when provided', () async {
      http.Request? seen;
      final mock = MockClient((request) async {
        seen = request;
        return http.Response(
          jsonEncode({
            'data': _userJson(),
            'meta': {'request_id': 'req-test'},
            'error': null,
          }),
          200,
        );
      });
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      await repo.updateProfileBio(
          avatarUrl: 'http://example.com/storage/media/a.jpg');

      final body = jsonDecode(seen!.body) as Map<String, dynamic>;
      expect(body, {'avatarUrl': 'http://example.com/storage/media/a.jpg'});
    });

    test('updateProfileBio posts only the provided fields to /me/profile', () async {
      http.Request? seen;
      final mock = MockClient((request) async {
        seen = request;
        return http.Response(
          jsonEncode({
            'data': _userJson(department: 'CS'),
            'meta': {'request_id': 'req-test'},
            'error': null,
          }),
          200,
        );
      });
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      final updated = await repo.updateProfileBio(department: 'CS');

      expect(seen!.method, 'POST');
      expect(seen!.url.path, '/api/v1/me/profile');
      final body = jsonDecode(seen!.body) as Map<String, dynamic>;
      expect(body, {'department': 'CS'});
      expect(updated.department, 'CS');
    });

    test('validation failure surfaces as ApiClientException', () async {
      final mock = MockClient((request) async => http.Response(
            jsonEncode({
              'data': null,
              'meta': {'request_id': 'req-test'},
              'error': {'code': 'VALIDATION', 'message': 'Invalid profile fields.'},
            }),
            400,
          ));
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      try {
        await repo.updateProfileBio(clubs: const ['x']);
        fail('expected ApiClientException');
      } on ApiClientException catch (e) {
        expect(e.statusCode, 400);
        expect(e.code, 'VALIDATION');
      }
    });
  });
}
