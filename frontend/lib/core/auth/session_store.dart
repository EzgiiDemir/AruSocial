import 'package:shared_preferences/shared_preferences.dart';

/// A real, persisted sign-in session (docs/EKSIKLER.md "Gerçek JWT/session
/// authentication") — this is what makes "uygulama yeniden açıldığında
/// kullanıcı session'ı geri yüklenmeli" (session restored on relaunch)
/// real instead of forcing a fresh sign-in every time. [token] is the
/// backend's real Sanctum bearer token (`AuthController::session()`) in
/// Rest mode; null in Mock mode, which has no real backend to authenticate
/// against.
class StoredSession {
  final String? token;
  final String email;
  final String name;
  final String role;
  const StoredSession({
    required this.token,
    required this.email,
    required this.name,
    required this.role,
  });
}

class SessionStore {
  static const _kToken = 'session.token';
  static const _kEmail = 'session.email';
  static const _kName = 'session.name';
  static const _kRole = 'session.role';

  static Future<void> save({
    String? token,
    required String email,
    required String name,
    required String role,
  }) async {
    final prefs = await SharedPreferences.getInstance();
    if (token != null) {
      await prefs.setString(_kToken, token);
    } else {
      await prefs.remove(_kToken);
    }
    await prefs.setString(_kEmail, email);
    await prefs.setString(_kName, name);
    await prefs.setString(_kRole, role);
  }

  static Future<StoredSession?> restore() async {
    final prefs = await SharedPreferences.getInstance();
    final email = prefs.getString(_kEmail);
    final name = prefs.getString(_kName);
    final role = prefs.getString(_kRole);
    if (email == null || name == null || role == null) return null;
    return StoredSession(token: prefs.getString(_kToken), email: email, name: name, role: role);
  }

  static Future<void> clear() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_kToken);
    await prefs.remove(_kEmail);
    await prefs.remove(_kName);
    await prefs.remove(_kRole);
  }

  /// The current bearer token, read fresh on every call — [SessionTokenAdapter]
  /// uses this so a token saved *after* [ApiClient] was already constructed
  /// (sign-in always happens after app startup) still gets picked up.
  static Future<String?> currentToken() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_kToken);
  }
}
