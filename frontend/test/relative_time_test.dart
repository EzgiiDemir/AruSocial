import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/utils/relative_time.dart';

/// Elapsed-time labels must be in the reader's language and must move.
///
/// Both were wrong: the label was hardcoded Turkish, so an English or
/// Russian reader saw "5 dk önce"; and it was computed once at build time,
/// so a post opened at 14:00 still read "şimdi" at 14:40 unless something
/// unrelated rebuilt the screen.
void main() {
  Widget wrap(AppLanguage language, Widget child) => AppLocale(
        language: language,
        child: MaterialApp(home: Scaffold(body: child)),
      );

  group('formatRelativeTime speaks the reader', () {
    final at = DateTime.now().subtract(const Duration(minutes: 5));

    test('Turkish', () {
      expect(formatRelativeTime(at, language: AppLanguage.tr), '5 dk önce');
    });

    test('English', () {
      expect(formatRelativeTime(at, language: AppLanguage.en), '5m ago');
    });

    test('Russian', () {
      expect(formatRelativeTime(at, language: AppLanguage.ru), '5 мин назад');
    });

    test('the present tense is translated too', () {
      final now = DateTime.now();
      expect(formatRelativeTime(now, language: AppLanguage.en), 'now');
      expect(formatRelativeTime(now, language: AppLanguage.ru), 'сейчас');
    });
  });

  test('a UTC timestamp is read in the device time zone', () {
    // The API sends UTC. Formatting it without converting would be wrong by
    // the whole offset — three hours, for a campus in Cyprus.
    final utc = DateTime.now().toUtc().subtract(const Duration(hours: 2));

    expect(formatRelativeTime(utc, language: AppLanguage.en), '2h ago');
  });

  test('an old timestamp becomes a date in that language\'s format', () {
    final old = DateTime(2026, 3, 14, 9, 30);

    // Turkish and Russian write day-first; US English writes month-first.
    // 14 March, so the two orders are distinguishable.
    expect(formatRelativeTime(old, language: AppLanguage.tr), '14.03.2026');
    expect(formatRelativeTime(old, language: AppLanguage.ru), '14.03.2026');
    expect(formatRelativeTime(old, language: AppLanguage.en), '03/14/2026');
  });

  testWidgets('LiveTimeAgo advances without the screen being rebuilt',
      (tester) async {
    final at = DateTime.now();

    await tester.pumpWidget(wrap(AppLanguage.en, LiveTimeAgo(at)));
    expect(find.text('now'), findsOneWidget);

    // Nothing rebuilds the screen — only the shared clock moves.
    TimeAgoTicker.advanceForTest(const Duration(minutes: 6));
    await tester.pump();

    expect(find.text('now'), findsNothing);
    expect(find.text('6m ago'), findsOneWidget);
  });

  testWidgets('the label follows the chosen language', (tester) async {
    final at = DateTime.now().subtract(const Duration(hours: 3));

    await tester.pumpWidget(wrap(AppLanguage.ru, LiveTimeAgo(at)));
    expect(find.text('3 ч назад'), findsOneWidget);
  });

  testWidgets('the shared timer runs only while a label is on screen',
      (tester) async {
    // One timer for the whole app, started by the first label and stopped
    // by the last — a feed of fifty posts must not mean fifty timers, and a
    // screen with no timestamps should cost nothing.
    await tester.pumpWidget(wrap(AppLanguage.en, LiveTimeAgo(DateTime.now())));
    expect(TimeAgoTicker.isRunning, isTrue);

    await tester.pumpWidget(wrap(AppLanguage.en, const SizedBox.shrink()));
    await tester.pump();
    expect(TimeAgoTicker.isRunning, isFalse,
        reason: 'The ticker outlived the last label on screen.');
  });
}
