import 'package:flutter/foundation.dart';

/// Runtime environment for public/client config (`--dart-define`).
/// Secrets (DB password, Reverb secret, Firebase private key, SMTP, WP token)
/// must never land here.
enum AppEnvironment {
  local,
  staging,
  production,
}

class AppConfig {
  final String appName;
  final AppEnvironment environment;
  final String apiBaseUrl;
  final bool useRestApi;
  final bool demoMode;
  final String defaultLanguage;
  final List<String> supportedLanguages;
  final String reverbAppKey;
  final String reverbHost;
  final int reverbPort;
  final String reverbScheme;
  final String sentryDsn;
  final String firebaseProjectId;

  const AppConfig({
    required this.appName,
    required this.environment,
    required this.apiBaseUrl,
    required this.useRestApi,
    required this.demoMode,
    required this.defaultLanguage,
    required this.supportedLanguages,
    this.reverbAppKey = 'arucad-local-key',
    this.reverbHost = '',
    this.reverbPort = 8080,
    this.reverbScheme = '',
    this.sentryDsn = '',
    this.firebaseProjectId = '',
  });

  factory AppConfig.demo() => const AppConfig(
        appName: 'AruSocial',
        environment: AppEnvironment.local,
        apiBaseUrl: 'http://localhost:4000/api/v1',
        useRestApi: false,
        demoMode: true,
        defaultLanguage: 'tr',
        supportedLanguages: ['tr', 'en', 'ru'],
      );

  /// Compile-time `--dart-define` values, with testable overrides.
  ///
  /// Debug `flutter run` with no defines stays mock (`USE_REST_API` unset).
  /// Release builds must set `USE_REST_API` explicitly so a store APK cannot
  /// silently ship as mock.
  factory AppConfig.fromEnvironment({
    bool isRelease = kReleaseMode,
    bool isWeb = kIsWeb,
    TargetPlatform? platform,
    String? appEnv,
    String? useRestApi,
    String? apiBaseUrl,
    String? reverbAppKey,
    String? reverbHost,
    int? reverbPort,
    String? reverbScheme,
    String? sentryDsn,
    String? firebaseProjectId,
  }) {
    final envRaw = appEnv ?? const String.fromEnvironment('APP_ENV', defaultValue: 'local');
    final environment = parseEnvironment(envRaw, strict: isRelease);
    final useRestRaw = useRestApi ?? const String.fromEnvironment('USE_REST_API');
    if (isRelease && useRestRaw.isEmpty) {
      throw StateError(
        'Release builds require --dart-define=USE_REST_API=true '
        '(or false for an explicit local mock demo) and --dart-define=APP_ENV=local|staging|production.',
      );
    }
    final rest = useRestRaw.toLowerCase() == 'true';
    if (isRelease && environment != AppEnvironment.local && !rest) {
      throw StateError(
        'staging/production requires --dart-define=USE_REST_API=true; mock mode is local-only.',
      );
    }

    final override = apiBaseUrl ?? const String.fromEnvironment('API_BASE_URL');
    final resolvedUrl = resolveApiBaseUrl(
      override: override,
      isWeb: isWeb,
      platform: platform ?? defaultTargetPlatform,
      allowLoopbackFallback: environment == AppEnvironment.local,
    );
    if (rest && resolvedUrl.isEmpty) {
      throw StateError(
        'USE_REST_API=true requires --dart-define=API_BASE_URL=... '
        '(localhost fallback is local only).',
      );
    }

    return AppConfig(
      appName: 'AruSocial',
      environment: environment,
      apiBaseUrl: resolvedUrl.isEmpty ? 'http://localhost:4000/api/v1' : resolvedUrl,
      useRestApi: rest,
      demoMode: !rest,
      defaultLanguage: 'tr',
      supportedLanguages: const ['tr', 'en', 'ru'],
      reverbAppKey: reverbAppKey ??
          const String.fromEnvironment('REVERB_APP_KEY', defaultValue: 'arucad-local-key'),
      reverbHost: reverbHost ?? const String.fromEnvironment('REVERB_HOST'),
      reverbPort: reverbPort ?? const int.fromEnvironment('REVERB_PORT', defaultValue: 8080),
      reverbScheme: reverbScheme ?? const String.fromEnvironment('REVERB_SCHEME'),
      sentryDsn: sentryDsn ?? const String.fromEnvironment('SENTRY_DSN'),
      firebaseProjectId: firebaseProjectId ?? const String.fromEnvironment('FIREBASE_PROJECT_ID'),
    );
  }

  static AppEnvironment parseEnvironment(String raw, {required bool strict}) {
    switch (raw.toLowerCase().trim()) {
      case '':
      case 'local':
      case 'dev':
      case 'development':
      case 'testing':
        return AppEnvironment.local;
      case 'staging':
        return AppEnvironment.staging;
      case 'production':
      case 'prod':
        return AppEnvironment.production;
      default:
        if (strict) {
          throw StateError('Unknown APP_ENV=$raw. Use local, staging, or production.');
        }
        return AppEnvironment.local;
    }
  }

  static String resolveApiBaseUrl({
    required String override,
    required bool isWeb,
    required TargetPlatform platform,
    required bool allowLoopbackFallback,
  }) {
    if (override.isNotEmpty) return override;
    if (!allowLoopbackFallback) return '';
    const localhost = 'http://localhost:4000/api/v1';
    if (isWeb) return localhost;
    return platform == TargetPlatform.android ? 'http://10.0.2.2:4000/api/v1' : localhost;
  }
}
