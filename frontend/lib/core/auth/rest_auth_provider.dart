import '../network/api_client.dart';
import '../services/contracts.dart';
import 'session_store.dart';

/// Real sign-in against the Laravel backend: `POST /auth/session` exchanges
/// the credentials for a Sanctum token, [SessionStore] keeps it, and
/// `SessionTokenAdapter` puts it on every later request. This is what makes
/// the app's identity the server's identity — before it, the backend
/// resolved every request to the same seeded row no matter who signed in.
///
/// Used only in REST mode; `MockAuthProvider` still owns the offline demo
/// flow, and the two never share session state.
class RestAuthProvider implements AuthProvider {
  RestAuthProvider({required this.client});

  final ApiClient client;

  String? _currentEmail;

  @override
  String? get currentEmail => _currentEmail;

  /// Microsoft sign-in isn't wired to this backend: the server has no way to
  /// verify an Entra token yet, so offering the button here would be
  /// offering something that cannot work. `EntraAuthProvider` keeps the
  /// client-side half ready for the production identity milestone.
  @override
  Future<bool> signIn() async => false;

  @override
  Future<bool> signInWithCredentials(String identifier, String password) async {
    final email = identifier.trim().toLowerCase();
    final response = await client.post(
      '/auth/session',
      body: {'email': email, 'password': password},
    );

    final data = response['data'];
    final token = data is Map<String, dynamic> ? data['token'] as String? : null;
    if (token == null || token.isEmpty) return false;

    await SessionStore.save(token: token, email: email);
    _currentEmail = email;
    return true;
  }

  /// Biometrics unlock a session that already exists on this device. The
  /// backend never sees a fingerprint, so if the stored token is gone — or
  /// was revoked by a logout elsewhere — there is nothing here to
  /// authenticate with and the user has to enter their password again.
  @override
  Future<bool> unlockWithBiometrics({String? email}) async {
    final token = await SessionStore.token();
    if (token == null) return false;
    _currentEmail = (email ?? await SessionStore.email())?.trim().toLowerCase();
    return true;
  }

  @override
  Future<String?> getAccessToken() => SessionStore.token();

  @override
  Future<void> signOut() async {
    try {
      // Revoking server-side is what actually ends the session; without it
      // the token stays valid for anyone who copied it off the device.
      await client.post('/auth/logout');
    } on ApiClientException {
      // Already unusable server-side (revoked elsewhere, or the server is
      // unreachable) — either way the local clear below is the part that
      // still has to happen, so the app can't be left holding a token it
      // believes is good.
    } finally {
      await SessionStore.clear();
      _currentEmail = null;
    }
  }
}
