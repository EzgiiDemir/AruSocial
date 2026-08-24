import 'package:firebase_messaging/firebase_messaging.dart';

import 'push_messaging_io.dart';

Future<void> bootstrapPush() async {
  FirebaseMessaging.onBackgroundMessage(firebaseMessagingBackgroundHandler);
}
