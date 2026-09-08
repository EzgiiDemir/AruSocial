import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/mock_analytics_tracker.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/core/services/url_launcher_map_provider.dart';
import 'package:arucad_campus_prototype/features/campus_shell.dart';

/// The bottom bar has to hold five labels, and the longest of them
/// ("Arucad'a Sor") is what decides whether it looks right.
///
/// Two things break it on a real phone that a simulator at default settings
/// never shows: a narrow device leaves each tab about 70px, and the system
/// font-size setting scales every label without limit. Both are exercised
/// here, because "it looked fine on my machine" is exactly how this shipped.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() => SharedPreferences.setMockInitialValues({}));

  Widget shell() => CampusShell(
        user: const CampusUser(
          id: 'u1',
          name: 'Trainer Test',
          role: 'trainer',
          level: 1,
          xp: 0,
          places: 0,
          events: 0,
          memories: 0,
          interests: [],
        ),
        repository: MockCampusRepository(),
        mapProvider: const UrlLauncherMapProvider(),
        analyticsTracker: MockAnalyticsTracker(),
        onLogout: () {},
      );

  /// Fails on any overflow the framework reports while laying out.
  Future<void> expectNoOverflow(WidgetTester tester, String label) async {
    final errors = <String>[];
    final previous = FlutterError.onError;
    FlutterError.onError = (details) {
      final text = details.exceptionAsString();
      if (text.contains('overflowed')) errors.add(text.split('\n').first);
    };

    await tester.pumpWidget(MaterialApp(home: shell()));
    await tester.pump(const Duration(milliseconds: 300));

    FlutterError.onError = previous;
    expect(errors, isEmpty, reason: '$label overflowed: ${errors.join(' | ')}');
  }

  for (final (w, h, name) in const [
    (360.0, 780.0, 'Galaxy S24 (360px)'),
    (375.0, 667.0, 'iPhone SE (375px)'),
    (393.0, 852.0, 'iPhone 16 (393px)'),
    (320.0, 640.0, 'very narrow (320px)'),
  ]) {
    testWidgets('nav labels fit on $name', (tester) async {
      tester.view.physicalSize = Size(w, h);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await expectNoOverflow(tester, name);
    });
  }

  /// Not only the nav bar: this pumps the whole shell, so anything Home
  /// builds is covered too. That is deliberate — the first run of this test
  /// found an overflow in the survey sheet, not in the bar it was written
  /// for, and a phone at a large font size is exactly where such things
  /// surface while a default-settings simulator shows nothing.
  testWidgets('nothing in the shell overflows at a large system font size',
      (tester) async {
    tester.view.physicalSize = const Size(360, 780);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final errors = <String>[];
    final previous = FlutterError.onError;
    FlutterError.onError = (details) {
      final text = details.exceptionAsString();
      // The widget path is kept in the message: an overflow is only
      // actionable if you know which Row it came from, and hunting for it
      // afterwards costs more than carrying it here.
      if (text.contains('overflowed')) {
        final where = RegExp(r'lib/features/[^\s:]+:\d+')
            .firstMatch(details.toString())?.group(0);
        errors.add('${text.split('\n').first} (${where ?? 'konum bilinmiyor'})');
      }
    };

    // 2.0 is a real accessibility setting, not a hypothetical one.
    await tester.pumpWidget(MediaQuery(
      data: const MediaQueryData(textScaler: TextScaler.linear(2.0)),
      child: MaterialApp(home: shell()),
    ));
    await tester.pump(const Duration(milliseconds: 300));

    FlutterError.onError = previous;
    expect(errors, isEmpty,
        reason: 'Overflowed at 2x text scale: ${errors.join(' | ')}');
  });
}
