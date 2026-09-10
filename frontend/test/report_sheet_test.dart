import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/report_reason.dart';
import 'package:arucad_campus_prototype/features/widgets/appeal_sheet.dart';
import 'package:arucad_campus_prototype/features/widgets/report_sheet.dart';

/// Wraps a sheet-opening callback in enough app to render.
Widget _host(void Function(BuildContext) onPressed,
    {AppLanguage language = AppLanguage.tr}) {
  return AppLocale(
    language: language,
    child: MaterialApp(
      home: Scaffold(
        body: Builder(
          builder: (context) => TextButton(
            onPressed: () => onPressed(context),
            child: const Text('open'),
          ),
        ),
      ),
    ),
  );
}

void main() {
  group('report sheet', () {
    testWidgets('cannot be submitted until a reason is chosen', (tester) async {
      var submitted = false;

      await tester.pumpWidget(_host((context) => showReportSheet(
            context,
            targetLabel: 'bir gönderi',
            onSubmit: (_) async => submitted = true,
          )));
      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();

      final strings = AppStrings(AppLanguage.tr);
      final send = find.widgetWithText(FilledButton, strings.t('social_send'));

      // Send is disabled with nothing selected. Allowing it would push
      // everything into `other`, which is the untriageable pile the reason
      // codes exist to prevent.
      expect(tester.widget<FilledButton>(send).onPressed, isNull);

      await tester.tap(find.text(ReportReason.hate.label(strings)));
      await tester.pumpAndSettle();

      expect(tester.widget<FilledButton>(send).onPressed, isNotNull);

      await tester.tap(send);
      await tester.pumpAndSettle();
      expect(submitted, isTrue);
    });

    testWidgets('sends the wire code, never the translated label',
        (tester) async {
      ReportSubmission? captured;

      await tester.pumpWidget(_host((context) => showReportSheet(
            context,
            targetLabel: 'bir gönderi',
            onSubmit: (submission) async => captured = submission,
          )));
      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();

      final strings = AppStrings(AppLanguage.tr);
      // Turkish label "Taciz" against wire code "harassment" — if the
      // label ever leaked onto the wire, this is where it would show.
      await tester.tap(find.text(ReportReason.harassment.label(strings)));
      await tester.pumpAndSettle();
      await tester
          .tap(find.widgetWithText(FilledButton, strings.t('social_send')));
      await tester.pumpAndSettle();

      // The server stores, counts and prioritises on this string. A
      // localised label would make the same complaint in two languages
      // look like two unrelated reports.
      expect(captured!.reason.code, 'harassment');
      expect(strings.t('report_reason_harassment'), isNot('harassment'),
          reason: 'the test is meaningless if label and code are identical');
    });

    testWidgets('every reason is translated in all three languages',
        (tester) async {
      for (final language in AppLanguage.values) {
        await tester.pumpWidget(_host(
          (context) => showReportSheet(
            context,
            targetLabel: 'x',
            onSubmit: (_) async {},
          ),
          language: language,
        ));
        await tester.tap(find.text('open'));
        await tester.pumpAndSettle();

        final strings = AppStrings(language);
        for (final reason in ReportReason.values) {
          final label = reason.label(strings);
          expect(label, isNot(startsWith('report_reason_')),
              reason: 'untranslated ${reason.code} in $language');
          expect(find.text(label), findsOneWidget,
              reason: '${reason.code} missing from the sheet in $language');
        }

        await tester.tap(find.widgetWithText(OutlinedButton, strings.t('social_cancel')));
        await tester.pumpAndSettle();
      }
    });

    /// A failed report must not look like a sent one. Closing the sheet on
    /// error would lose what they typed and imply it went through.
    testWidgets('stays open and explains when submitting fails',
        (tester) async {
      await tester.pumpWidget(_host((context) => showReportSheet(
            context,
            targetLabel: 'bir gönderi',
            onSubmit: (_) async => throw Exception('CONTENT_BLOCKED'),
          )));
      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();

      final strings = AppStrings(AppLanguage.tr);
      await tester.tap(find.text(ReportReason.hate.label(strings)));
      await tester.pumpAndSettle();
      await tester
          .tap(find.widgetWithText(FilledButton, strings.t('social_send')));
      await tester.pumpAndSettle();

      expect(find.text(strings.t('report_error_description_blocked')),
          findsOneWidget);
      expect(find.text(strings.t('report_title')), findsOneWidget,
          reason: 'the sheet closed on a failed report');
    });
  });

  group('appeal sheet', () {
    testWidgets('refuses to send an empty appeal', (tester) async {
      var submitted = false;

      await tester.pumpWidget(_host((context) => showAppealSheet(
            context,
            contentLabel: 'kaldırılan gönderi',
            onSubmit: (_) async => submitted = true,
          )));
      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();

      final strings = AppStrings(AppLanguage.tr);
      await tester
          .tap(find.widgetWithText(FilledButton, strings.t('appeal_submit')));
      await tester.pumpAndSettle();

      expect(submitted, isFalse);
      expect(find.text(strings.t('appeal_reason_required')), findsOneWidget);
    });

    testWidgets('keeps the typed reason when sending fails', (tester) async {
      await tester.pumpWidget(_host((context) => showAppealSheet(
            context,
            contentLabel: 'kaldırılan gönderi',
            onSubmit: (_) async => throw Exception('network down'),
          )));
      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();

      const written = 'Bu benim kendi calismam.';
      await tester.enterText(find.byType(TextField), written);
      final strings = AppStrings(AppLanguage.tr);
      await tester
          .tap(find.widgetWithText(FilledButton, strings.t('appeal_submit')));
      await tester.pumpAndSettle();

      // Someone writing an appeal has thought about the wording; losing it
      // to a dropped connection is its own small injustice.
      expect(find.text(written), findsOneWidget);
      expect(find.text(strings.t('appeal_error_generic')), findsOneWidget);
    });
  });
}
