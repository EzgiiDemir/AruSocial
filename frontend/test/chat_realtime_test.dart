import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/models/chat_message.dart';
import 'package:arucad_campus_prototype/core/services/chat_realtime_config.dart';
import 'package:arucad_campus_prototype/core/services/chat_realtime_service.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';

class _FakeConnector implements ChatRealtimeConnector {
  final eventsController = StreamController<Map<String, dynamic>>.broadcast();
  final reconnectController = StreamController<void>.broadcast();
  var connected = false;
  var connectCalls = 0;
  String? conversationId;
  var failConnect = false;

  @override
  Stream<Map<String, dynamic>> get events => eventsController.stream;

  @override
  Stream<void> get reconnected => reconnectController.stream;

  @override
  Future<void> connect({
    required String userId,
    required Future<String?> Function() tokenProvider,
  }) async {
    connectCalls += 1;
    if (failConnect) throw StateError('socket down');
    await tokenProvider();
    connected = true;
  }

  @override
  Future<void> subscribeConversation(String id) async {
    conversationId = id;
  }

  @override
  Future<void> disconnect() async {
    connected = false;
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    InMemoryChatConnector.instance.reset();
  });

  test('parses a REST-shaped message event and recomputes fromMe', () {
    final incoming = ChatMessage.fromJson({
      'id': 'msg-1',
      'fromMe': true,
      'text': 'selam',
      'sentAt': '2026-08-23T10:00:00.000Z',
      'sender': 'Kullanıcı A',
      'peer': 'Kullanıcı B',
      'conversationId': 9,
    }, myName: 'Kullanıcı B');
    expect(incoming.fromMe, isFalse);
    expect(incoming.conversationId, '9');
    expect(incoming.sender, 'Kullanıcı A');
  });

  test('duplicate ids are ignored', () {
    final first = ChatMessage(id: 'msg-1', fromMe: false, text: 'a');
    final again = ChatMessage(id: 'msg-1', fromMe: false, text: 'a');
    final merged = ChatRealtimeService.upsert(
      ChatRealtimeService.upsert(const [], first),
      again,
    );
    expect(merged, hasLength(1));
  });

  test('optimistic tmp row is replaced by the confirmed id', () {
    final tmp = ChatMessage(id: 'tmp-1', fromMe: true, text: 'hello');
    final saved = ChatMessage(id: 'msg-9', fromMe: true, text: 'hello');
    final merged = ChatRealtimeService.upsert([tmp], saved);
    expect(merged.map((m) => m.id), ['msg-9']);
  });

  test('reconnect emits resynced so the UI can refetch REST history', () async {
    final fake = _FakeConnector();
    final service = ChatRealtimeService(connector: fake);
    var resyncs = 0;
    final sub = service.resynced.listen((_) => resyncs++);
    await service.start(userId: '1', userName: 'Ezgi', tokenProvider: () async => 'tok');
    expect(fake.connected, isTrue);
    fake.reconnectController.add(null);
    await Future<void>.delayed(Duration.zero);
    expect(resyncs, 1);
    await sub.cancel();
    await service.dispose();
    expect(fake.connected, isFalse);
  });

  test('REST fallback: a dead connector does not throw out of start', () async {
    final fake = _FakeConnector()..failConnect = true;
    final service = ChatRealtimeService(connector: fake);
    await service.start(userId: '1', userName: 'Ezgi', tokenProvider: () async => 'tok');
    expect(fake.connected, isFalse);
    await service.dispose();
  });

  test('pause disconnects and resume reconnects', () async {
    final fake = _FakeConnector();
    final service = ChatRealtimeService(connector: fake);
    await service.start(userId: '1', userName: 'Ezgi', tokenProvider: () async => 'tok');
    await service.pause();
    expect(fake.connected, isFalse);
    await service.resume(userId: '1', userName: 'Ezgi');
    expect(fake.connected, isTrue);
    expect(fake.connectCalls, 2);
    await service.dispose();
  });

  test('mock mode publishes in-process without a websocket', () async {
    final repo = MockCampusRepository();
    final service = ChatRealtimeService.forRepository(repo);
    final incoming = <ChatMessage>[];
    final sub = service.messages.listen(incoming.add);
    final me = await repo.getMe();
    await service.start(userId: me.id, userName: me.name);
    final saved = await repo.sendChatMessage('Peer', 'in-process');
    await Future<void>.delayed(Duration.zero);
    expect(incoming.map((m) => m.id), contains(saved.id));
    await sub.cancel();
    await service.dispose();
  });

  test('subscribeConversation is forwarded to the connector', () async {
    final fake = _FakeConnector();
    final service = ChatRealtimeService(connector: fake);
    await service.start(userId: '1', userName: 'Ezgi', conversationId: '12', tokenProvider: () async => 'tok');
    expect(fake.conversationId, '12');
    await service.dispose();
  });

  test('Reverb config never puts the token on the websocket URI', () {
    final config = ChatRealtimeConfig.fromEnvironment(
      apiBaseUrl: 'http://localhost:4000/api/v1',
    );
    expect(config.authUri.toString(), 'http://localhost:4000/broadcasting/auth');
    expect(config.websocketUri.queryParameters.containsKey('token'), isFalse);
    expect(config.websocketUri.path, '/app/arucad-local-key');
  });
}
