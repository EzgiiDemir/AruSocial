import 'package:shared_preferences/shared_preferences.dart';

/// The signed-in session as this device remembers it: the Sanctum token the
/// backend authenticates every request with, plus the email it belongs to
/// (the email→role lookup at sign-in is keyed on it, and the token itself
/// carries no claims the client can read).
///
/// Kept in SharedPreferences — app-private storage, the same place every
/// other persisted setting here lives, and no new dependency. It is not the
/// OS keychain: on a rooted or jailbroken device this token is readable.
/// That's why it is revocable server-side (`POST /auth/logout` deletes the
/// row in `personal_access_tokens`) instead of being trusted forever.
/// Moving to a keychain-backed store is a real decision for the production
/// identity milestone, not something to pretend at here.
class SessionStore {
  static const _tokenKey = 'session.token';
  static const _emailKey = 'session.email';

  static Future<void> save({required String token, required String email}) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_tokenKey, token);
    await prefs.setString(_emailKey, email);
  }

  static Future<String?> token() async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString(_tokenKey);
    return (token == null || token.isEmpty) ? null : token;
  }

  static Future<String?> email() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_emailKey);
  }

  static Future<void> clear() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_tokenKey);
    await prefs.remove(_emailKey);
  }
}
