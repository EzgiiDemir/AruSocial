import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:web_socket_channel/web_socket_channel.dart';

import 'chat_realtime_config.dart';
import 'chat_realtime_service.dart';

/// Pusher-protocol client aimed at Laravel Reverb. Private channel auth uses
/// the Sanctum bearer header — the token is never placed on the WebSocket URL.
class ReverbChatConnector implements ChatRealtimeConnector {
  ReverbChatConnector({
    required this.config,
    required Future<String?> Function() tokenProvider,
    http.Client? httpClient,
  })  : _tokenProvider = tokenProvider,
        _http = httpClient ?? http.Client();

  final ChatRealtimeConfig config;
  final Future<String?> Function() _tokenProvider;
  final http.Client _http;

  final _events = StreamController<Map<String, dynamic>>.broadcast();
  final _reconnected = StreamController<void>.broadcast();

  WebSocketChannel? _channel;
  StreamSubscription? _socketSub;
  Future<String?> Function()? _activeTokenProvider;
  String? _userId;
  String? _socketId;
  String? _conversationId;
  var _sawDisconnect = false;
  var _closed = false;

  @override
  Stream<Map<String, dynamic>> get events => _events.stream;

  @override
  Stream<void> get reconnected => _reconnected.stream;

  @override
  Future<void> connect({
    required String userId,
    required Future<String?> Function() tokenProvider,
  }) async {
    _closed = false;
    _userId = userId;
    _activeTokenProvider = tokenProvider;
    await _openSocket();
  }

  @override
  Future<void> subscribeConversation(String conversationId) async {
    _conversationId = conversationId;
    if (_socketId == null) return;
    await _subscribe('private-conversation.$conversationId');
  }

  @override
  Future<void> disconnect() async {
    _closed = true;
    await _socketSub?.cancel();
    _socketSub = null;
    await _channel?.sink.close();
    _channel = null;
    _socketId = null;
  }

  Future<void> _openSocket() async {
    try {
      final socket = WebSocketChannel.connect(config.websocketUri);
      _channel = socket;
      _socketSub = socket.stream.listen(
        _onFrame,
        onError: (_) => _handleDrop(),
        onDone: _handleDrop,
      );
    } catch (_) {
      // REST chat stays usable when the socket cannot be opened.
    }
  }

  void _onFrame(dynamic raw) {
    Map<String, dynamic> frame;
    try {
      frame = jsonDecode(raw as String) as Map<String, dynamic>;
    } catch (_) {
      return;
    }
    final event = frame['event'] as String? ?? '';
    if (event == 'pusher:connection_established') {
      final data = _decodeData(frame['data']);
      _socketId = data['socket_id'] as String?;
      unawaited(_afterConnected());
      return;
    }
    if (event == 'message.created' || event.endsWith('message.created')) {
      final data = _decodeData(frame['data']);
      if (data.isNotEmpty) _events.add(data);
    }
  }

  Future<void> _afterConnected() async {
    final userId = _userId;
    if (userId != null) {
      await _subscribe('private-user.$userId');
    }
    final conversationId = _conversationId;
    if (conversationId != null) {
      await _subscribe('private-conversation.$conversationId');
    }
    if (_sawDisconnect) {
      _sawDisconnect = false;
      _reconnected.add(null);
    }
  }

  Future<void> _subscribe(String channelName) async {
    final socketId = _socketId;
    final token = await (_activeTokenProvider ?? _tokenProvider)();
    if (socketId == null || token == null || token.isEmpty) return;
    try {
      final response = await _http.post(
        config.authUri,
        headers: {
          'Authorization': 'Bearer $token',
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
        body: jsonEncode({
          'socket_id': socketId,
          'channel_name': channelName,
        }),
      );
      if (response.statusCode < 200 || response.statusCode >= 300) return;
      final body = jsonDecode(response.body);
      final auth = body is Map<String, dynamic> ? body['auth'] as String? : null;
      if (auth == null) return;
      _channel?.sink.add(jsonEncode({
        'event': 'pusher:subscribe',
        'data': {
          'channel': channelName,
          'auth': auth,
        },
      }));
    } catch (_) {
      // Subscription failure is silent; history refresh covers missed events.
    }
  }

  void _handleDrop() {
    if (_closed) return;
    _sawDisconnect = true;
    _socketId = null;
    unawaited(Future<void>.delayed(const Duration(seconds: 1), () async {
      if (_closed) return;
      await _openSocket();
    }));
  }

  Map<String, dynamic> _decodeData(dynamic data) {
    if (data is Map<String, dynamic>) return data;
    if (data is String) {
      try {
        final decoded = jsonDecode(data);
        if (decoded is Map<String, dynamic>) return decoded;
      } catch (_) {}
    }
    return const {};
  }
}
