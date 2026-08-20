import 'package:shared_preferences/shared_preferences.dart';

/// Persists the admin panel's own small set of preferences — currently just
/// its display language. Deliberately separate from `AppSettingsStore`
/// (which persists the *student* app's language): the admin panel is used
/// independently of a student session, so it gets its own
/// `shared_preferences` key rather than sharing/overwriting the app's.
class AdminSettingsStore {
  static const _kLanguage = 'admin.settings.language';

  static Future<String> language() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_kLanguage) ?? 'TR';
  }

  static Future<void> setLanguage(String language) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kLanguage, language);
  }
}
