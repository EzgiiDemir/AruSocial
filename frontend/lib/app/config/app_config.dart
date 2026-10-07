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
  static const productionApiBaseUrl =
      'https://api-aruverse.arucad.edu.tr/api/v1';

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
  final List<String> tlsPins;

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
    this.reverbPort = 8091,
    this.reverbScheme = '',
    this.sentryDsn = '',
    this.firebaseProjectId = '',
    this.tlsPins = const [],
  });

  factory AppConfig.demo() => const AppConfig(
        appName: 'ARUVERSE',
        environment: AppEnvironment.local,
        apiBaseUrl: 'http://127.0.0.1:4000/api/v1',
        useRestApi: false,
        demoMode: true,
        defaultLanguage: 'tr',
        supportedLanguages: ['tr', 'en', 'ru'],
      );

  AppConfig copyWith({
    String? apiBaseUrl,
    bool? useRestApi,
  }) {
    final rest = useRestApi ?? this.useRestApi;
    return AppConfig(
      appName: appName,
      environment: environment,
      apiBaseUrl: apiBaseUrl ?? this.apiBaseUrl,
      useRestApi: rest,
      demoMode: !rest,
      defaultLanguage: defaultLanguage,
      supportedLanguages: supportedLanguages,
      reverbAppKey: reverbAppKey,
      reverbHost: reverbHost,
      reverbPort: reverbPort,
      reverbScheme: reverbScheme,
      sentryDsn: sentryDsn,
      firebaseProjectId: firebaseProjectId,
      tlsPins: tlsPins,
    );
  }

  /// Turn a host typed in login Settings into the Laravel API root.
  /// `192.168.1.8` + port 4000 → `http://192.168.1.8:4000/api/v1`.
  static String buildLocalApiBaseUrl(String host, {int port = 4000}) {
    var raw = host.trim();
    if (raw.isEmpty) return '';
    if (!raw.contains('://')) raw = 'http://$raw';
    final uri = Uri.tryParse(raw);
    if (uri == null || uri.host.isEmpty) return '';
    final resolvedHost = uri.host == 'localhost' ? '127.0.0.1' : uri.host;
    return Uri(
      scheme: uri.scheme.isEmpty ? 'http' : uri.scheme,
      host: resolvedHost,
      port: uri.hasPort ? uri.port : port,
      path: '/api/v1',
    ).toString();
  }

  /// Hosts that are only valid for debug / emulator loopback. Release
  /// builds must not silently talk to these.
  static const loopbackHosts = {
    'localhost',
    '127.0.0.1',
    '::1',
    '10.0.2.2',
    '0.0.0.0',
  };

  static bool isLoopbackHost(String hostOrUrl) {
    final raw = hostOrUrl.trim().toLowerCase();
    if (raw.isEmpty) return false;
    if (loopbackHosts.contains(raw)) return true;
    final uri = Uri.tryParse(raw.contains('://') ? raw : 'http://$raw');
    final host = uri?.host.toLowerCase() ?? '';
    if (host.isNotEmpty) return loopbackHosts.contains(host);
    return loopbackHosts.contains(raw.split(':').first);
  }

  static bool isLoopbackApiUrl(String url) => isLoopbackHost(url);

  /// Debug-only override. A release APK is configured at build time and
  /// never asks a student for an IP address or port on the login screen.
  AppConfig applyRuntimeOverride({
    required bool isRelease,
    bool? useRestApi,
    String host = '',
    int port = 4000,
  }) {
    if (useRestApi == null) return this;
    if (isRelease) return this;
    if (!useRestApi) return copyWith(useRestApi: false);
    final url = host.trim().isEmpty
        ? apiBaseUrl
        : AppConfig.buildLocalApiBaseUrl(host, port: port);
    if (url.isEmpty) return copyWith(useRestApi: true);
    return copyWith(useRestApi: true, apiBaseUrl: url);
  }

  /// Compile-time `--dart-define` values, with testable overrides.
  ///
  /// Debug: unset `USE_REST_API` → REST + loopback. Explicit `false` → mock.
  /// Release: always REST. Unset/missing URL or loopback → fail-fast, never mock.
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
    String? tlsPinSha256,
  }) {
    const compiledEnvironment = String.fromEnvironment('APP_ENV');
    final envRaw = appEnv ??
        (compiledEnvironment.isEmpty
            ? (isRelease ? 'production' : 'local')
            : compiledEnvironment);
    final environment = parseEnvironment(envRaw, strict: isRelease);
    final useRestRaw =
        useRestApi ?? const String.fromEnvironment('USE_REST_API');
    final rest = useRestRaw.isEmpty || useRestRaw.toLowerCase() == 'true';
    if (isRelease && !rest) {
      throw StateError(
        'Release derlemede mock (USE_REST_API=false) kullanılamaz. '
        '--dart-define=USE_REST_API=true ve API_BASE_URL zorunludur.',
      );
    }

    final configuredUrl =
        apiBaseUrl ?? const String.fromEnvironment('API_BASE_URL');
    final override = configuredUrl.trim().isEmpty &&
            isRelease &&
            environment == AppEnvironment.production
        ? productionApiBaseUrl
        : configuredUrl;
    final resolvedUrl = resolveApiBaseUrl(
      override: override,
      isWeb: isWeb,
      platform: platform ?? defaultTargetPlatform,
      allowLoopbackFallback: !isRelease && environment == AppEnvironment.local,
    );
    if (rest && resolvedUrl.isEmpty) {
      if (isRelease) {
        throw StateError(
          'Release APK için API_BASE_URL zorunludur. Uygulamayı '
          'deploy/scripts/build-android.ps1 ile derleyin.',
        );
      } else {
        throw StateError(
          'USE_REST_API=true requires --dart-define=API_BASE_URL=... '
          '(localhost fallback is local debug only).',
        );
      }
    }
    if (isRelease && resolvedUrl.isNotEmpty && isLoopbackApiUrl(resolvedUrl)) {
      throw StateError(
        'Release derlemede API_BASE_URL localhost/127.0.0.1/10.0.2.2 olamaz. '
        'LAN (ör. 192.168.x.x) veya genel bir HTTPS adresi kullanın.',
      );
    }

    return AppConfig(
      appName: 'ARUVERSE',
      environment: environment,
      apiBaseUrl: resolvedUrl.isEmpty && !isRelease
          ? 'http://127.0.0.1:4000/api/v1'
          : resolvedUrl,
      useRestApi: rest,
      demoMode: !rest,
      defaultLanguage: 'tr',
      supportedLanguages: const ['tr', 'en', 'ru'],
      reverbAppKey: reverbAppKey ??
          const String.fromEnvironment('REVERB_APP_KEY',
              defaultValue: 'arucad-local-key'),
      reverbHost: reverbHost ?? const String.fromEnvironment('REVERB_HOST'),
      reverbPort: reverbPort ??
          const int.fromEnvironment('REVERB_PORT', defaultValue: 8091),
      reverbScheme:
          reverbScheme ?? const String.fromEnvironment('REVERB_SCHEME'),
      sentryDsn: sentryDsn ?? const String.fromEnvironment('SENTRY_DSN'),
      firebaseProjectId: firebaseProjectId ??
          const String.fromEnvironment('FIREBASE_PROJECT_ID'),
      tlsPins: _parsePins(
          tlsPinSha256 ?? const String.fromEnvironment('TLS_PIN_SHA256')),
    );
  }

  static List<String> _parsePins(String raw) {
    if (raw.trim().isEmpty) return const [];
    return raw
        .split(',')
        .map((p) => p.trim())
        .where((p) => p.isNotEmpty)
        .toList();
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
          throw StateError(
              'Unknown APP_ENV=$raw. Use local, staging, or production.');
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
    const loopback = 'http://127.0.0.1:4000/api/v1';
    if (isWeb) return loopback;
    return platform == TargetPlatform.android
        ? 'http://10.0.2.2:4000/api/v1'
        : loopback;
  }
}
