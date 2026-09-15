import '../network/api_client.dart';
import '../services/contracts.dart';
import 'entra_auth_provider.dart';
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
  RestAuthProvider({
    required this.client,
    this.portal = 'student',
    this.entra,
  });

  final ApiClient client;

  /// 'student' | 'admin' | 'trainer' — which real portal this session
  /// belongs to. Each keeps its own [SessionStore] entry so the three can
  /// be signed in independently in the same browser (e.g. an admin testing
  /// `/admin` in one tab and `/trainer` in another) without one logout or
  /// expired token affecting the others.
  final String portal;
  final EntraAuthProvider? entra;

  String? _currentEmail;

  @override
  String? get currentEmail => _currentEmail;

  bool get microsoftSignInAvailable => entra != null;

  @override
  Future<bool> signIn() async {
    final provider = entra;
    if (provider == null) return false;
    final ok = await provider.signIn();
    final idToken = provider.lastIdToken;
    if (!ok || idToken == null || idToken.isEmpty) return false;

    final response =
        await client.post('/auth/entra', body: {'idToken': idToken});
    final data = response['data'];
    final token =
        data is Map<String, dynamic> ? data['token'] as String? : null;
    final user = data is Map<String, dynamic> ? data['user'] : null;
    final email = user is Map<String, dynamic>
        ? (user['email'] as String? ?? provider.currentEmail)
        : provider.currentEmail;
    if (token == null || token.isEmpty || email == null || email.isEmpty) {
      return false;
    }
    await SessionStore.save(token: token, email: email, portal: portal);
    _currentEmail = email.trim().toLowerCase();
    return true;
  }

  @override
  Future<bool> signInWithCredentials(String identifier, String password) async {
    final email = _normalizeCampusEmail(identifier);
    final pass = password.trim();
    // Avoid a pointless 422 round-trip when the form is empty / student-no
    // only (Laravel `email` rule rejects local-part without a domain).
    if (email.isEmpty || pass.isEmpty || !email.contains('@')) {
      throw ApiClientException(
        'Geçerli bir e-posta (veya öğrenci no) ve şifre gerekli.',
        code: 'VALIDATION',
        statusCode: 422,
      );
    }
    // Local/dev password login accepts campus + Gmail test inboxes. Entra
    // (Microsoft) sign-in keeps its own production domain check.
    if (!_isAllowedPasswordLoginDomain(email)) {
      throw ApiClientException(
        'Yalnızca @arucad.edu.tr veya @gmail.com uzantılı hesaplar giriş yapabilir.',
        code: 'DOMAIN_NOT_ALLOWED',
        statusCode: 403,
      );
    }
    final response = await client.post(
      '/auth/session',
      body: {'email': email, 'password': pass},
    );

    final data = response['data'];
    final token =
        data is Map<String, dynamic> ? data['token'] as String? : null;
    if (token == null || token.isEmpty) return false;

    await SessionStore.save(token: token, email: email, portal: portal);
    _currentEmail = email;
    return true;
  }

  /// Accepts full `user@arucad.edu.tr` / `user@gmail.com` or a bare
  /// student/local id and expands it to the campus domain the API's
  /// `email` rule expects.
  static const _passwordLoginDomains = ['@arucad.edu.tr', '@gmail.com'];

  static bool _isAllowedPasswordLoginDomain(String email) {
    final id = email.trim().toLowerCase();
    return _passwordLoginDomains.any(id.endsWith);
  }

  static String _normalizeCampusEmail(String identifier) {
    final raw = identifier.trim().toLowerCase();
    if (raw.isEmpty) return raw;
    if (raw.contains('@')) return raw;
    return '$raw@arucad.edu.tr';
  }

  /// Restore a previously saved Sanctum session after cold start / web
  /// refresh. Validates the token with `GET /me` so a revoked token never
  /// leaves the UI in a half-signed-in state.
  Future<bool> restoreFromStore() async {
    final token = await SessionStore.token(portal: portal);
    if (token == null) return false;
    _currentEmail =
        (await SessionStore.email(portal: portal))?.trim().toLowerCase();
    try {
      await client.get('/me');
      return true;
    } on ApiClientException catch (e) {
      await SessionStore.clear(portal: portal);
      _currentEmail = null;
      if (e.code == 'ACCOUNT_BANNED') rethrow;
      return false;
    }
  }

  /// Biometrics unlock a session that already exists on this device. The
  /// backend never sees a fingerprint, so if the stored token is gone — or
  /// was revoked by a logout elsewhere — there is nothing here to
  /// authenticate with and the user has to enter their password again.
  /// Success of the OS prompt is never treated as authentication by itself.
  @override
  Future<bool> unlockWithBiometrics({String? email}) async {
    final token = await SessionStore.token(portal: portal);
    if (token == null || token.isEmpty) return false;
    final storedEmail =
        (await SessionStore.email(portal: portal))?.trim().toLowerCase();
    if (storedEmail == null || storedEmail.isEmpty) return false;
    final requested = email?.trim().toLowerCase();
    if (requested != null && requested.isNotEmpty && requested != storedEmail) {
      return false;
    }
    return restoreFromStore();
  }

  @override
  Future<String?> getAccessToken() => SessionStore.token(portal: portal);

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
      await SessionStore.clear(portal: portal);
      _currentEmail = null;
    }
  }

  /// Drop a session the server has already rejected without making a
  /// guaranteed-to-fail `/auth/logout` request. Used for 401/ACCOUNT_BANNED
  /// responses; normal user-initiated logout still revokes server-side.
  Future<void> discardRejectedSession() async {
    await SessionStore.clear(portal: portal);
    _currentEmail = null;
  }
}
