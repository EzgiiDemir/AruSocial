import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/core/services/push_messaging.dart';
import 'package:arucad_campus_prototype/core/services/push_notification_service.dart';
import 'package:arucad_campus_prototype/core/services/push_payload.dart';
import 'package:arucad_campus_prototype/core/services/rest_campus_repository.dart';

class FakePushMessaging implements PushMessaging {
  FakePushMessaging({this.currentToken, this.initialPayload});

  String? currentToken;
  PushPayload? initialPayload;
  final tokenController = StreamController<String>.broadcast();
  final foregroundController = StreamController<PushPayload>.broadcast();
  final openedController = StreamController<PushPayload>.broadcast();
  var prepareCalls = 0;

  @override
  Future<void> prepare() async {
    prepareCalls += 1;
  }

  @override
  Future<String?> token() async => currentToken;

  @override
  Stream<String> get tokenRefreshes => tokenController.stream;

  @override
  Stream<PushPayload> get foreground => foregroundController.stream;

  @override
  Stream<PushPayload> get opened => openedController.stream;

  @override
  Future<PushPayload?> initial() async => initialPayload;
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  test('PushPayload.fromData reads chat fields and opensChat', () {
    final payload = PushPayload.fromData({
      'type': 'message',
      'notificationId': 'notif-1',
      'conversationId': '9',
      'messageId': 'msg-1',
      'peer': 'Kullanıcı A',
    }, title: 'Yeni mesaj', body: 'selam');

    expect(payload.opensChat, isTrue);
    expect(payload.peer, 'Kullanıcı A');
    expect(payload.messageId, 'msg-1');
    expect(payload.title, 'Yeni mesaj');
  });

  test('follow payload routes to notifications, not chat', () {
    final payload = PushPayload.fromData({'type': 'follow', 'notificationId': 'n2', 'peer': 'A'});
    expect(payload.opensChat, isFalse);
  });

  test('mock repository is a no-op for push tokens', () async {
    final fake = FakePushMessaging(currentToken: 'tok');
    final service = PushNotificationService.forRepository(
      MockCampusRepository(),
      messaging: fake,
    );
    await service.start();
    expect(fake.prepareCalls, 1);
    await service.stopAndUnregister();
    await service.dispose();
  });

  test('rest start registers token, refresh re-registers, logout unregisters', () async {
    final calls = <http.Request>[];
    final mock = MockClient((request) async {
      calls.add(request);
      return http.Response(jsonEncode({'data': {'ok': true}, 'meta': {}, 'error': null}), 200);
    });
    final repo = RestCampusRepository(
      client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
    );
    final fake = FakePushMessaging(currentToken: 'fcm-1');
    final service = PushNotificationService(repository: repo, messaging: fake);

    await service.start();
    expect(calls.single.method, 'POST');
    expect(calls.single.url.path, '/api/v1/push-tokens');
    expect(jsonDecode(calls.single.body)['token'], 'fcm-1');

    fake.tokenController.add('fcm-2');
    await Future<void>.delayed(Duration.zero);
    expect(calls.length, 2);
    expect(jsonDecode(calls.last.body)['token'], 'fcm-2');

    await service.stopAndUnregister();
    expect(calls.last.url.path, '/api/v1/push-tokens/unregister');
    expect(jsonDecode(calls.last.body)['token'], 'fcm-2');
    await service.dispose();
  });

  test('foreground does not emit a second opened event for the same id', () async {
    final fake = FakePushMessaging();
    final service = PushNotificationService(
      repository: MockCampusRepository(),
      messaging: fake,
    );
    final opened = <PushPayload>[];
    final foreground = <PushPayload>[];
    service.opened.listen(opened.add);
    service.foreground.listen(foreground.add);
    await service.start();

    final payload = PushPayload.fromData({'type': 'message', 'messageId': 'msg-dup', 'peer': 'A'});
    fake.foregroundController.add(payload);
    fake.openedController.add(payload);
    await Future<void>.delayed(Duration.zero);

    expect(foreground, hasLength(1));
    expect(opened, isEmpty);
    await service.dispose();
  });

  test('opened tap with unique id is emitted for routing', () async {
    final fake = FakePushMessaging();
    final service = PushNotificationService(
      repository: MockCampusRepository(),
      messaging: fake,
    );
    final opened = <PushPayload>[];
    service.opened.listen(opened.add);
    await service.start();

    fake.openedController.add(PushPayload.fromData({
      'type': 'message',
      'messageId': 'msg-tap',
      'peer': 'Kullanıcı B',
    }));
    await Future<void>.delayed(Duration.zero);
    expect(opened, hasLength(1));
    expect(opened.single.opensChat, isTrue);
    await service.dispose();
  });
}
