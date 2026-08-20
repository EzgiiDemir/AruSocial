import '../models/campus_models.dart';
import '../network/auth_token_adapter.dart';
import 'contracts.dart';

/// Standing in for the university's real identity backend: one exact admin
/// credential for testing, and a domain allowlist (any @arucad.edu.tr email
/// with a non-empty password) for everyone else — since this prototype has
/// no real student directory to check individual passwords against.
class MockAuthProvider extends AuthTokenAdapter implements AuthProvider {
  static const _adminEmail = 'ezgi.demir@arucad.edu.tr';
  static const _adminPassword = 'Ez26m!r';
  static const _allowedDomain = '@arucad.edu.tr';

  bool _signedIn = false;
  String? _currentEmail;

  String? get currentEmail => _currentEmail;

  /// There's no real Entra/backend role claim yet (see README roadmap) —
  /// the one seeded admin account is the only account with elevated
  /// permissions, as a stand-in for a real role system.
  UserRole get role => _currentEmail == _adminEmail ? UserRole.superAdmin : UserRole.student;

  @override
  Future<bool> signIn() async {
    await Future<void>.delayed(const Duration(milliseconds: 500));
    _signedIn = true;
    _currentEmail ??= _adminEmail;
    return true;
  }

  @override
  Future<bool> signInWithCredentials(String identifier, String password) async {
    await Future<void>.delayed(const Duration(milliseconds: 500));
    final id = identifier.trim().toLowerCase();

    final isAdmin = id == _adminEmail && password == _adminPassword;
    final isAllowedStaffOrStudent =
        id.endsWith(_allowedDomain) && password.isNotEmpty;

    if (!isAdmin && !isAllowedStaffOrStudent) return false;

    _signedIn = true;
    _currentEmail = id;
    return true;
  }

  @override
  Future<bool> unlockWithBiometrics({String? email}) async {
    await Future<void>.delayed(const Duration(milliseconds: 400));
    _signedIn = true;
    if (email != null) _currentEmail = email.trim().toLowerCase();
    return true;
  }

  @override
  Future<String?> getAccessToken() async {
    return _signedIn ? 'demo.token.arucad' : null;
  }
}
