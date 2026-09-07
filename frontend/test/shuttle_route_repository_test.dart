import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/config/shuttle_config.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/core/services/rest_campus_repository.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// Real replacement for the previously fully-hardcoded `shuttleRoutes`
/// const — REST mode now defers to the backend; Mock mode keeps that const
/// only as an offline seed (docs/EKSIKLER.md scope for this milestone).
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  test('colorForShuttleKey maps every known key and falls back to blue', () {
    expect(colorForShuttleKey('yellow'), ArucadColors.yellow);
    expect(colorForShuttleKey('danger'), ArucadColors.danger);
    expect(colorForShuttleKey('not-a-real-key'), ArucadColors.blue);
    expect(colorForShuttleKey(null), ArucadColors.blue);
  });

  test('MockCampusRepository seeds from the hardcoded const and supports admin CRUD',
      () async {
    final repo = MockCampusRepository();

    final seeded = await repo.getShuttleRoutes();
    expect(seeded.map((r) => r.id), contains('nicosia'));

    final created = await repo.upsertShuttleRoute(const ShuttleRoute(
      id: 'test-route',
      name: 'Test Servisi',
      colorKey: 'danger',
      stops: ['A', 'B'],
      departures: ['09:00'],
    ));
    expect(created.name, 'Test Servisi');

    final afterCreate = await repo.getShuttleRoutes();
    expect(afterCreate.any((r) => r.id == 'test-route'), isTrue);

    await repo.deleteShuttleRoute('test-route');
    final afterDelete = await repo.getShuttleRoutes();
    expect(afterDelete.any((r) => r.id == 'test-route'), isFalse);
  });

  test('RestCampusRepository reads/writes /shuttle-routes and /admin/shuttle-routes',
      () async {
    final calls = <http.Request>[];
    final mock = MockClient((request) async {
      calls.add(request);
      if (request.method == 'GET' &&
          request.url.path.endsWith('/shuttle-routes')) {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'nicosia',
                'name': 'Nicosia Line',
                'colorKey': 'blue',
                'stops': ['A', 'B'],
                'departures': ['07:00', '11:00'],
                'returns': null,
              },
            ],
            'meta': {},
            'error': null,
          }),
          200,
        );
      }
      if (request.method == 'POST' &&
          request.url.path.endsWith('/admin/shuttle-routes')) {
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        return http.Response(
          jsonEncode({'data': body, 'meta': {}, 'error': null}),
          201,
        );
      }
      return http.Response(jsonEncode({'data': {}, 'meta': {}, 'error': null}), 404);
    });

    final repo = RestCampusRepository(
      client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
    );

    final routes = await repo.getShuttleRoutes();
    expect(routes, hasLength(1));
    expect(routes.single.color, ArucadColors.blue);
    expect(routes.single.stops, ['A', 'B']);

    final upserted = await repo.upsertShuttleRoute(const ShuttleRoute(
      id: 'bandabuliya',
      name: 'Bandabuliya Servisi',
      colorKey: 'warning',
      stops: ['ARUCAD', 'Bandabuliya'],
      departures: ['07:30'],
      returns: ['09:00'],
    ));
    expect(upserted.id, 'bandabuliya');
    expect(upserted.returns, ['09:00']);
    expect(
        calls.any((r) =>
            r.method == 'POST' &&
            r.url.path.endsWith('/admin/shuttle-routes')),
        isTrue);
  });
}
