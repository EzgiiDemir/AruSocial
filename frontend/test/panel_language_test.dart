import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/l10n/admin_strings.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';

void main() {
  test('admin and student panels share TR EN RU codes', () {
    expect(adminLanguageFromCode('TR'), AdminLanguage.tr);
    expect(adminLanguageFromCode('EN'), AdminLanguage.en);
    expect(adminLanguageFromCode('RU'), AdminLanguage.ru);
    expect(adminLanguageCode(AdminLanguage.ru), 'RU');

    expect(languageFromCode('TR'), AppLanguage.tr);
    expect(languageFromCode('EN'), AppLanguage.en);
    expect(languageFromCode('RU'), AppLanguage.ru);

    expect(const AdminStrings(AdminLanguage.ru).t('admin_nav_events'), 'События');
    expect(const AdminStrings(AdminLanguage.ru).t('trainer_nav_dashboard'), 'Обзор');
    expect(const AppStrings(AppLanguage.ru).t('appt_time'), 'Время *');
  });
}
