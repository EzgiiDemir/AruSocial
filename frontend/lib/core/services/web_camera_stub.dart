import 'dart:typed_data';

import 'package:flutter/widgets.dart';

/// Non-web builds: the platform camera is handled by `image_picker`, which
/// opens the real camera app on Android and iOS. Nothing here is used.
bool get webCameraAvailable => false;

/// Only meaningful on the web, where the browser refuses camera access
/// outside a secure context.
bool get webContextIsSecure => true;

Future<Uint8List?> captureFromWebCamera(BuildContext context) async => null;
