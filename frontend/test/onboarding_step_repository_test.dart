import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/config/onboarding_config.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/core/services/rest_campus_repository.dart';

/// Real replacement for the previously fully-hardcoded `onboardingSteps`
/// const — REST mode now defers to the backend; Mock mode keeps that const
/// only as an offline seed (docs/EKSIKLER.md scope for this milestone).
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  test('MockCampusRepository seeds from the const and supports admin CRUD',
      () async {
    final repo = MockCampusRepository();

    final seeded = await repo.getOnboardingSteps();
    expect(seeded.map((s) => s.id), contains('day1-orientation'));

    final created = await repo.upsertOnboardingStep(const OnboardingStep(
      id: 'test-step',
      group: '5. Hafta',
      title: 'Test adımı',
      detail: 'Test detayı.',
    ));
    expect(created.title, 'Test adımı');

    final afterCreate = await repo.getOnboardingSteps();
    expect(afterCreate.any((s) => s.id == 'test-step'), isTrue);

    await repo.deleteOnboardingStep('test-step');
    final afterDelete = await repo.getOnboardingSteps();
    expect(afterDelete.any((s) => s.id == 'test-step'), isFalse);
  });

  test('MockCampusRepository hides inactive steps from the student-facing list',
      () async {
    final repo = MockCampusRepository();
    await repo.upsertOnboardingStep(const OnboardingStep(
      id: 'hidden-step',
      group: '5. Hafta',
      title: 'Gizli adım',
      detail: 'd',
      active: false,
    ));

    final studentFacing = await repo.getOnboardingSteps();
    expect(studentFacing.any((s) => s.id == 'hidden-step'), isFalse);

    final adminFacing = await repo.getAdminOnboardingSteps();
    expect(adminFacing.any((s) => s.id == 'hidden-step'), isTrue);
  });

  test('RestCampusRepository reads/writes /onboarding-steps and /admin/onboarding-steps',
      () async {
    final calls = <http.Request>[];
    final mock = MockClient((request) async {
      calls.add(request);
      if (request.method == 'GET' &&
          request.url.path.endsWith('/onboarding-steps') &&
          !request.url.path.contains('/admin/')) {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'day1-orientation',
                'group': '1. Gun',
                'title': 'Orientation',
                'detail': 'Go to orientation.',
                'actionKind': 'service',
                'refId': 'student-affairs',
                'sortOrder': 0,
              },
            ],
            'meta': {},
            'error': null,
          }),
          200,
        );
      }
      if (request.method == 'POST' &&
          request.url.path.endsWith('/admin/onboarding-steps')) {
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        return http.Response(
          jsonEncode({
            'data': {
              'id': body['id'],
              'group': body['groupLabel'],
              'title': body['title'],
              'detail': body['detail'],
              'actionKind': body['actionKind'] ?? 'info',
              'refId': body['refId'],
              'sortOrder': body['sortOrder'] ?? 0,
            },
            'meta': {},
            'error': null,
          }),
          201,
        );
      }
      return http.Response(jsonEncode({'data': {}, 'meta': {}, 'error': null}), 404);
    });

    final repo = RestCampusRepository(
      client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
    );

    final steps = await repo.getOnboardingSteps();
    expect(steps, hasLength(1));
    expect(steps.single.actionKind, OnboardingActionKind.service);
    expect(steps.single.refId, 'student-affairs');

    final upserted = await repo.upsertOnboardingStep(const OnboardingStep(
      id: 'week5-extra',
      group: '5. Hafta',
      title: 'Extra',
      detail: 'Extra detail.',
      actionKind: OnboardingActionKind.list,
      refId: 'clubs',
    ));
    expect(upserted.id, 'week5-extra');
    expect(upserted.actionKind, OnboardingActionKind.list);
    expect(
        calls.any((r) =>
            r.method == 'POST' &&
            r.url.path.endsWith('/admin/onboarding-steps')),
        isTrue);
  });
}
