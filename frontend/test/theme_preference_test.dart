import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/auth/app_settings_store.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  test('theme preference defaults to system and can still be stored', () async {
    expect(await AppSettingsStore.themePreference(),
        ArucadThemePreference.system);

    await AppSettingsStore.setThemePreference(ArucadThemePreference.light);
    expect(await AppSettingsStore.themePreference(),
        ArucadThemePreference.light);
  });
}
