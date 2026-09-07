import 'dart:async';

import '../auth/session_store.dart';
import '../models/chat_message.dart';
import 'chat_realtime_config.dart';
import 'contracts.dart';
import 'reverb_chat_connector.dart';
import 'rest_campus_repository.dart';

/// Narrow transport for chat delivery. REST remains the history source of
/// truth; this only pushes newly persisted messages to subscribers.
abstract class ChatRealtimeConnector {
  Stream<Map<String, dynamic>> get events;
  Stream<void> get reconnected;

  Future<void> connect({
    required String userId,
    required Future<String?> Function() tokenProvider,
    bool subscribeOpsAppointments = false,
  });

  Future<void> subscribeConversation(String conversationId);

  Future<void> disconnect();
}

/// In-process bus for Mock mode (`USE_REST_API=false`). No Reverb process.
class InMemoryChatConnector implements ChatRealtimeConnector {
  InMemoryChatConnector();

  static final instance = InMemoryChatConnector();

  final _events = StreamController<Map<String, dynamic>>.broadcast();
  final _reconnected = StreamController<void>.broadcast();
  var _connected = false;

  @override
  Stream<Map<String, dynamic>> get events => _events.stream;

  @override
  Stream<void> get reconnected => _reconnected.stream;

  @override
  Future<void> connect({
    required String userId,
    required Future<String?> Function() tokenProvider,
    bool subscribeOpsAppointments = false,
  }) async {
    _connected = true;
  }

  @override
  Future<void> subscribeConversation(String conversationId) async {}

  @override
  Future<void> disconnect() async {
    _connected = false;
  }

  void publish(Map<String, dynamic> payload) {
    if (_connected) _events.add(payload);
  }

  void simulateReconnect() {
    if (_connected) _reconnected.add(null);
  }

  void reset() {
    _connected = false;
  }
}

class ChatRealtimeService {
  ChatRealtimeService({
    required ChatRealtimeConnector connector,
    Future<String?> Function()? tokenProvider,
  })  : _connector = connector,
        _tokenProvider = tokenProvider;

  final ChatRealtimeConnector _connector;
  final Future<String?> Function()? _tokenProvider;
  final _messages = StreamController<ChatMessage>.broadcast();
  final _appointmentChanged = StreamController<void>.broadcast();
  final _campusChanged = StreamController<List<String>>.broadcast();
  StreamSubscription<Map<String, dynamic>>? _eventsSub;

  String? _userName;
  var _started = false;

  Stream<ChatMessage> get messages => _messages.stream;
  Stream<void> get appointmentChanged => _appointmentChanged.stream;

  /// Public-data invalidation only; callers re-fetch REST data for the
  /// listed collections rather than trusting websocket payloads as records.
  Stream<List<String>> get campusChanged => _campusChanged.stream;
  Stream<void> get resynced => _connector.reconnected;

  static ChatRealtimeService forRepository(
    CampusRepository repository, {
    Future<String?> Function()? tokenProvider,
  }) {
    if (repository is RestCampusRepository) {
      final tokens = tokenProvider ??
          repository.client.authTokenAdapter?.getAccessToken ??
          SessionStore.token;
      return ChatRealtimeService(
        connector: ReverbChatConnector(
          config: ChatRealtimeConfig.fromEnvironment(
              apiBaseUrl: repository.client.baseUrl),
          tokenProvider: tokens,
        ),
        tokenProvider: tokens,
      );
    }
    return ChatRealtimeService(connector: InMemoryChatConnector.instance);
  }

  Future<void> start({
    required String userId,
    required String userName,
    String? conversationId,
    Future<String?> Function()? tokenProvider,
    bool subscribeOpsAppointments = false,
  }) async {
    _userName = userName;
    _eventsSub ??= _connector.events.listen(_onEvent);
    try {
      await _connector.connect(
        userId: userId,
        tokenProvider: tokenProvider ?? _tokenProvider ?? SessionStore.token,
        subscribeOpsAppointments: subscribeOpsAppointments,
      );
      if (conversationId != null) {
        await _connector.subscribeConversation(conversationId);
      }
    } catch (_) {
      // Socket optional. REST history/send stay available.
    }
    _started = true;
  }

  Future<void> watchConversation(String conversationId) =>
      _connector.subscribeConversation(conversationId);

  Future<void> pause() => _connector.disconnect();

  Future<void> resume({
    required String userId,
    required String userName,
    String? conversationId,
  }) async {
    if (!_started) return;
    await start(
        userId: userId, userName: userName, conversationId: conversationId);
  }

  Future<void> dispose() async {
    await _eventsSub?.cancel();
    _eventsSub = null;
    await _messages.close();
    await _appointmentChanged.close();
    await _campusChanged.close();
    await _connector.disconnect();
    _started = false;
  }

  void _onEvent(Map<String, dynamic> json) {
    if (json['_campusEvent'] == 'appointment.changed') {
      _appointmentChanged.add(null);
      return;
    }
    if (json['_campusEvent'] == 'campus.changed') {
      final raw = json['resources'];
      final resources = raw is List
          ? raw.whereType<String>().toList(growable: false)
          : const <String>[];
      _campusChanged.add(resources);
      return;
    }
    final name = _userName;
    if (name == null) return;
    try {
      _messages.add(ChatMessage.fromJson(json, myName: name));
    } catch (_) {
      // Malformed payload must not take chat down; REST refresh is the fallback.
    }
  }

  /// Insert [incoming] by id, drop a matching optimistic row, keep created order.
  static List<ChatMessage> upsert(
      List<ChatMessage> current, ChatMessage incoming) {
    if (current.any((m) => m.id == incoming.id)) return current;
    final withoutOptimistic = incoming.fromMe
        ? current
            .where((m) => !(m.id.startsWith('tmp-') &&
                m.fromMe &&
                m.text == incoming.text))
            .toList()
        : current;
    return [...withoutOptimistic, incoming];
  }
}
