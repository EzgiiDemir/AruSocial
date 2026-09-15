import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:sentry_flutter/sentry_flutter.dart';

import 'app/app.dart';
import 'app/config/app_config.dart';
import 'core/auth/app_settings_store.dart';
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
    await bootstrapPush();
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
  // API configuration is compiled into the build, and that stays the
  // default. A saved host/port from the login screen's settings can point
  // this build at a different server — how a phone reaches a laptop running
  // the backend on the same WiFi, where the compiled-in address is either a
  // loopback the phone cannot route to or an IP that changed since the
  // build. The override is stored on the device and survives restarts, so
  // it is entered once rather than every launch.
  final config = await _withSavedApiOverride(AppConfig.fromEnvironment());
  await _mountAppWithConfig(
    config: config,
  );
}

/// Applies a host/port the student saved on the login screen.
///
/// Returns the config unchanged when nothing is saved, so a normal install
/// still uses whatever the build was configured with.
Future<AppConfig> _withSavedApiOverride(AppConfig config) async {
  // Simulator/repro builds must use the endpoint they were compiled with.
  // Otherwise an old SharedPreferences value (often a stale Wi-Fi, VPN or
  // Hyper-V address) silently wins and the status panel tests one server
  // while the application talks to another.
  const lockCompiledTarget =
      bool.fromEnvironment('LOCK_API_BASE_URL', defaultValue: false);
  if (lockCompiledTarget) return config;

  try {
    final host = (await AppSettingsStore.runtimeApiHost()).trim();
    if (host.isEmpty) return config;
    final port = await AppSettingsStore.runtimeApiPort();

    return config.copyWith(
      useRestApi: true,
      apiBaseUrl: AppConfig.buildLocalApiBaseUrl(host, port: port),
    );
  } catch (_) {
    // A device that cannot read its own preferences should still start on
    // the compiled-in configuration rather than refusing to launch.
    return config;
  }
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
      final cfg = await client.get('/auth/entra/config');
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
