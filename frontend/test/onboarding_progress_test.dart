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

  group('MockCampusRepository onboarding', () {
    test('starts empty, marking a step persists it', () async {
      final repo = MockCampusRepository();

      final first = await repo.getOnboardingProgress();
      expect(first.done, isEmpty);

      await repo.setOnboardingStepDone('day1-orientation', true);
      final second = await repo.getOnboardingProgress();
      expect(second.done, {'day1-orientation'});
    });

    test('unchecking removes the step; startedAt is stable', () async {
      final repo = MockCampusRepository();

      final first = await repo.getOnboardingProgress();
      await repo.setOnboardingStepDone('day1-id', true);
      await repo.setOnboardingStepDone('day1-id', false);

      final second = await repo.getOnboardingProgress();
      expect(second.done, isEmpty);
      expect(second.startedAt, first.startedAt);
    });
  });

  group('RestCampusRepository onboarding', () {
    test('getOnboardingProgress hits GET /me/onboarding', () async {
      http.Request? seen;
      final mock = MockClient((request) async {
        seen = request;
        return http.Response(
          jsonEncode(_envelope({
            'done': ['day1-orientation'],
            'startedAt': '2026-08-01T10:00:00.000000Z',
            'eligible': true,
          })),
          200,
        );
      });
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      final progress = await repo.getOnboardingProgress();

      expect(seen!.method, 'GET');
      expect(seen!.url.path, '/api/v1/me/onboarding');
      expect(progress.done, {'day1-orientation'});
      expect(progress.eligible, isTrue);
      expect(progress.startedAt.toUtc().toIso8601String(), '2026-08-01T10:00:00.000Z');
    });

    test('setOnboardingStepDone posts completed to /me/onboarding/{stepId}', () async {
      http.Request? seen;
      final mock = MockClient((request) async {
        seen = request;
        return http.Response(jsonEncode(_envelope({'completed': true})), 200);
      });
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      await repo.setOnboardingStepDone('week1-club', true);

      expect(seen!.method, 'POST');
      expect(seen!.url.path, '/api/v1/me/onboarding/week1-club');
      expect(jsonDecode(seen!.body), {'completed': true});
    });
  });
}
