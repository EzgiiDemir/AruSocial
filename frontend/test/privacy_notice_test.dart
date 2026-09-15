import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/auth/app_settings_store.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/features/auth/privacy_notice_screen.dart';
import 'package:arucad_campus_prototype/features/legal/legal_document_screen.dart';

/// The gate in front of the app.
///
/// Two separate things are checked here and they are easy to conflate: that
/// the notice is *shown*, and that it cannot be *walked past*. The second is
/// the one with legal weight — a screen someone dismisses with one tap
/// without ticking anything records nothing about consent.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});

    // `rootBundle` caches per asset and the cache outlives a test. A load
    // started by one test and abandoned when its zone was torn down leaves
    // an entry that never completes, so a later test reading the same asset
    // hangs on it and renders as though the file were missing.
    rootBundle.clear();
  });

  /// Alternates real time with frames.
  ///
  /// The gate reads its acknowledgement flag asynchronously, and a document
  /// page then reads a bundled asset. Both complete only on the real event
  /// loop (`runAsync`), while the `setState` each one triggers is applied
  /// only by a `pump` — so neither a pump loop nor a single `runAsync` gets
  /// there on its own.
  Future<void> settle(WidgetTester tester) async {
    for (var i = 0; i < 8; i++) {
      await tester.runAsync(
        () => Future<void>.delayed(const Duration(milliseconds: 15)),
      );
      await tester.pump();
    }
  }

  Future<void> show(WidgetTester tester, Widget widget) async {
    // A tall surface, because the content sits in a scroll view and a
    // ListView does not build what is below the fold: on the default 800px
    // test window half the screen is simply not in the widget tree, which
    // is indistinguishable from it not being implemented.
    tester.view.physicalSize = const Size(1200, 3000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(widget);
    await settle(tester);
  }

  Widget gate([Widget child = const Scaffold(body: Text('Behind the gate'))]) =>
      MaterialApp(home: PrivacyNoticeGate(child: child));

  Widget screenIn(AppLanguage language) => MaterialApp(
        home: PrivacyNoticeScreen(onAccept: () async {}, language: language),
      );

  /// Opens each document, scrolls it to the bottom and comes back.
  ///
  /// This is what the consent gate now requires, so almost every test that
  /// wants to reach the app has to do it. On the very tall test surface a
  /// document may already fit without scrolling, which counts as read —
  /// the drag is harmless in that case.
  Future<void> readBothDocuments(WidgetTester tester) async {
    for (final label in ['Privacy Policy', 'Community Guidelines']) {
      await tester.tap(find.text(label));
      await settle(tester);

      // Far enough to reach the end of any of these documents.
      await tester.drag(find.byType(ListView).last, const Offset(0, -20000));
      await settle(tester);

      await tester.pageBack();
      await settle(tester);
    }
  }

  // ---- the part with legal weight ------------------------------------

  testWidgets('Continue does nothing until the box is ticked', (tester) async {
    await show(tester, gate());

    final button = tester.widget<FilledButton>(find.byType(FilledButton));
    expect(button.onPressed, isNull,
        reason: 'Continue was live before anyone accepted anything.');

    await tester.tap(find.byType(FilledButton));
    await settle(tester);

    expect(find.text('Behind the gate'), findsNothing);
    expect(await AppSettingsStore.privacyNoticeAcknowledged(), isFalse);
  });

  testWidgets('ticking the box and continuing opens the app', (tester) async {
    await show(tester, gate());
    await readBothDocuments(tester);

    await tester.tap(find.byType(Checkbox));
    await settle(tester);

    expect(tester.widget<FilledButton>(find.byType(FilledButton)).onPressed,
        isNotNull);

    await tester.tap(find.byType(FilledButton));
    await settle(tester);

    expect(find.text('Behind the gate'), findsOneWidget);
    expect(await AppSettingsStore.privacyNoticeAcknowledged(), isTrue);
  });

  /// The label is the tap target too. A 26px box is a small thing to hit on
  /// a phone, and this is the only control between a student and the app.
  testWidgets('tapping the label toggles the checkbox', (tester) async {
    await show(tester, gate());
    await readBothDocuments(tester);

    await tester.tap(find.textContaining('I have read and agree'));
    await settle(tester);

    expect(tester.widget<Checkbox>(find.byType(Checkbox)).value, isTrue);
  });

  testWidgets('acceptance survives a relaunch', (tester) async {
    await show(tester, gate());
    await readBothDocuments(tester);
    await tester.tap(find.byType(Checkbox));
    await settle(tester);
    await tester.tap(find.byType(FilledButton));
    await settle(tester);

    // A fresh gate widget, as on the next launch.
    await show(tester, gate());

    expect(find.text('Behind the gate'), findsOneWidget);
  });

  // ---- reading the documents before agreeing ---------------------------

  /// The point of the whole gate: consent recorded from someone who tapped
  /// a link and came straight back is not evidence that they were told
  /// anything.
  testWidgets('the box cannot be ticked before either document is read',
      (tester) async {
    await show(tester, gate());

    expect(tester.widget<Checkbox>(find.byType(Checkbox)).onChanged, isNull,
        reason: 'The box was tickable before anything had been read.');

    await tester.tap(find.byType(Checkbox), warnIfMissed: false);
    await settle(tester);

    expect(tester.widget<Checkbox>(find.byType(Checkbox)).value, isFalse);
    expect(tester.widget<FilledButton>(find.byType(FilledButton)).onPressed,
        isNull);
  });

  testWidgets('reading only one document is not enough', (tester) async {
    await show(tester, gate());

    await tester.tap(find.text('Privacy Policy'));
    await settle(tester);
    await tester.drag(find.byType(ListView).last, const Offset(0, -20000));
    await settle(tester);
    await tester.pageBack();
    await settle(tester);

    expect(tester.widget<Checkbox>(find.byType(Checkbox)).onChanged, isNull,
        reason: 'One document read should not unlock consent.');
  });

  testWidgets('reading both documents unlocks the box', (tester) async {
    await show(tester, gate());
    await readBothDocuments(tester);

    expect(tester.widget<Checkbox>(find.byType(Checkbox)).onChanged, isNotNull);

    // Still not through: reading is not the same as agreeing.
    expect(tester.widget<FilledButton>(find.byType(FilledButton)).onPressed,
        isNull);
  });

  testWidgets('a document that has been read is marked as read',
      (tester) async {
    await show(tester, gate());

    expect(find.byIcon(Icons.check_circle), findsNothing);

    await readBothDocuments(tester);

    expect(find.byIcon(Icons.check_circle), findsNWidgets(2));
  });

  testWidgets('the screen says why the box is not yet tickable',
      (tester) async {
    await show(tester, screenIn(AppLanguage.en));

    expect(
      find.textContaining('read them to the end'),
      findsOneWidget,
      reason: 'A disabled control with no explanation reads as a bug.',
    );
  });

  testWidgets('the explanation goes away once both are read', (tester) async {
    await show(tester, gate());
    await readBothDocuments(tester);

    expect(find.textContaining('read them to the end'), findsNothing);
  });

  /// Only while a consent flow is waiting. Opened from Settings there is
  /// nothing to unlock and the prompt would just be nagging.
  testWidgets('the scroll prompt appears only when consent is waiting',
      (tester) async {
    await show(tester, screenIn(AppLanguage.en));
    await tester.tap(find.text('Privacy Policy'));
    await settle(tester);

    expect(find.text('Scroll to the end'), findsOneWidget);

    await tester.pageBack();
    await settle(tester);

    await show(
      tester,
      const MaterialApp(
        home: LegalDocumentScreen(
          title: 'Privacy Policy',
          assetPath: 'assets/legal/privacy.md',
          language: AppLanguage.en,
        ),
      ),
    );

    expect(find.text('Scroll to the end'), findsNothing);
  });

  // ---- what is on the screen -------------------------------------------

  testWidgets('the moderation summary is on the screen', (tester) async {
    await show(tester, screenIn(AppLanguage.en));

    expect(find.text('How moderation works'), findsOneWidget);
    expect(find.textContaining('automated safety systems'), findsOneWidget);
    expect(find.textContaining('Authorized moderators'), findsOneWidget);
    expect(find.textContaining('university authorities'), findsOneWidget);
  });

  testWidgets('both documents are named and reachable before agreeing',
      (tester) async {
    await show(tester, screenIn(AppLanguage.en));

    expect(find.text('Privacy Policy'), findsOneWidget);
    expect(find.text('Community Guidelines'), findsOneWidget);
  });

  /// The helper line under the button was removed on request; the disabled
  /// button is the affordance now.
  testWidgets('there is no "tick the box" helper line', (tester) async {
    await show(tester, screenIn(AppLanguage.en));

    expect(find.textContaining('Select the checkbox'), findsNothing);
    expect(find.textContaining('Tick the box'), findsNothing);
  });

  testWidgets('the screen is translated', (tester) async {
    await show(tester, screenIn(AppLanguage.tr));
    expect(find.text('Gizliliğiniz ve Güvenliğiniz'), findsOneWidget);
    expect(find.text('Moderasyon nasıl işler'), findsOneWidget);
    expect(find.text('Topluluk Kuralları'), findsOneWidget);

    await show(tester, screenIn(AppLanguage.ru));
    expect(find.text('Как работает модерация'), findsOneWidget);
    expect(find.text('Правила сообщества'), findsOneWidget);
  });

  // ---- the documents themselves ----------------------------------------

  testWidgets('opening the Privacy Policy shows it with a way back',
      (tester) async {
    await show(tester, screenIn(AppLanguage.en));

    await tester.tap(find.text('Privacy Policy'));
    await settle(tester);

    // Real content out of the published file, not a placeholder.
    expect(find.textContaining('Information We Collect'), findsOneWidget);

    // The wordmark, so it still reads as the same app.
    expect(find.byType(Image), findsWidgets);

    await tester.tap(find.byIcon(Icons.arrow_back));
    await settle(tester);

    expect(find.text('How moderation works'), findsOneWidget,
        reason: 'Back should return to the consent screen.');
  });

  testWidgets('opening the Community Guidelines shows it with a way back',
      (tester) async {
    await show(tester, screenIn(AppLanguage.en));

    await tester.tap(find.text('Community Guidelines'));
    await settle(tester);

    expect(find.textContaining('Treat Others With Respect'), findsOneWidget);

    await tester.tap(find.byIcon(Icons.arrow_back));
    await settle(tester);

    expect(find.text('How moderation works'), findsOneWidget);
  });

  /// A document opened from the consent screen has to arrive in the same
  /// language as the screen that linked to it, which is the device locale —
  /// not the in-app picker, which nobody has touched yet.
  testWidgets('a document opens in the language of the screen that linked it',
      (tester) async {
    await show(tester, screenIn(AppLanguage.tr));

    await tester.tap(find.text('Gizlilik Politikası'));
    await settle(tester);

    expect(find.textContaining('Topladığımız Bilgiler'), findsOneWidget);
  });

  /// The previous version dumped the raw file into one Text, asterisks and
  /// hashes included. That is what a student read before agreeing to it.
  testWidgets('the document is rendered, not dumped as raw markdown',
      (tester) async {
    await show(tester, screenIn(AppLanguage.en));

    await tester.tap(find.text('Privacy Policy'));
    await settle(tester);

    expect(find.textContaining('## '), findsNothing);
    expect(find.textContaining('**'), findsNothing);
  });

  // ---- language selection ----------------------------------------------

  test('the device locale maps to the three supported languages', () {
    expect(noticeLanguageFor(const Locale('tr')), AppLanguage.tr);
    expect(noticeLanguageFor(const Locale('en')), AppLanguage.en);
    expect(noticeLanguageFor(const Locale('ru')), AppLanguage.ru);
    expect(noticeLanguageFor(const Locale('de')), AppLanguage.en);
  });

  testWidgets('falls back to English for an unsupported device locale',
      (tester) async {
    tester.platformDispatcher.localeTestValue = const Locale('fr');
    addTearDown(tester.platformDispatcher.clearLocaleTestValue);

    await show(tester, gate(const SizedBox.shrink()));

    expect(find.text('Your Privacy & Safety'), findsOneWidget);
  });

  // ---- re-acceptance after a change ------------------------------------

  testWidgets('an updated policy says so instead of looking like first run',
      (tester) async {
    await show(
      tester,
      MaterialApp(
        home: PrivacyNoticeScreen(
          onAccept: () async {},
          language: AppLanguage.en,
          policyChanged: true,
        ),
      ),
    );

    expect(find.text('Our policy has been updated'), findsOneWidget);
    expect(find.text('Your Privacy & Safety'), findsNothing);

    // Still gated: an update is re-consent, not a notification.
    expect(tester.widget<FilledButton>(find.byType(FilledButton)).onPressed,
        isNull);
  });

  // ---- the renderer ----------------------------------------------------

  group('LegalMarkdown', () {
    test('drops the document title, which the screen already shows', () {
      expect(LegalMarkdown.render('# Privacy Policy\n\nBody.\n'), hasLength(1));
    });

    test('renders headings, bullets and paragraphs as separate blocks', () {
      final widgets = LegalMarkdown.render(
        '## One\n\nA paragraph\nwrapped over lines.\n\n- first\n- second\n',
      );

      // heading + paragraph + two bullets
      expect(widgets, hasLength(4));
    });

    test('an empty document renders nothing rather than throwing', () {
      expect(LegalMarkdown.render(''), isEmpty);
    });
  });
}
