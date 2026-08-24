import '../auth/session_store.dart';
import 'auth_token_adapter.dart';

/// Hands [ApiClient] the stored Sanctum token, so every REST call carries
/// `Authorization: Bearer …` without a single call site having to remember.
///
/// Reads the store on each request rather than caching one copy: sign-in and
/// logout change the token underneath an [ApiClient] that lives for the whole
/// app session, and a cached copy would keep authenticating as the account
/// that just signed out.
class SessionTokenAdapter extends AuthTokenAdapter {
  @override
  Future<String?> getAccessToken() => SessionStore.token();
}
