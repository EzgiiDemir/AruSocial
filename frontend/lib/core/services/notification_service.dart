import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

/// Local (on-device) notifications — e.g. confirming a check-in. This is not
/// a push notification system: it can only notify the same device/user that
/// triggered it, never other people. `flutter_local_notifications` also has
/// no web implementation, so calls here are no-ops on web instead of
/// throwing; the caller should still show its own in-app confirmation (a
/// SnackBar, etc.) so the user isn't left without feedback on web.
class NotificationService {
  NotificationService._();
  static final NotificationService instance = NotificationService._();
  factory NotificationService() => instance;

  final FlutterLocalNotificationsPlugin _plugin =
      FlutterLocalNotificationsPlugin();
  bool _initialized = false;

  Future<void> init() async {
    if (kIsWeb || _initialized) return;
    try {
      const android = AndroidInitializationSettings('@mipmap/ic_launcher');
      const ios = DarwinInitializationSettings();
      await _plugin.initialize(
          settings: const InitializationSettings(android: android, iOS: ios));
      _initialized = true;
    } catch (_) {
      // Platform doesn't support local notifications; ignore.
    }
  }

  Future<void> showSimple(String title, String body) async {
    if (kIsWeb) return;
    await init();
    try {
      const android = AndroidNotificationDetails('arucad', 'ARUCAD',
          importance: Importance.defaultImportance);
      const ios = DarwinNotificationDetails();
      await _plugin.show(
        id: DateTime.now().millisecondsSinceEpoch ~/ 1000,
        title: title,
        body: body,
        notificationDetails: const NotificationDetails(android: android, iOS: ios),
      );
    } catch (_) {
      // Best-effort only — the caller shows its own in-app confirmation too.
    }
  }
}
