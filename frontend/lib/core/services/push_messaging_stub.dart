import 'push_payload.dart';

/// Platform messaging. IO uses firebase_messaging; web/tests use this no-op
/// so missing Firebase config cannot break mock or Chrome.
abstract class PushMessaging {
  Future<void> prepare();
  Future<String?> token();
  Stream<String> get tokenRefreshes;
  Stream<PushPayload> get foreground;
  Stream<PushPayload> get opened;
  Future<PushPayload?> initial();
}

PushMessaging defaultPushMessaging() => NoopPushMessaging();

class NoopPushMessaging implements PushMessaging {
  @override
  Future<void> prepare() async {}

  @override
  Future<String?> token() async => null;

  @override
  Stream<String> get tokenRefreshes => const Stream.empty();

  @override
  Stream<PushPayload> get foreground => const Stream.empty();

  @override
  Stream<PushPayload> get opened => const Stream.empty();

  @override
  Future<PushPayload?> initial() async => null;
}
