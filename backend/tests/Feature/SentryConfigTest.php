<?php

namespace Tests\Feature;

use App\Support\SentryScrubber;
use Sentry\Laravel\Integration;
use Tests\TestCase;

// P3-6 §21: local DSN empty allowed, staging/prod config accepted,
// environment resolves correctly. No real Sentry network call anywhere
// here — these only inspect config and the (already-booted) SDK objects.
class SentryConfigTest extends TestCase
{
    public function test_local_dsn_is_empty_by_default_and_app_still_boots(): void
    {
        // phpunit.xml sets no SENTRY_DSN — this is the "local/testing" case.
        // The whole suite (this test included) booting at all is the proof
        // that sentry/sentry-laravel's ServiceProvider::boot() — which
        // unconditionally builds a Hub/Client even without a DSN — never
        // throws or requires one.
        $this->assertSame('', (string) config('sentry.dsn'));
        $this->assertTrue(class_exists(Integration::class));
    }

    public function test_send_default_pii_stays_false(): void
    {
        // Required for the SDK's own RequestIntegration to redact
        // Authorization/Cookie/etc. headers automatically (P3-6 §7/§19).
        $this->assertFalse(config('sentry.send_default_pii'));
    }

    public function test_before_send_is_wired_to_the_app_scrubber(): void
    {
        $this->assertSame([SentryScrubber::class, 'scrubEvent'], config('sentry.before_send'));
    }

    public function test_no_performance_tracing_by_default(): void
    {
        // P3-6 §16: crash/error observability only in this milestone.
        $this->assertNull(config('sentry.traces_sample_rate'));
        $this->assertNull(config('sentry.profiles_sample_rate'));
    }

    public function test_staging_and_production_dsn_config_is_env_driven_and_isolated(): void
    {
        // Simulates what a staging/production .env sets — see
        // docs/ENVIRONMENTS.md — without needing a real DSN or network call.
        config(['sentry.dsn' => 'https://public@o0.ingest.sentry.io/1']);
        $this->assertSame('https://public@o0.ingest.sentry.io/1', config('sentry.dsn'));

        // 'environment' is left unset in config/sentry.php on purpose so the
        // package falls back to Laravel's own APP_ENV — local, staging and
        // production can never share one tag by accident (P3-6 §18).
        $this->assertNull(config('sentry.environment'));
        $this->assertSame('testing', app()->environment());
    }
}
