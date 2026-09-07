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
///
/// Scoped by [portal] ('student' | 'admin' | 'trainer') — the student app,
/// Admin Panel, and Trainer Panel are three separate real entry points (see
/// main.dart's `startInAdminMode`/`startInTrainerMode`), each with its own
/// login. Before this scoping, all three shared one storage key, so signing
/// out of any one of them (or even just letting one token expire) silently
/// logged the other two out too — on the same device/browser they must stay
/// fully independent, exactly like separate accounts would.
class SessionStore {
  static String _tokenKey(String portal) => 'session.token.$portal';
  static String _emailKey(String portal) => 'session.email.$portal';

  static Future<void> save({
    required String token,
    required String email,
    String portal = 'student',
  }) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_tokenKey(portal), token);
    await prefs.setString(_emailKey(portal), email);
  }

  static Future<String?> token({String portal = 'student'}) async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString(_tokenKey(portal));
    return (token == null || token.isEmpty) ? null : token;
  }

  static Future<String?> email({String portal = 'student'}) async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_emailKey(portal));
  }

  static Future<void> clear({String portal = 'student'}) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_tokenKey(portal));
    await prefs.remove(_emailKey(portal));
  }
}
