import 'package:flutter_test/flutter_test.dart';
import 'package:sentry_flutter/sentry_flutter.dart';

import 'package:arucad_campus_prototype/core/observability/sentry_bootstrap.dart';

// P3-6 §22: exercises SentryBootstrapOptions in isolation (no real
// SentryFlutter.init/native channel calls, no network) — DSN/environment
// resolution and the options actually handed to the SDK are what's under
// test here, not the vendor SDK's own already-tested global handler wiring.
void main() {
  test('empty SENTRY_DSN --dart-define disables reporting (local default)', () {
    final options = SentryBootstrapOptions.fromEnvironment(dsn: '', environment: 'local');

    expect(options.isEnabled, isFalse);
    expect(options.dsn, isEmpty);
  });

  test('a configured DSN enables reporting', () {
    final options = SentryBootstrapOptions.fromEnvironment(
      dsn: 'https://public@o0.ingest.sentry.io/1',
      environment: 'staging',
    );

    expect(options.isEnabled, isTrue);
    expect(options.dsn, 'https://public@o0.ingest.sentry.io/1');
  });

  test('environment defaults to local and is not hardcoded to staging/production', () {
    final options = SentryBootstrapOptions.fromEnvironment(dsn: '', environment: null);

    expect(options.environment, 'local');
  });

  test('staging and production environments resolve independently (no shared tag)', () {
    final staging = SentryBootstrapOptions.fromEnvironment(dsn: 'x', environment: 'staging');
    final production = SentryBootstrapOptions.fromEnvironment(dsn: 'x', environment: 'production');

    expect(staging.environment, 'staging');
    expect(production.environment, 'production');
    expect(staging.environment, isNot(production.environment));
  });

  test('no performance tracing by default (traces sample rate stays null, not 0.0)', () {
    final options = SentryBootstrapOptions.fromEnvironment(dsn: 'x', environment: 'production');

    // Explicitly null, not 0.0: SentryOptions.hasTracingEnabled treats any
    // non-null rate (even 0.0) as "tracing on", which would still register
    // tracing integrations we don't want yet (P3-6 §16).
    expect(options.tracesSampleRate, isNull);
  });

  test('an explicit SENTRY_TRACES_SAMPLE_RATE is honored and clamped to [0,1]', () {
    final options = SentryBootstrapOptions.fromEnvironment(
      dsn: 'x',
      environment: 'production',
      tracesSampleRate: '0.25',
    );

    expect(options.tracesSampleRate, 0.25);
  });

  test('an out-of-range sample rate is clamped rather than rejected', () {
    final options = SentryBootstrapOptions.fromEnvironment(
      dsn: 'x',
      environment: 'production',
      tracesSampleRate: '5',
    );

    expect(options.tracesSampleRate, 1.0);
  });

  test('applyTo wires dsn/environment/tracesSampleRate onto SentryFlutterOptions', () {
    final options = SentryBootstrapOptions.fromEnvironment(
      dsn: 'https://public@o0.ingest.sentry.io/1',
      environment: 'production',
      tracesSampleRate: '0.1',
    );

    final sentryOptions = SentryFlutterOptions();
    options.applyTo(sentryOptions);

    expect(sentryOptions.dsn, 'https://public@o0.ingest.sentry.io/1');
    expect(sentryOptions.environment, 'production');
    expect(sentryOptions.tracesSampleRate, 0.1);
  });

  test('applyTo never turns on default PII (no IP/header/cookie capture)', () {
    final options = SentryBootstrapOptions.fromEnvironment(dsn: 'x', environment: 'production');

    final sentryOptions = SentryFlutterOptions();
    options.applyTo(sentryOptions);

    expect(sentryOptions.sendDefaultPii, isFalse);
  });

  test('applyTo registers a beforeSendTransaction filter', () {
    final options = SentryBootstrapOptions.fromEnvironment(dsn: 'x', environment: 'production');
    final sentryOptions = SentryFlutterOptions();
    options.applyTo(sentryOptions);

    expect(sentryOptions.beforeSendTransaction, isNotNull);
  });

  test('health/reachability transaction names are ignored (mirrors backend config)', () {
    expect(SentryBootstrapOptions.isIgnoredTransactionName('GET /api/v1/health'), isTrue);
    expect(SentryBootstrapOptions.isIgnoredTransactionName('GET /up'), isTrue);
    expect(SentryBootstrapOptions.isIgnoredTransactionName('GET /api/v1/feed'), isFalse);
    expect(SentryBootstrapOptions.isIgnoredTransactionName(null), isFalse);
  });
}
