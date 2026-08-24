<?php

use App\Support\SentryScrubber;

// Only the keys we actually want to change from sentry/sentry-laravel's own
// defaults (vendor/sentry/sentry-laravel/config/sentry.php) — Laravel's
// mergeConfigFrom() fills in every other key (breadcrumbs, tracing, etc.)
// from the package, so we don't need to restate them here.
return [

    // Same SENTRY_DSN convention already used for the Flutter client
    // (app/config/app_config.dart) and previously the services.php
    // placeholder. Empty in local/testing — the SDK still builds a client
    // but every capture/transport call becomes a safe no-op without a DSN
    // (see Sentry\Laravel\ServiceProvider::hasDsnSet()), so nothing here
    // needs its own "is Sentry on" branch.
    'dsn' => env('SENTRY_DSN'),

    // No separate release pipeline — best-effort short git commit hash if
    // this checkout is a git repo (e.g. a deployed build), otherwise unset.
    'release' => env('SENTRY_RELEASE') ?: SentryScrubber::gitReleaseHash(),

    // 'environment' is intentionally left unset: sentry-laravel already
    // falls back to Laravel's own APP_ENV (local/staging/production) when
    // this is empty, so staging and production events can never collide
    // under one shared config key.

    // Crash/error observability only in this milestone — no performance
    // tracing. Leaving these at their package default of null keeps
    // tracing disabled; set the env vars later if that's ever wanted.
    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE') === null ? null : (float) env('SENTRY_TRACES_SAMPLE_RATE'),
    'profiles_sample_rate' => env('SENTRY_PROFILES_SAMPLE_RATE') === null ? null : (float) env('SENTRY_PROFILES_SAMPLE_RATE'),

    // Never auto-attach IP/cookies/full request headers. With this false,
    // the SDK's own RequestIntegration already replaces Authorization,
    // Cookie, Proxy-Authorization, Set-Cookie, X-Forwarded-For and
    // X-Real-IP header values with "[Filtered]" — see
    // vendor/sentry/sentry/src/Integration/RequestIntegration.php.
    'send_default_pii' => false,

    // Pure reachability probes (docs/ENVIRONMENTS.md) — never worth an event.
    'ignore_transactions' => [
        '/up',
        '/api/v1',
        '/api/v1/health',
    ],

    // Request body fields (password, apiKey, wordpress.apiToken, ...) and
    // known secret values (SMTP, Firebase, Reverb, moderation key) are
    // redacted here before anything leaves the process — see
    // App\Support\SentryScrubber for the full policy.
    'before_send' => [SentryScrubber::class, 'scrubEvent'],
];
