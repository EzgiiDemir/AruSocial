// Real smoke test against the app's actual root widget — the default
// Flutter counter-app template this replaced referenced a `MyApp` class
// that doesn't exist in this project and never compiled.

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/app/app.dart';
import 'package:arucad_campus_prototype/app/config/app_config.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/mock_analytics_tracker.dart';
import 'package:arucad_campus_prototype/core/services/mock_auth_provider.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/core/services/url_launcher_map_provider.dart';

void main() {
  testWidgets('App boots to the sign-in screen without throwing',
      (tester) async {
    // The privacy notice is a one-time, pre-login gate (see
    // privacy_notice_test.dart) — these tests are about what's behind it.
    SharedPreferences.setMockInitialValues(
        {'settings.privacy_notice.acknowledged': true});
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

  testWidgets('login settings never exposes API host or port controls',
      (tester) async {
    SharedPreferences.setMockInitialValues(
        {'settings.privacy_notice.acknowledged': true});
    await tester.pumpWidget(ArucadCampusApp(
      config: AppConfig.demo(),
      repository: MockCampusRepository(),
      authProvider: MockAuthProvider(),
      mapProvider: const UrlLauncherMapProvider(),
      analyticsTracker: MockAnalyticsTracker(),
    ));
    await tester.pump();
    // Lets PrivacyNoticeGate's async ack-flag lookup resolve so the real
    // sign-in screen (behind it) is what's on screen for this test.
    await tester.pump();
    await tester.tap(find.byIcon(Icons.settings_outlined));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    expect(find.text('Sunucu (Laravel)'), findsNothing);
    expect(find.text('Bilgisayar IP / host'), findsNothing);
    expect(find.text('Port'), findsNothing);
  });

  testWidgets('login form stays readable on a desktop viewport',
      (tester) async {
    tester.view.physicalSize = const Size(1440, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    SharedPreferences.setMockInitialValues(
        {'settings.privacy_notice.acknowledged': true});

    await tester.pumpWidget(ArucadCampusApp(
      config: AppConfig.demo(),
      repository: MockCampusRepository(),
      authProvider: MockAuthProvider(),
      mapProvider: const UrlLauncherMapProvider(),
      analyticsTracker: MockAnalyticsTracker(),
    ));
    await tester.pumpAndSettle();

    final identifier = find.byType(TextField).first;
    expect(tester.getSize(identifier).width, lessThanOrEqualTo(464));
    expect(tester.getCenter(identifier).dx, closeTo(720, 1));
  });

  test('no-route-to-host is explained instead of a raw SocketException', () {
    final message = describeNetworkFailure(
      'ClientException with SocketException: No route to host (OS Error: No route to host, errno = 113), address = 192.168.161.247, port = 56552',
    );
    expect(message, contains('ağ'));
    expect(message.toLowerCase(), isNot(contains('socketexception')));
  });

  test('timeout is explained without IPv6 worker advice', () {
    final message = describeNetworkFailure(
      TimeoutException('Request timed out', const Duration(seconds: 20)),
    );
    expect(message, contains('tekrar deneyin'));
    expect(message, isNot(contains('127.0.0.1')));
    expect(message.toLowerCase(), isNot(contains('ipv6')));
    expect(message, isNot(contains('PHP_CLI_SERVER_WORKERS')));
  });
}
