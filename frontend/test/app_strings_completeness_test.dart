import 'package:flutter_test/flutter_test.dart';
import 'package:flutter/widgets.dart';

import 'package:arucad_campus_prototype/app/app.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';

/// Guards the translation table against the two ways it silently rots.
///
/// A missing language falls back to English (or Turkish), so a half-added
/// key looks fine in Turkish testing and quietly ships an untranslated
/// string to the students who actually need the translation. And a key left
/// in the table after its screen was rewritten is invisible forever. Both
/// are cheap to catch here and expensive to notice in the field.
void main() {
  group('AppStrings', () {
    test('first launch follows supported device languages', () {
      expect(deviceLanguageCode(const Locale('tr', 'TR')), 'TR');
      expect(deviceLanguageCode(const Locale('en', 'GB')), 'EN');
      expect(deviceLanguageCode(const Locale('ru', 'RU')), 'RU');
      expect(deviceLanguageCode(const Locale('de', 'DE')), 'EN');
    });
    test('every key is translated into all three languages', () {
      final missing = <String>[];

      for (final entry in AppStrings.debugTable.entries) {
        for (final language in AppLanguage.values) {
          final value = entry.value[language];
          if (value == null || value.trim().isEmpty) {
            missing.add('${entry.key} → ${language.name}');
          }
        }
      }

      expect(
        missing,
        isEmpty,
        reason: '${missing.length} translation(s) missing:\n'
            '${missing.join('\n')}',
      );
    });

    test('lookup returns the requested language, not a fallback', () {
      // 'nav_home' is one of the oldest keys and is genuinely different in
      // all three languages, so a broken lookup cannot pass by coincidence.
      expect(const AppStrings(AppLanguage.tr).t('nav_home'), 'Ana Sayfa');
      expect(const AppStrings(AppLanguage.en).t('nav_home'), 'Home');
      expect(const AppStrings(AppLanguage.ru).t('nav_home'), 'Главная');
    });

    test('an unknown key returns the key itself rather than throwing', () {
      // A missing key should show up as an obvious ugly string in the UI,
      // not crash the screen it appears on.
      expect(
        const AppStrings(AppLanguage.en).t('definitely_not_a_key'),
        'definitely_not_a_key',
      );
    });

    test('languageFromCode falls back to Turkish for anything unexpected', () {
      expect(languageFromCode('EN'), AppLanguage.en);
      expect(languageFromCode('RU'), AppLanguage.ru);
      expect(languageFromCode('TR'), AppLanguage.tr);
      expect(languageFromCode(''), AppLanguage.tr);
      expect(languageFromCode('de'), AppLanguage.tr);
    });
  });
}
