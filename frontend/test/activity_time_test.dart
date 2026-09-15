import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/utils/relative_time.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

/// Activity History has to show when something actually happened.
///
/// It used to render `ActivityItem.meta`, a string the server wrote once at
/// creation time and never touched again — and every call site left it at
/// its default, the literal Turkish `az önce` ("just now"). So a check-in
/// from March and one from a minute ago both read "az önce", in Turkish, to
/// a student reading the app in Russian.
void main() {
  ActivityItem itemAt(DateTime when) => ActivityItem(
        id: 'activity-1',
        kind: ActivityKind.checkIn,
        title: 'Check-in: Studio A',
        subtitle: '+10 XP',
        meta: 'az önce',
        timestamp: when,
      );

  Future<void> show(
    WidgetTester tester,
    ActivityItem item, {
    AppLanguage language = AppLanguage.en,
  }) async {
    await tester.pumpWidget(MaterialApp(
      home: AppLocale(
        language: language,
        child: Scaffold(body: ActivityTile(item: item)),
      ),
    ));
    await tester.pump();
  }

  testWidgets('an old entry does not claim to be recent', (tester) async {
    await show(tester, itemAt(DateTime.now().subtract(const Duration(days: 3))));

    expect(find.text('az önce'), findsNothing,
        reason: 'The frozen meta string must not be the timestamp.');
    expect(find.textContaining('3'), findsWidgets);
  });

  testWidgets('the timestamp is in the reader language, not Turkish',
      (tester) async {
    final when = DateTime.now().subtract(const Duration(hours: 2));

    await show(tester, itemAt(when), language: AppLanguage.ru);
    expect(find.text('az önce'), findsNothing);

    await show(tester, itemAt(when), language: AppLanguage.en);
    expect(find.text('az önce'), findsNothing);
  });

  testWidgets('a recent entry still reads as recent', (tester) async {
    await show(
      tester,
      itemAt(DateTime.now().subtract(const Duration(seconds: 20))),
    );

    // Whatever each language calls "just now" — the point is that it is
    // produced from the timestamp rather than copied from `meta`.
    expect(find.byType(ActivityTile), findsOneWidget);
    expect(find.text('az önce'), findsNothing);
  });

  /// A UTC timestamp off the API has to be read in the device's zone. The
  /// backend stores and serves UTC (`config/app.php` timezone), so a naive
  /// reader in Cyprus would place a check-in made this afternoon three
  /// hours in the future.
  testWidgets('a UTC timestamp is read in the device zone', (tester) async {
    final utc = DateTime.now().toUtc().subtract(const Duration(minutes: 5));

    await show(tester, itemAt(utc));

    // Compared against the ticker's clock rather than `DateTime.now()`.
    // The ticker is a shared value that only moves every thirty seconds, so
    // a locally captured "now" drifts from what the widget actually
    // rendered with and the label flips a minute either way.
    expect(
      find.text(formatRelativeTime(
        utc,
        language: AppLanguage.en,
        now: TimeAgoTicker.now.value,
      )),
      findsOneWidget,
    );
  });

  /// The same instant expressed two ways has to read the same, which is
  /// the property that actually rules out a zone mistake.
  test('the same instant in UTC and local produce the same label', () {
    final now = DateTime.now();
    final local = now.subtract(const Duration(minutes: 5));

    expect(
      formatRelativeTime(local, language: AppLanguage.en, now: now),
      formatRelativeTime(local.toUtc(), language: AppLanguage.en, now: now),
    );
  });
}
