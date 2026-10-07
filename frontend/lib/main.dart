import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:sentry_flutter/sentry_flutter.dart';

import 'app/app.dart';
import 'app/config/app_config.dart';
import 'app/config_error_app.dart';
import 'core/auth/entra_auth_provider.dart';
import 'core/auth/rest_auth_provider.dart';
import 'core/network/api_client.dart';
import 'core/network/pinning_http_client.dart';
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
import 'core/theme/arucad_theme.dart';
import 'package:firebase_core/firebase_core.dart';

Future<void> main() async {
  await runZoned(() async {
    final sentryOptions = SentryBootstrapOptions.fromEnvironment();

    // SentryFlutter.init(..., appRunner: ...) installs FlutterError.onError,
    // PlatformDispatcher.instance.onError and a runZonedGuarded zone around
    // appRunner itself — all three uncaught-error paths P3-6 §15 asks for —
    // internally and exactly once. Adding our own handlers on top of this
    // would just report every crash a second (or third) time, so intentionally
    // not done here. Local/DSN-less builds skip init entirely so the SDK does
    // not log "No DSN provided" on every cold start.
    if (sentryOptions.isEnabled) {
      await SentryFlutter.init(
        sentryOptions.applyTo,
        appRunner: _runApp,
      );
    } else {
      await _runApp();
    }
  }, zoneSpecification: ZoneSpecification(
    print: (self, parent, zone, line) {
      // maplibre_gl_web always logs this; location is disabled on web.
      if (line.contains('myLocationRenderMode not available in web')) {
        return;
      }
      parent.print(zone, line);
    },
  ));
}

Future<void> _runApp() async {
  WidgetsFlutterBinding.ensureInitialized();
  SystemChrome.setSystemUIOverlayStyle(const SystemUiOverlayStyle(
    statusBarColor: Colors.white,
    statusBarIconBrightness: Brightness.dark,
    statusBarBrightness: Brightness.light,
    systemNavigationBarColor: ArucadColors.primary,
    systemNavigationBarIconBrightness: Brightness.light,
  ));
  configureUrlStrategy();

  // Attempt Firebase initialization; swallow errors so missing config won't
  // block running the demo app. For production, provide generated
  // `firebase_options.dart` and proper platform files (google-services.json / Info.plist).
  // Deliberately not logged: no Firebase project is configured in this
  // prototype (see docs/FIREBASE_SETUP.md and docs/GERCEK_PROJEYE_GECIS.md
  // §7) — this is an expected, disclosed condition, not an error to alert on.
  try {
    await Firebase.initializeApp().timeout(const Duration(seconds: 4));
    // Also bounded: Firebase had a ceiling and the push registration that
    // follows it did not, so a hung token request could block launch with no
    // limit at all. Push simply stays unregistered until the next start.
    await bootstrapPush().timeout(const Duration(seconds: 4));
  } catch (_) {
    // Push notifications simply stay unavailable until a real project exists.
  }

  try {
    await _mountApp();
  } on StateError catch (e) {
    runApp(ConfigErrorApp(message: e.message));
  } catch (e) {
    runApp(ConfigErrorApp(
      message: kReleaseMode
          ? 'Uygulama yapılandırması geçersiz. Release derlemede REST API adresi zorunludur; mock moda düşülmez.'
          : e.toString(),
    ));
  }
}

Future<void> _mountApp() async {
  // Entra sign-in switches on when the backend/site configuration supplies
  // Tenant ID, Client ID and Redirect URI. The student app no longer embeds
  // a management panel; administrators configure these values in Filament.
  // The backend endpoint is a build/deployment concern. Students never enter
  // an IP address: release builds use ARUVERSE's public HTTPS API, while local
  // developers can still provide API_BASE_URL with --dart-define.
  final config = AppConfig.fromEnvironment();
  await _mountAppWithConfig(
    config: config,
  );
}

Future<void> _mountAppWithConfig({
  required AppConfig config,
}) async {
  const portal = 'student';
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
    if (config.tlsPins.isNotEmpty) {
      await assertCertificatePins(Uri.parse(apiBaseUrl), config.tlsPins);
    }
    final client = ApiClient(
      baseUrl: apiBaseUrl,
      authTokenAdapter: SessionTokenAdapter(portal: portal),
    );
    EntraAuthProvider? entra;
    try {
      /*
       * Bounded, because this runs BEFORE the first frame.
       *
       * ApiClient's own timeout is 20 seconds, which is right for a request a
       * student is waiting on inside the app and badly wrong here: on a slow
       * or unreachable network this held the launch screen blank for the full
       * twenty before anything was painted. All it decides is whether the
       * "Sign in with Microsoft" button appears, and email/password sign-in
       * works regardless — so the app starting is worth more than that button
       * being right on the very first try.
       */
      final cfg = await client
          .get('/auth/entra/config')
          .timeout(const Duration(seconds: 3));
      final data = cfg['data'];
      if (data is Map<String, dynamic> && data['configured'] == true) {
        final tenant = (data['tenantId'] as String? ?? '').trim();
        final clientId = (data['clientId'] as String? ?? '').trim();
        final redirect = (data['redirectUri'] as String? ?? '').trim();
        if (tenant.isNotEmpty && clientId.isNotEmpty && redirect.isNotEmpty) {
          entra = EntraAuthProvider(
            tenantId: tenant,
            clientId: clientId,
            redirectUri: redirect,
          );
        }
      }
    } catch (_) {}
    authProvider =
        RestAuthProvider(client: client, portal: portal, entra: entra);
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
      onReloadServices: _mountApp,
    ),
  );
}

// Note: `firebase_messaging` usage removed due to web interop compile issues.
// Re-enable and test messaging when compatible web packages are available.
