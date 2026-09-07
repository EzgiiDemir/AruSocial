import '../auth/session_store.dart';
import '../models/campus_models.dart';
import '../network/auth_token_adapter.dart';
import 'contracts.dart';

/// Standing in for the university's real identity backend: one exact admin
/// credential for testing, and a domain allowlist (any @arucad.edu.tr or
/// @gmail.com email with a non-empty password) for everyone else — since
/// this prototype has no real student directory to check individual
/// passwords against.
///
/// Debug / `USE_REST_API=false` only. Biometric unlock never invents a
/// session: it only restores a token [SessionStore] already holds.
class MockAuthProvider extends AuthTokenAdapter implements AuthProvider {
  static const _adminEmail = 'ezgi.demir@arucad.edu.tr';
  static const _adminPassword = 'Ez26m!r';
  static const _allowedDomains = ['@arucad.edu.tr', '@gmail.com'];

  MockAuthProvider({this.portal = 'student'});

  final String portal;

  String? _currentEmail;

  @override
  String? get currentEmail => _currentEmail;

  /// There's no real Entra/backend role claim yet (see README roadmap) —
  /// the one seeded admin account is the only account with elevated
  /// permissions, as a stand-in for a real role system.
  UserRole get role => _currentEmail == _adminEmail ? UserRole.superAdmin : UserRole.student;

  @override
  Future<bool> signIn() async {
    await Future<void>.delayed(const Duration(milliseconds: 500));
    await SessionStore.save(
      token: 'mock.local.session.$_adminEmail',
      email: _adminEmail,
      portal: portal,
    );
    _currentEmail = _adminEmail;
    return true;
  }

  @override
  Future<bool> signInWithCredentials(String identifier, String password) async {
    await Future<void>.delayed(const Duration(milliseconds: 500));
    final id = identifier.trim().toLowerCase();

    final isAdmin = id == _adminEmail && password == _adminPassword;
    final isAllowedStaffOrStudent =
        _allowedDomains.any(id.endsWith) && password.isNotEmpty;

    if (!isAdmin && !isAllowedStaffOrStudent) return false;

    await SessionStore.save(
      token: 'mock.local.session.$id',
      email: id,
      portal: portal,
    );
    _currentEmail = id;
    return true;
  }

  @override
  Future<bool> unlockWithBiometrics({String? email}) async {
    await Future<void>.delayed(const Duration(milliseconds: 400));
    final token = await SessionStore.token(portal: portal);
    if (token == null || token.isEmpty) return false;
    final storedEmail =
        (await SessionStore.email(portal: portal))?.trim().toLowerCase();
    if (storedEmail == null || storedEmail.isEmpty) return false;
    final requested = email?.trim().toLowerCase();
    if (requested != null && requested.isNotEmpty && requested != storedEmail) {
      return false;
    }
    _currentEmail = storedEmail;
    return true;
  }

  @override
  Future<String?> getAccessToken() => SessionStore.token(portal: portal);

  /// Drop the in-memory flag and the stored mock session so a later
  /// biometric prompt cannot revive the previous account.
  @override
  Future<void> signOut() async {
    _currentEmail = null;
    await SessionStore.clear(portal: portal);
  }
}
