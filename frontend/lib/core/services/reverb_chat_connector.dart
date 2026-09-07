import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:web_socket_channel/web_socket_channel.dart';

import 'chat_realtime_config.dart';
import 'chat_realtime_service.dart';

/// Pusher-protocol client aimed at Laravel Reverb. Private channel auth uses
/// the Sanctum bearer header — the token is never placed on the WebSocket URL.
///
/// When Reverb is not configured (typical local REST without `php artisan
/// reverb:start`), [connect] is a no-op so chat still works over REST and the
/// console is not flooded with WebSocket retries.
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
  var _opening = false;
  var _failures = 0;
  var _subscribeOpsAppointments = false;
  Timer? _reconnectTimer;

  static const _maxBackoff = Duration(seconds: 60);
  static const _giveUpAfter = 8;

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
    _closed = false;
    _userId = userId;
    _activeTokenProvider = tokenProvider;
    _subscribeOpsAppointments = subscribeOpsAppointments;
    if (!config.isEnabled) return;
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
    _reconnectTimer?.cancel();
    _reconnectTimer = null;
    await _socketSub?.cancel();
    _socketSub = null;
    try {
      await _channel?.sink.close();
    } catch (_) {}
    _channel = null;
    _socketId = null;
    _opening = false;
  }

  Future<void> _openSocket() async {
    if (_closed || !config.isEnabled || _opening) return;
    _opening = true;
    await _socketSub?.cancel();
    _socketSub = null;
    try {
      await _channel?.sink.close();
    } catch (_) {}
    _channel = null;

    try {
      final socket = WebSocketChannel.connect(config.websocketUri);
      _channel = socket;
      // Failures often surface on [ready], not on connect() itself.
      await socket.ready.timeout(const Duration(seconds: 5));
      if (_closed) {
        await socket.sink.close();
        return;
      }
      _failures = 0;
      _socketSub = socket.stream.listen(
        _onFrame,
        onError: (_) => _handleDrop(),
        onDone: _handleDrop,
        cancelOnError: true,
      );
    } catch (_) {
      // REST chat stays usable when the socket cannot be opened.
      _channel = null;
      _scheduleReconnect();
    } finally {
      _opening = false;
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
      return;
    }
    if (event == 'appointment.changed' ||
        event.endsWith('appointment.changed')) {
      final data = _decodeData(frame['data']);
      _events.add({'_campusEvent': 'appointment.changed', ...data});
      return;
    }
    if (event == 'campus.changed' || event.endsWith('campus.changed')) {
      final data = _decodeData(frame['data']);
      _events.add({'_campusEvent': 'campus.changed', ...data});
    }
  }

  Future<void> _afterConnected() async {
    _subscribePublic('campus.live');
    final userId = _userId;
    if (userId != null) {
      await _subscribe('private-user.$userId');
    }
    // Staff-only channel (`appointments.manage`). Students correctly get 403
    // if we always request it — gate so DevTools stays clean.
    if (_subscribeOpsAppointments) {
      await _subscribe('private-ops.appointments');
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

  void _subscribePublic(String channelName) {
    // This carries only collection invalidation metadata. It is intentionally
    // public so every active campus client can refresh public events without
    // exposing a bearer token in the socket URL or a channel name.
    _channel?.sink.add(jsonEncode({
      'event': 'pusher:subscribe',
      'data': {'channel': channelName},
    }));
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
      final auth =
          body is Map<String, dynamic> ? body['auth'] as String? : null;
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
    _scheduleReconnect();
  }

  void _scheduleReconnect() {
    if (_closed || !config.isEnabled) return;
    _reconnectTimer?.cancel();
    _failures += 1;
    if (_failures > _giveUpAfter) {
      // Stop hammering the browser when Reverb is down; one slow retry later.
      _failures = _giveUpAfter;
      _reconnectTimer = Timer(_maxBackoff, () {
        if (_closed) return;
        unawaited(_openSocket());
      });
      return;
    }
    final seconds = 1 << (_failures - 1).clamp(0, 5); // 1,2,4,8,16,32
    final delay = Duration(seconds: seconds > 60 ? 60 : seconds);
    _reconnectTimer = Timer(delay, () {
      if (_closed) return;
      unawaited(_openSocket());
    });
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
