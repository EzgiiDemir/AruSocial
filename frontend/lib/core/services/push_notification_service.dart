import 'dart:async';

import 'package:flutter/foundation.dart';

import 'contracts.dart';
import 'push_messaging.dart';
import 'push_payload.dart';
import 'rest_campus_repository.dart';

/// Registers the FCM token with the existing `/push-tokens` contract.
/// Mock mode is a no-op. Foreground payloads are exposed but not turned
/// into OS banners (Reverb owns in-app chat). Taps go to [opened].
class PushNotificationService {
  PushNotificationService({
    required CampusRepository repository,
    required PushMessaging messaging,
  })  : _repository = repository,
        _messaging = messaging;

  final CampusRepository _repository;
  final PushMessaging _messaging;
  final _opened = StreamController<PushPayload>.broadcast();
  final _foreground = StreamController<PushPayload>.broadcast();
  final _seen = <String>{};
  final _subs = <StreamSubscription>[];
  String? _token;
  var _started = false;

  Stream<PushPayload> get opened => _opened.stream;
  Stream<PushPayload> get foreground => _foreground.stream;

  static PushNotificationService forRepository(
    CampusRepository repository, {
    PushMessaging? messaging,
  }) {
    if (repository is! RestCampusRepository || kIsWeb) {
      return PushNotificationService(
        repository: repository,
        messaging: messaging ?? NoopPushMessaging(),
      );
    }
    return PushNotificationService(
      repository: repository,
      messaging: messaging ?? defaultPushMessaging(),
    );
  }

  Future<void> start() async {
    if (_started) return;
    _started = true;
    try {
      await _messaging.prepare();
      _subs.add(_messaging.tokenRefreshes.listen(_register));
      _subs.add(_messaging.foreground.listen(_onForeground));
      _subs.add(_messaging.opened.listen(_emitOpened));
      final token = await _messaging.token();
      if (token != null) await _register(token);
      final initial = await _messaging.initial();
      if (initial != null) _emitOpened(initial);
    } catch (_) {
      // Missing google-services / APNs must not take the app down.
    }
  }

  Future<void> stopAndUnregister() async {
    final token = _token;
    _token = null;
    for (final sub in _subs) {
      await sub.cancel();
    }
    _subs.clear();
    _started = false;
    if (token != null) {
      try {
        await _repository.unregisterPushToken(token);
      } catch (_) {}
    }
  }

  Future<void> dispose() async {
    for (final sub in _subs) {
      await sub.cancel();
    }
    _subs.clear();
    _started = false;
    await _opened.close();
    await _foreground.close();
  }

  Future<void> _register(String token) async {
    _token = token;
    await _repository.registerPushToken(token: token, platform: _platform);
  }

  void _onForeground(PushPayload payload) {
    if (!_remember(payload)) return;
    // No OS notification while the app is open — Reverb already paints chat.
    _foreground.add(payload);
  }

  void _emitOpened(PushPayload payload) {
    if (!_remember(payload)) return;
    _opened.add(payload);
  }

  bool _remember(PushPayload payload) {
    final id = payload.notificationId ?? payload.messageId;
    if (id == null) return true;
    if (_seen.contains(id)) return false;
    _seen.add(id);
    return true;
  }

  String get _platform {
    if (kIsWeb) return 'web';
    switch (defaultTargetPlatform) {
      case TargetPlatform.iOS:
        return 'ios';
      default:
        return 'android';
    }
  }
}
