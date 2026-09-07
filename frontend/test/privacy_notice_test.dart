import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/auth/app_settings_store.dart';
import 'package:arucad_campus_prototype/features/auth/privacy_notice_screen.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  testWidgets('shows the privacy notice before the child on first launch',
      (tester) async {
    await tester.pumpWidget(const MaterialApp(
      home: PrivacyNoticeGate(child: Scaffold(body: Text('Behind the gate'))),
    ));
    await tester.pump();
    await tester.pump();

    expect(find.text('Behind the gate'), findsNothing);
    expect(find.textContaining('Privacy'), findsWidgets);
  });

  testWidgets('acknowledging shows the child and persists across relaunch',
      (tester) async {
    await tester.pumpWidget(const MaterialApp(
      home: PrivacyNoticeGate(child: Scaffold(body: Text('Behind the gate'))),
    ));
    await tester.pump();
    await tester.pump();

    await tester.tap(find.byType(FilledButton));
    await tester.pump();

    expect(find.text('Behind the gate'), findsOneWidget);
    expect(await AppSettingsStore.privacyNoticeAcknowledged(), isTrue);

    // A fresh gate widget (simulating relaunch) skips straight to the child.
    await tester.pumpWidget(const MaterialApp(
      home: PrivacyNoticeGate(child: Scaffold(body: Text('Behind the gate'))),
    ));
    await tester.pump();
    await tester.pump();

    expect(find.text('Behind the gate'), findsOneWidget);
  });

  testWidgets('falls back to English for an unsupported device locale',
      (tester) async {
    tester.platformDispatcher.localeTestValue = const Locale('fr');
    addTearDown(tester.platformDispatcher.clearLocaleTestValue);

    await tester.pumpWidget(const MaterialApp(
      home: PrivacyNoticeGate(child: SizedBox.shrink()),
    ));
    await tester.pump();
    await tester.pump();

    expect(find.text('Your Privacy Is Our Priority'), findsOneWidget);
  });

  testWidgets('shows the Russian notice for a Russian device locale',
      (tester) async {
    tester.platformDispatcher.localeTestValue = const Locale('ru');
    addTearDown(tester.platformDispatcher.clearLocaleTestValue);

    await tester.pumpWidget(const MaterialApp(
      home: PrivacyNoticeGate(child: SizedBox.shrink()),
    ));
    await tester.pump();
    await tester.pump();

    expect(find.text('Ваша конфиденциальность — наш приоритет'), findsOneWidget);
  });
}
