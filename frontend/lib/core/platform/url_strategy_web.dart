import 'package:flutter_web_plugins/flutter_web_plugins.dart';

/// Clean `/admin` style URLs on web (no `#/`) — see main.dart's
/// `startInAdminMode` handling for why this matters.
void configureUrlStrategy() => usePathUrlStrategy();
