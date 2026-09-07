import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/core/services/rest_campus_repository.dart';

Map<String, dynamic> _envelope(dynamic data) => {
      'data': data,
      'meta': {'request_id': 'req-test'},
      'error': null,
    };

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  group('MockCampusRepository user settings', () {
    test('defaults then persists a visibility change', () async {
      final repo = MockCampusRepository();

      final first = await repo.getUserSettings();
      expect(first.locationVisibility, 'ghost');
      expect(first.checkInVisible, isTrue);

      final updated = await repo.updateUserSettings(
        locationVisibility: 'friends',
        checkInVisible: false,
      );
      expect(updated.locationVisibility, 'friends');
      expect(updated.checkInVisible, isFalse);
      expect(updated.personalization, isTrue);
    });
  });

  group('RestCampusRepository user settings', () {
    test('getUserSettings hits GET /me/settings', () async {
      http.Request? seen;
      final mock = MockClient((request) async {
        seen = request;
        return http.Response(
          jsonEncode(_envelope({
            'locationVisibility': 'public',
            'nearbyDiscoverable': true,
            'checkInVisible': false,
            'personalization': true,
            'preferredLanguage': 'EN',
          })),
          200,
        );
      });
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      final settings = await repo.getUserSettings();

      expect(seen!.method, 'GET');
      expect(seen!.url.path, '/api/v1/me/settings');
      expect(settings.locationVisibility, 'public');
      expect(settings.nearbyDiscoverable, isTrue);
      expect(settings.checkInVisible, isFalse);
      expect(settings.preferredLanguage, 'EN');
    });

    test('updateUserSettings posts only provided keys', () async {
      http.Request? seen;
      final mock = MockClient((request) async {
        seen = request;
        return http.Response(
          jsonEncode(_envelope({
            'locationVisibility': 'community',
            'nearbyDiscoverable': false,
            'checkInVisible': true,
            'personalization': true,
            'preferredLanguage': 'TR',
          })),
          200,
        );
      });
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      await repo.updateUserSettings(preferredLanguage: 'RU');

      expect(seen!.method, 'POST');
      expect(seen!.url.path, '/api/v1/me/settings');
      expect(jsonDecode(seen!.body), {'preferredLanguage': 'RU'});
    });
  });
}
