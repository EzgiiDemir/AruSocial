import 'package:flutter/material.dart';
import 'package:flutter/foundation.dart';
import 'package:sentry_flutter/sentry_flutter.dart';

import 'app/app.dart';
import 'app/config/app_config.dart';
import 'core/auth/entra_auth_provider.dart';
import 'core/auth/rest_auth_provider.dart';
import 'core/network/api_client.dart';
import 'core/network/session_token_adapter.dart';
import 'core/observability/sentry_bootstrap.dart';
import 'core/platform/url_strategy.dart';
import 'core/services/contracts.dart';
import 'core/services/mock_auth_provider.dart';
import 'core/services/mock_analytics_tracker.dart';
import 'core/services/mock_campus_repository.dart';
import 'core/services/rest_campus_repository.dart';
import 'core/services/site_settings_store.dart';
import 'core/services/url_launcher_map_provider.dart';
import 'core/services/push_bootstrap.dart';
import 'package:firebase_core/firebase_core.dart';

Future<void> main() async {
  final sentryOptions = SentryBootstrapOptions.fromEnvironment();

  // SentryFlutter.init(..., appRunner: ...) installs FlutterError.onError,
  // PlatformDispatcher.instance.onError and a runZonedGuarded zone around
  // appRunner itself — all three uncaught-error paths P3-6 §15 asks for —
  // internally and exactly once. Adding our own handlers on top of this
  // would just report every crash a second (or third) time, so intentionally
  // not done here. A DSN-less (local) build still runs _runApp completely
  // normally; it just never has anything to report.
  await SentryFlutter.init(
    sentryOptions.applyTo,
    appRunner: _runApp,
  );
}

Future<void> _runApp() async {
  WidgetsFlutterBinding.ensureInitialized();
  configureUrlStrategy();

  // Real web split: visiting /admin (vs the normal / root) is a genuinely
  // separate entry point, not just a hidden button — see _DemoSession's
  // handling of `startInAdminMode` in app.dart for the actual role check.
  final startInAdminMode = kIsWeb && Uri.base.path.startsWith('/admin');

  // Attempt Firebase initialization; swallow errors so missing config won't
  // block running the demo app. For production, provide generated
  // `firebase_options.dart` and proper platform files (google-services.json / Info.plist).
  // Deliberately not logged: no Firebase project is configured in this
  // prototype (see docs/FIREBASE_SETUP.md and docs/GERCEK_PROJEYE_GECIS.md
  // §7) — this is an expected, disclosed condition, not an error to alert on.
  try {
    await Firebase.initializeApp();
    await bootstrapPush();
  } catch (_) {
    // Push notifications simply stay unavailable until a real project exists.
  }

  // Real Entra sign-in switches on automatically once an admin fills in
  // Tenant ID / Client ID / Redirect URI from Profile → Yönetim Paneli →
  // Site Settings — no code change or rebuild needed for the credentials
  // themselves (the redirect scheme is still fixed at Android build time,
  // see android/app/build.gradle.kts). Until then this is empty and the app
  // keeps using the mock directory, exactly as before.
  final config = AppConfig.fromEnvironment();
  final useRestApi = config.useRestApi;
  final apiBaseUrl = config.apiBaseUrl;

  // REST signs in with Sanctum email/password — on-device Entra prefs are
  // not the source of truth there. Mock/Entra login still reads the local
  // store until an admin has filled tenant/client/redirect.
  final entraConfig = useRestApi
      ? const EntraSiteConfig(tenantId: '', clientId: '', redirectUri: '')
      : await SiteSettingsStore.entra();

  final AuthProvider authProvider;
  final CampusRepository repository;
  if (useRestApi) {
    // One client for both halves: the auth provider writes the token into
    // SessionStore, and SessionTokenAdapter puts it on every request the
    // repository makes afterwards.
    final client = ApiClient(
      baseUrl: apiBaseUrl,
      authTokenAdapter: SessionTokenAdapter(),
    );
    authProvider = RestAuthProvider(client: client);
    repository = RestCampusRepository(client: client);
  } else if (entraConfig.isConfigured) {
    authProvider = EntraAuthProvider(
      tenantId: entraConfig.tenantId,
      clientId: entraConfig.clientId,
      redirectUri: entraConfig.redirectUri,
    );
    repository = MockCampusRepository();
  } else {
    authProvider = MockAuthProvider();
    repository = MockCampusRepository();
  }

  runApp(
    ArucadCampusApp(
      config: config,
      repository: repository,
      authProvider: authProvider,
      mapProvider: const UrlLauncherMapProvider(),
      analyticsTracker: MockAnalyticsTracker(),
      startInAdminMode: startInAdminMode,
    ),
  );
}

// Note: `firebase_messaging` usage removed due to web interop compile issues.
// Re-enable and test messaging when compatible web packages are available.
