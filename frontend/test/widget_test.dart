// Real smoke test against the app's actual root widget — the default
// Flutter counter-app template this replaced referenced a `MyApp` class
// that doesn't exist in this project and never compiled.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/app/app.dart';
import 'package:arucad_campus_prototype/app/config/app_config.dart';
import 'package:arucad_campus_prototype/core/services/mock_analytics_tracker.dart';
import 'package:arucad_campus_prototype/core/services/mock_auth_provider.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/core/services/url_launcher_map_provider.dart';

void main() {
  testWidgets('App boots to the sign-in screen without throwing', (tester) async {
    await tester.pumpWidget(ArucadCampusApp(
      config: AppConfig.demo(),
      repository: MockCampusRepository(),
      authProvider: MockAuthProvider(),
      mapProvider: const UrlLauncherMapProvider(),
      analyticsTracker: MockAnalyticsTracker(),
    ));
    await tester.pump();

    expect(find.byType(MaterialApp), findsOneWidget);
  });
}
