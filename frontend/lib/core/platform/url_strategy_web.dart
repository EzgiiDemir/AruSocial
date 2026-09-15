import 'dart:html' as html;

import 'package:flutter_web_plugins/flutter_web_plugins.dart';

/// The Flutter web build is the student application only. Admin and trainer
/// are served by Laravel/Filament on the backend origin, so keeping legacy
/// Flutter URLs would expose two different-looking management products.
void configureUrlStrategy() {
  usePathUrlStrategy();

  final path = Uri.base.path.toLowerCase();
  if (path == '/admin' ||
      path.startsWith('/admin/') ||
      path == '/trainer' ||
      path.startsWith('/trainer/')) {
    html.window.history.replaceState(null, '', '/');
  }
}
