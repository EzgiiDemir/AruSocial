import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/auth/session_store.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/ask_arucad_store.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    ApiClient.onSessionInvalid = null;
  });

  tearDown(() {
    ApiClient.onSessionInvalid = null;
  });

  group('Ask ARUCAD history is per account', () {
    test('user B does not see user A conversations', () async {
      final a = AskArucadConversation(
        id: 'c1',
        title: 'A private',
        messages: [
          AskArucadMessage(fromUser: true, text: 'secret', at: DateTime.now()),
        ],
        updatedAt: DateTime.now(),
      );
      await AskArucadStore.save(a, ownerEmail: 'a@arucad.edu.tr');

      final forB = await AskArucadStore.all(ownerEmail: 'b@arucad.edu.tr');
      expect(forB, isEmpty);

      final forA = await AskArucadStore.all(ownerEmail: 'a@arucad.edu.tr');
      expect(forA, hasLength(1));
      expect(forA.single.title, 'A private');
    });

    test('signed-out lookup does not return another user thread', () async {
      await AskArucadStore.save(
        AskArucadConversation(
          id: 'c2',
          title: 'A',
          messages: const [],
          updatedAt: DateTime.now(),
        ),
        ownerEmail: 'a@arucad.edu.tr',
      );
      expect(await AskArucadStore.all(), isEmpty);
    });
  });

  group('API error display', () {
    test('401 AUTH_REQUIRED invalidates session', () async {
      String? seen;
      ApiClient.onSessionInvalid = (code, _) => seen = code;
      final client = ApiClient(
        baseUrl: 'http://example.com/api/v1',
        client: MockClient((_) async => http.Response(
              jsonEncode({
                'error': {'code': 'AUTH_REQUIRED', 'message': 'gone'},
              }),
              401,
            )),
      );
      try {
        await client.get('/me');
        fail('expected throw');
      } on ApiClientException catch (e) {
        expect(seen, 'AUTH_REQUIRED');
        expect(e.displayMessage, contains('Oturumunuz sona erdi'));
      }
    });

    test('403 ACCOUNT_BANNED invalidates session', () async {
      String? seen;
      ApiClient.onSessionInvalid = (code, _) => seen = code;
      final client = ApiClient(
        baseUrl: 'http://example.com/api/v1',
        // Turkish characters need a UTF-8 body; http.Response(String) encodes
        // as Latin-1 and would throw on 'ı'/'ş'.
        client: MockClient((_) async => http.Response.bytes(
              utf8.encode(jsonEncode({
                'error': {
                  'code': 'ACCOUNT_BANNED',
                  'message':
                      'Hesabın geçici olarak askıya alındı. Tekrar erişebileceğin zaman: 20.09.2026 14:00.',
                },
              })),
              403,
              headers: {'content-type': 'application/json; charset=utf-8'},
            )),
      );
      try {
        await client.get('/feed');
        fail('expected throw');
      } on ApiClientException catch (e) {
        expect(seen, 'ACCOUNT_BANNED');
        // The user must see the real reason AND when they can return.
        expect(e.displayMessage, contains('askıya'));
        expect(e.displayMessage, contains('20.09.2026 14:00'));
      }
    });

    test('403 permission denied does not invalidate session', () async {
      var fired = false;
      ApiClient.onSessionInvalid = (_, __) => fired = true;
      final client = ApiClient(
        baseUrl: 'http://example.com/api/v1',
        client: MockClient((_) async => http.Response(
              jsonEncode({
                'error': {'code': 'FORBIDDEN', 'message': 'no'},
              }),
              403,
            )),
      );
      try {
        await client.get('/admin/stats');
        fail('expected throw');
      } on ApiClientException catch (e) {
        expect(fired, isFalse);
        expect(e.displayMessage, contains('yetkin'));
      }
    });

    test('422 429 500 map to stable messages', () {
      expect(
        ApiClientException('field invalid', statusCode: 422).displayMessage,
        'field invalid',
      );
      expect(
        ApiClientException('slow', statusCode: 429).displayMessage,
        contains('Çok fazla istek'),
      );
      expect(
        ApiClientException('boom', statusCode: 500).displayMessage,
        contains('Sunucu hatası'),
      );
    });
  });

  test('portal session isolation still holds after login switch', () async {
    await SessionStore.save(token: 's', email: 'student@arucad.edu.tr', portal: 'student');
    await SessionStore.save(token: 'a', email: 'admin@arucad.edu.tr', portal: 'admin');
    await SessionStore.clear(portal: 'student');
    expect(await SessionStore.token(portal: 'student'), isNull);
    expect(await SessionStore.email(portal: 'admin'), 'admin@arucad.edu.tr');
  });
}
