import 'package:firebase_messaging/firebase_messaging.dart';

import 'push_payload.dart';
import 'push_messaging_stub.dart' hide defaultPushMessaging;

export 'push_messaging_stub.dart' hide defaultPushMessaging;

PushMessaging defaultPushMessaging() => FirebasePushMessaging();

@pragma('vm:entry-point')
Future<void> firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  PushPayload.fromData(
    Map<String, dynamic>.from(message.data),
    title: message.notification?.title,
    body: message.notification?.body,
  );
}

class FirebasePushMessaging implements PushMessaging {
  FirebasePushMessaging({FirebaseMessaging? messaging})
      : _messaging = messaging ?? FirebaseMessaging.instance;

  final FirebaseMessaging _messaging;

  @override
  Future<void> prepare() async {
    await _messaging.requestPermission(alert: true, badge: true, sound: true);
    await _messaging.setForegroundNotificationPresentationOptions(
      alert: false,
      badge: false,
      sound: false,
    );
  }

  @override
  Future<String?> token() => _messaging.getToken();

  @override
  Stream<String> get tokenRefreshes => _messaging.onTokenRefresh;

  @override
  Stream<PushPayload> get foreground => FirebaseMessaging.onMessage.map(_fromMessage);

  @override
  Stream<PushPayload> get opened => FirebaseMessaging.onMessageOpenedApp.map(_fromMessage);

  @override
  Future<PushPayload?> initial() async {
    final message = await _messaging.getInitialMessage();
    return message == null ? null : _fromMessage(message);
  }

  PushPayload _fromMessage(RemoteMessage message) => PushPayload.fromData(
        Map<String, dynamic>.from(message.data),
        title: message.notification?.title,
        body: message.notification?.body,
      );
}
