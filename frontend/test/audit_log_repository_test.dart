import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/audit_log_store.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/core/services/rest_campus_repository.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  test('AuditLogEntry.fromJson reads the GET /admin/audit-log payload', () {
    final entry = AuditLogEntry.fromJson({
      'id': 'audit-1',
      'actorName': 'Ezgi Demir',
      'action': 'create',
      'targetType': 'food_venue',
      'targetLabel': 'The Garden',
      'at': '2026-08-23T16:00:00.000000Z',
    });

    expect(entry.id, 'audit-1');
    expect(entry.actorName, 'Ezgi Demir');
    expect(entry.action, 'create');
    expect(entry.targetType, 'food_venue');
    expect(entry.targetLabel, 'The Garden');
    expect(entry.at.isUtc, isTrue);
  });

  test('MockCampusRepository getAuditLog reads AuditLogStore', () async {
    final repo = MockCampusRepository();
    expect(await repo.getAuditLog(), isEmpty);

    await AuditLogStore.logIfMock(
      repo,
      actorName: 'Mock Admin',
      action: 'create',
      targetType: 'club',
      targetLabel: 'Fotoğraf',
    );

    final rows = await repo.getAuditLog();
    expect(rows, hasLength(1));
    expect(rows.first.actorName, 'Mock Admin');
    expect(rows.first.action, 'create');
    expect(rows.first.targetType, 'club');
    expect(rows.first.targetLabel, 'Fotoğraf');

    final prefs = await SharedPreferences.getInstance();
    expect(prefs.getString('admin.audit.log.v1'), isNotNull);
  });

  test('RestCampusRepository getAuditLog hits GET /admin/audit-log and does not write SharedPreferences',
      () async {
    final calls = <http.Request>[];
    final mock = MockClient((request) async {
      calls.add(request);
      if (request.method == 'GET' && request.url.path.endsWith('/admin/audit-log')) {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'audit-rest',
                'actorName': 'Test superAdmin',
                'action': 'create',
                'targetType': 'food_venue',
                'targetLabel': 'Audit Cafe',
                'at': '2026-08-23T16:00:00.000000Z',
              },
            ],
            'meta': {},
            'error': null,
          }),
          200,
        );
      }
      return http.Response(jsonEncode({'data': {}, 'meta': {}, 'error': null}), 404);
    });

    final repo = RestCampusRepository(
      client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
    );

    await AuditLogStore.logIfMock(
      repo,
      actorName: 'should-not-write',
      action: 'create',
      targetType: 'food_venue',
      targetLabel: 'local duplicate',
    );

    final rows = await repo.getAuditLog();
    expect(calls, hasLength(1));
    expect(calls.single.method, 'GET');
    expect(calls.single.url.path, '/api/v1/admin/audit-log');
    expect(rows, hasLength(1));
    expect(rows.single.id, 'audit-rest');
    expect(rows.single.actorName, 'Test superAdmin');
    expect(rows.single.action, 'create');

    final prefs = await SharedPreferences.getInstance();
    expect(prefs.getString('admin.audit.log.v1'), isNull);
  });

  test('REST admin UI and RestCampusRepository do not use AuditLogStore as source of truth', () {
    const paths = [
      'lib/features/admin/admin_panel_screen.dart',
      'lib/core/services/rest_campus_repository.dart',
      'lib/app/app.dart',
    ];
    for (final rel in paths) {
      final src = File(rel).readAsStringSync();
      expect(src.contains('AuditLogStore.entries()'), isFalse, reason: rel);
      expect(src.contains('AuditLogStore.log('), isFalse, reason: rel);
    }

    final admin = File('lib/features/admin/admin_panel_screen.dart').readAsStringSync();
    expect(admin.contains('repository.getAuditLog()'), isTrue);
    expect(admin.contains('AuditLogStore.logIfMock(widget.repository,'), isTrue);

    final rest = File('lib/core/services/rest_campus_repository.dart').readAsStringSync();
    expect(rest.contains('AuditLogStore'), isFalse);
    expect(rest.contains('/admin/audit-log'), isTrue);

    final app = File('lib/app/app.dart').readAsStringSync();
    expect(app.contains('AuditLogStore.logIfMock(widget.repository,'), isTrue);
  });
}
