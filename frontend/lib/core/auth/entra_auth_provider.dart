import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_appauth/flutter_appauth.dart';

import '../network/auth_token_adapter.dart';
import '../services/contracts.dart';

/// Entra (Azure AD) scaffold implementing the project's `AuthProvider` and
/// `AuthTokenAdapter`. It enforces `@arucad.edu.tr` domain on signed-in accounts.
class EntraAuthProvider implements AuthProvider, AuthTokenAdapter {
  final FlutterAppAuth _appAuth;
  final String clientId;
  final String redirectUri;
  final String tenantId;
  final List<String> scopes;

  String? _accessToken;
  String? _email;

  /// The signed-in account's real email, parsed from the Entra ID token —
  /// used to look up a locally-assigned role via `RoleAssignmentStore`
  /// (Entra itself doesn't hand this app a roles claim yet).
  @override
  String? get currentEmail => _email;

  EntraAuthProvider({
    FlutterAppAuth? appAuth,
    required this.clientId,
    required this.redirectUri,
    required this.tenantId,
    this.scopes = const ['openid', 'profile', 'email', 'offline_access'],
  }) : _appAuth = appAuth ?? const FlutterAppAuth();

  @override
  Future<bool> signIn() async {
    try {
      final issuer = 'https://login.microsoftonline.com/$tenantId';

      final result = await _appAuth.authorizeAndExchangeCode(
        AuthorizationTokenRequest(
          clientId,
          redirectUri,
          serviceConfiguration: AuthorizationServiceConfiguration(
            authorizationEndpoint: '$issuer/oauth2/v2.0/authorize',
            tokenEndpoint: '$issuer/oauth2/v2.0/token',
          ),
          scopes: scopes,
        ),
      );

      if (result == null) return false;

      final idToken = result.idToken;
      final accessToken = result.accessToken;

      if (idToken == null || accessToken == null) {
        return false;
      }

      final claims = _parseIdToken(idToken);
      final email =
          claims['email'] ?? claims['upn'] ?? claims['preferred_username'];
      if (email == null || !email.endsWith('@arucad.edu.tr')) {
        return false;
      }

      _accessToken = accessToken;
      _email = (email as String).trim().toLowerCase();

      return true;
    } catch (e, st) {
      if (kDebugMode) debugPrint('Entra signIn error: $e\n$st');
      return false;
    }
  }

  @override
  Future<bool> signInWithCredentials(String identifier, String password) async {
    // Entra/Azure AD accounts authenticate exclusively through the OAuth
    // flow above (`signIn`); there is no separate password grant here.
    return false;
  }

  @override
  Future<bool> unlockWithBiometrics({String? email}) async {
    // Biometrics are handled elsewhere via `local_auth`; scaffold returns false.
    return false;
  }

  @override
  Future<String?> getAccessToken() async => _accessToken;

  @override
  Future<void> signOut() async {
    // No end-session call to the tenant yet — this scaffold only performs
    // the authorization-code exchange, so dropping the tokens it holds is
    // all it can honestly claim to do. A real single-sign-out belongs with
    // the production identity milestone.
    _accessToken = null;
    _email = null;
  }

  @override
  Future<Map<String, String>> authorizeHeaders() async {
    final token = await getAccessToken();
    if (token == null || token.isEmpty) return {};
    return {'Authorization': 'Bearer $token'};
  }

  Map<String, dynamic> _parseIdToken(String idToken) {
    try {
      final parts = idToken.split('.');
      if (parts.length != 3) return {};
      final payload = parts[1];
      final normalized = base64Url.normalize(payload);
      final decoded = utf8.decode(base64Url.decode(normalized));
      return Map<String, dynamic>.from(jsonDecode(decoded) as Map);
    } catch (_) {
      return {};
    }
  }
}
