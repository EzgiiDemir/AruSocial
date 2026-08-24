import 'package:sentry_flutter/sentry_flutter.dart';

/// Resolves and applies the Sentry SDK options from the same `--dart-define`
/// sources `AppConfig` reads (SENTRY_DSN, APP_ENV) — kept separate from
/// `AppConfig` so `SentryFlutter.init()` can run before the rest of the app
/// (and its `AppConfig`) even exists, catching bootstrap-time errors too.
///
/// Deliberately does *not* touch `FlutterError.onError`,
/// `PlatformDispatcher.instance.onError` or `runZonedGuarded` itself:
/// `SentryFlutter.init(..., appRunner: ...)` already installs all three
/// internally, so adding our own would just double-report every crash
/// (P3-6 §15).
class SentryBootstrapOptions {
  final String dsn;
  final String environment;

  /// Null (the default) fully disables tracing — not just a 0% sample
  /// rate, which would still turn tracing integrations on (P3-6 §16).
  final double? tracesSampleRate;

  const SentryBootstrapOptions({
    required this.dsn,
    required this.environment,
    this.tracesSampleRate,
  });

  /// Empty DSN is the expected local/dev shape — `SentryFlutter.init` still
  /// runs the app normally but never reports anything (P3-6 §3).
  bool get isEnabled => dsn.isNotEmpty;

  factory SentryBootstrapOptions.fromEnvironment({
    String? dsn,
    String? environment,
    String? tracesSampleRate,
  }) {
    final rawRate = tracesSampleRate ??
        const String.fromEnvironment('SENTRY_TRACES_SAMPLE_RATE');
    final parsedRate = double.tryParse(rawRate);

    return SentryBootstrapOptions(
      dsn: dsn ?? const String.fromEnvironment('SENTRY_DSN'),
      // Same APP_ENV --dart-define AppConfig.fromEnvironment() reads, so a
      // local/staging/production build can never mix up its Sentry
      // environment tag with another build's (P3-6 §18).
      environment: environment ??
          const String.fromEnvironment('APP_ENV', defaultValue: 'local'),
      tracesSampleRate: parsedRate?.clamp(0.0, 1.0),
    );
  }

  void applyTo(SentryFlutterOptions options) {
    options.dsn = dsn;
    options.environment = environment;
    // No performance tracing (and so no profiling, which piggybacks on
    // sampled transactions) in this milestone — crash/error observability
    // only, unless explicitly opted into per-environment (P3-6 §16). Both
    // already default to null/disabled; left unset here on purpose.
    options.tracesSampleRate = tracesSampleRate;
    // No IP address, no request headers/cookies, no user id/email/name —
    // this milestone is crash/error observability only (P3-6 §10/§16); auth
    // context can be added in a later milestone if performance tracing is.
    options.sendDefaultPii = false;
    // Health/reachability noise, not errors — mirrors backend
    // config/sentry.php's ignore_transactions. No-op today since
    // tracesSampleRate is null by default, but keeps the two configs in
    // sync if tracing is ever turned on per-environment.
    options.beforeSendTransaction = (transaction, hint) {
      return isIgnoredTransactionName(transaction.transaction) ? null : transaction;
    };
  }

  /// Extracted for direct unit testing without constructing a real
  /// [SentryTransaction]/[SentryTracer] (P3-6 §22).
  static bool isIgnoredTransactionName(String? name) {
    if (name == null) return false;

    return name.contains('/health') || name.contains('/up');
  }
}
