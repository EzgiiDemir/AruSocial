import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/models/inbox_notification.dart';
import 'package:arucad_campus_prototype/core/models/page_slice.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/audit_log_store.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/core/services/rest_campus_repository.dart';

Map<String, dynamic> _envelope(List<Map<String, dynamic>> data,
        {Map<String, dynamic>? pagination}) =>
    {
      'data': data,
      'meta': {
        'request_id': 'req-test',
        if (pagination != null) 'pagination': pagination,
      },
      'error': null,
    };

Map<String, dynamic> _postJson(String id) => {
      'id': id,
      'authorId': '1',
      'name': 'Ezgi',
      'text': 'hello $id',
      'meta': '',
      'likes': 0,
      'likedByMe': false,
      'comments': [],
      'visibility': 'everyone',
      'postType': 'normal',
    };

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  test('PageSlice.fromEnvelope reads camelCase pagination meta', () {
    final slice = PageSlice.fromEnvelope(
      _envelope([
        _postJson('a'),
        _postJson('b'),
      ], pagination: {
        'currentPage': 2,
        'perPage': 2,
        'total': 5,
        'lastPage': 3,
      }),
      (json) => json['id'] as String,
    );
    expect(slice.items, ['a', 'b']);
    expect(slice.currentPage, 2);
    expect(slice.perPage, 2);
    expect(slice.total, 5);
    expect(slice.lastPage, 3);
    expect(slice.hasMore, isTrue);
  });

  test('PageSlice.fromEnvelope falls back when pagination is missing or malformed', () {
    final missing = PageSlice.fromEnvelope(
      _envelope([_postJson('only')]),
      (json) => json['id'] as String,
    );
    expect(missing.items, ['only']);
    expect(missing.currentPage, 1);
    expect(missing.lastPage, 1);
    expect(missing.total, 1);
    expect(missing.hasMore, isFalse);

    final empty = PageSlice.fromEnvelope(
      {'data': [], 'meta': {}, 'error': null},
      (json) => json['id'] as String,
    );
    expect(empty.items, isEmpty);
    expect(empty.hasMore, isFalse);

    final bad = PageSlice.fromEnvelope(
      {
        'data': [_postJson('x')],
        'meta': {'pagination': 'nope'},
        'error': null,
      },
      (json) => json['id'] as String,
    );
    expect(bad.items, ['x']);
    expect(bad.lastPage, 1);
    expect(bad.hasMore, isFalse);
  });

  test('RestCampusRepository getFeedPage sends page/perPage and parses the slice', () async {
    late http.Request seen;
    final mock = MockClient((request) async {
      seen = request;
      return http.Response(
        jsonEncode(_envelope([
          _postJson('post-11'),
        ], pagination: {
          'currentPage': 2,
          'perPage': 10,
          'total': 25,
          'lastPage': 3,
        })),
        200,
      );
    });
    final repo = RestCampusRepository(
      client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
    );
    final page = await repo.getFeedPage(page: 2, perPage: 10);
    expect(seen.method, 'GET');
    expect(seen.url.path, '/api/v1/feed');
    expect(seen.url.queryParameters['page'], '2');
    expect(seen.url.queryParameters['perPage'], '10');
    expect(page.items.single.id, 'post-11');
    expect(page.currentPage, 2);
    expect(page.hasMore, isTrue);
    expect(page.total, 25);
  });

  test('RestCampusRepository getAuditLogPage hits GET /admin/audit-log with page params', () async {
    late http.Request seen;
    final mock = MockClient((request) async {
      seen = request;
      return http.Response(
        jsonEncode(_envelope([
          {
            'id': 'audit-old',
            'actorName': 'Admin',
            'action': 'create',
            'targetType': 'club',
            'targetLabel': 'Foto',
            'at': '2026-08-23T16:00:00.000000Z',
          }
        ], pagination: {
          'currentPage': 5,
          'perPage': 50,
          'total': 210,
          'lastPage': 5,
        })),
        200,
      );
    });
    final repo = RestCampusRepository(
      client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
    );
    final page = await repo.getAuditLogPage(page: 5, perPage: 50);
    expect(seen.url.path, '/api/v1/admin/audit-log');
    expect(seen.url.queryParameters['page'], '5');
    expect(seen.url.queryParameters['perPage'], '50');
    expect(page.items.single.id, 'audit-old');
    expect(page.hasMore, isFalse);
    expect(page.total, 210);
  });

  test('RestCampusRepository getInboxNotificationsPage and getEmailLogsPage send page params',
      () async {
    final paths = <String>[];
    final mock = MockClient((request) async {
      paths.add('${request.url.path}?${request.url.query}');
      if (request.url.path.endsWith('/notifications')) {
        return http.Response(
          jsonEncode(_envelope([
            {
              'id': 'n-1',
              'kind': 'like',
              'title': 'liked',
              'body': 'body',
              'read': false,
              'createdAt': '2026-08-23T16:00:00.000000Z',
            }
          ], pagination: {
            'currentPage': 1,
            'perPage': 20,
            'total': 1,
            'lastPage': 1,
          })),
          200,
        );
      }
      return http.Response(
        jsonEncode(_envelope([
          {
            'id': 'mail-1',
            'toEmail': 'a@arucad.edu.tr',
            'subject': 'Hi',
            'template': 'bulk-announcement',
            'status': 'sent',
            'error': null,
            'attempts': 1,
            'sentAt': '2026-08-23T16:00:00.000000Z',
          }
        ], pagination: {
          'currentPage': 1,
          'perPage': 20,
          'total': 1,
          'lastPage': 1,
        })),
        200,
      );
    });
    final repo = RestCampusRepository(
      client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
    );
    final inbox = await repo.getInboxNotificationsPage();
    final mail = await repo.getEmailLogsPage();
    expect(inbox.items.single.id, 'n-1');
    expect(inbox.items.single.read, isFalse);
    expect(mail.items.single.toEmail, 'a@arucad.edu.tr');
    expect(paths.singleWhere((p) => p.contains('/notifications')), contains('page=1'));
    expect(paths.singleWhere((p) => p.contains('/email-logs')), contains('perPage=20'));
  });

  test('MockCampusRepository getFeedPage slices without duplicating ids across pages', () async {
    final repo = MockCampusRepository();
    final all = await repo.getFeed();
    expect(all, isNotEmpty);
    final page1 = await repo.getFeedPage(page: 1, perPage: 1);
    final page2 = await repo.getFeedPage(page: 2, perPage: 1);
    expect(page1.items, hasLength(1));
    expect(page1.hasMore, isTrue);
    expect(page1.items.single.id, isNot(page2.items.single.id));
    expect(page1.total, all.length);
  });

  test('MockCampusRepository getInboxNotificationsPage is an empty completed page', () async {
    final repo = MockCampusRepository();
    final page = await repo.getInboxNotificationsPage();
    expect(page.items, isEmpty);
    expect(page, isA<PageSlice<InboxNotification>>());
    expect(page.hasMore, isFalse);
    expect(page.total, 0);
  });

  test('RestCampusRepository sendBulkEmail posts recipients/subject/body and reads sent',
      () async {
    http.Request? seen;
    final mock = MockClient((request) async {
      seen = request;
      return http.Response(
        jsonEncode({
          'data': {
            'sent': 2,
            'results': [
              {'email': 'a@arucad.edu.tr', 'status': 'sent'},
              {'email': 'b@arucad.edu.tr', 'status': 'failed'},
            ],
          },
          'meta': {'request_id': 'req-test'},
          'error': null,
        }),
        200,
      );
    });
    final repo = RestCampusRepository(
      client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
    );
    final sent = await repo.sendBulkEmail(
      recipients: ['a@arucad.edu.tr', 'b@arucad.edu.tr'],
      subject: 'Duyuru',
      body: 'Merhaba',
    );
    expect(seen!.url.path, '/api/v1/admin/email/bulk');
    expect(jsonDecode(seen!.body) as Map<String, dynamic>, {
      'recipients': ['a@arucad.edu.tr', 'b@arucad.edu.tr'],
      'subject': 'Duyuru',
      'body': 'Merhaba',
    });
    expect(sent, 2);
  });

  test('RestCampusRepository sendBulkEmail surfaces VALIDATION and retry reads status',
      () async {
    final mock = MockClient((request) async {
      if (request.url.path.endsWith('/admin/email/bulk')) {
        return http.Response(
          jsonEncode({
            'data': null,
            'meta': {'request_id': 'req-test'},
            'error': {
              'code': 'VALIDATION',
              'message': 'recipients, subject and body are required.',
            },
          }),
          400,
        );
      }
      if (request.url.path.endsWith('/admin/email-logs/mail-1/retry')) {
        return http.Response(
          jsonEncode({
            'data': {'status': 'sent'},
            'meta': {'request_id': 'req-test'},
            'error': null,
          }),
          200,
        );
      }
      return http.Response('unexpected', 500);
    });
    final repo = RestCampusRepository(
      client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
    );
    try {
      await repo.sendBulkEmail(recipients: [], subject: '', body: '');
      fail('expected ApiClientException');
    } on ApiClientException catch (e) {
      expect(e.statusCode, 400);
      expect(e.code, 'VALIDATION');
    }
    expect(await repo.retryEmail('mail-1'), 'sent');
  });

  test('MockCampusRepository getAuditLogPage pages AuditLogStore newest-first', () async {
    final repo = MockCampusRepository();
    for (var i = 1; i <= 5; i++) {
      await AuditLogStore.logIfMock(
        repo,
        actorName: 'Admin',
        action: 'create',
        targetType: 'club',
        targetLabel: 'row $i',
      );
    }
    final page1 = await repo.getAuditLogPage(page: 1, perPage: 2);
    final page3 = await repo.getAuditLogPage(page: 3, perPage: 2);
    expect(page1.total, 5);
    expect(page1.items, hasLength(2));
    expect(page3.items, hasLength(1));
    expect(page1.items.map((e) => e.id).toSet().intersection(page3.items.map((e) => e.id).toSet()),
        isEmpty);
  });
}
