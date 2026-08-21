import 'auth_token_adapter.dart';
import '../auth/session_store.dart';

/// Real bearer-token auth for [ApiClient] (docs/EKSIKLER.md "Gerçek JWT/
/// session authentication") — reads whatever token [SessionStore] holds
/// *at call time*, so it works correctly even though `ApiClient` is built
/// once at app startup, before sign-in (and any resulting token) exists.
/// Every /v1 backend route now requires this — see
/// `AuthController::session()` and `auth:sanctum` in routes/api.php.
class SessionTokenAdapter extends AuthTokenAdapter {
  @override
  Future<String?> getAccessToken() => SessionStore.currentToken();
}
