<?php

namespace Tests\Feature;

use App\Support\EnvironmentGuard;
use App\Support\UnsafeEnvironmentException;
use Tests\TestCase;

class EnvironmentConfigTest extends TestCase
{
    public function test_testing_and_local_do_not_require_pgsql(): void
    {
        $this->assertSame('testing', config('app.env'));
        EnvironmentGuard::assertSafe();
        EnvironmentGuard::assertFor(['env' => 'local', 'debug' => true, 'db' => 'sqlite']);
        EnvironmentGuard::assertFor(['env' => 'testing', 'debug' => true, 'db' => 'sqlite']);
        $this->addToAssertionCount(2);
    }

    public function test_cache_session_and_redis_prefixes_include_the_environment(): void
    {
        $this->assertStringContainsString('testing', (string) config('cache.prefix'));
        $this->assertStringContainsString('testing', (string) config('session.cookie'));
        $this->assertStringContainsString('testing', (string) config('database.redis.options.prefix'));
    }

    public function test_media_url_follows_app_url_unless_overridden(): void
    {
        $this->assertSame('public', config('filesystems.media_disk'));
        config(['app.url' => 'https://staging-api.example.com']);
        $this->assertStringStartsWith(
            'https://staging-api.example.com',
            rtrim((string) config('app.url'), '/'),
        );
    }

    public function test_staging_like_config_boots_when_debug_is_off_and_pgsql_is_set(): void
    {
        EnvironmentGuard::assertFor($this->validRemoteState('staging'));
        EnvironmentGuard::assertFor($this->validRemoteState('production'));
        $this->addToAssertionCount(2);
    }

    public function test_production_rejects_local_fallbacks(): void
    {
        $this->expectException(UnsafeEnvironmentException::class);
        EnvironmentGuard::assertFor([
            'env' => 'production',
            'debug' => true,
            'url' => 'http://localhost:4000',
            'key' => '',
            'db' => 'sqlite',
            'queue' => 'sync',
            'reverb_key' => '',
            'reverb_secret' => '',
            'reverb_host' => 'localhost',
        ]);
    }

    public function test_production_rejects_loopback_reverb_and_sqlite_even_when_debug_is_off(): void
    {
        $this->expectException(UnsafeEnvironmentException::class);
        EnvironmentGuard::assertFor([
            ...$this->validRemoteState('production'),
            'db' => 'sqlite',
            'reverb_host' => '127.0.0.1',
        ]);
    }

    public function test_partial_firebase_credentials_are_rejected_on_staging(): void
    {
        $this->expectException(UnsafeEnvironmentException::class);
        EnvironmentGuard::assertFor([
            ...$this->validRemoteState('staging'),
            'fcm_project_id' => 'proj',
            'fcm_client_email' => '',
            'fcm_private_key' => '',
        ]);
    }

    public function test_empty_firebase_is_allowed_until_fcm_required(): void
    {
        EnvironmentGuard::assertFor($this->validRemoteState('staging'));
        $this->expectException(UnsafeEnvironmentException::class);
        EnvironmentGuard::assertFor([
            ...$this->validRemoteState('production'),
            'fcm_required' => true,
        ]);
    }

    // P3-7 §18: production/staging must never boot with an empty CORS
    // allowlist — Fruitcake\Cors falling back to "allow everything" on an
    // empty list is exactly the silent wildcard this guard exists to block.
    public function test_empty_cors_origins_are_rejected_in_production(): void
    {
        $this->expectException(UnsafeEnvironmentException::class);
        EnvironmentGuard::assertFor([
            ...$this->validRemoteState('production'),
            'cors_origins' => [],
        ]);
    }

    public function test_a_literal_wildcard_cors_origin_is_rejected(): void
    {
        $this->expectException(UnsafeEnvironmentException::class);
        EnvironmentGuard::assertFor([
            ...$this->validRemoteState('production'),
            'cors_origins' => ['*'],
        ]);
    }

    public function test_a_wildcard_mixed_with_a_real_origin_is_still_rejected(): void
    {
        $this->expectException(UnsafeEnvironmentException::class);
        EnvironmentGuard::assertFor([
            ...$this->validRemoteState('production'),
            'cors_origins' => ['https://app.example.com', '*'],
        ]);
    }

    public function test_a_localhost_cors_origin_is_rejected_in_staging(): void
    {
        $this->expectException(UnsafeEnvironmentException::class);
        EnvironmentGuard::assertFor([
            ...$this->validRemoteState('staging'),
            'cors_origins' => ['http://localhost:8090'],
        ]);
    }

    public function test_a_real_web_origin_boots_cleanly_in_both_staging_and_production(): void
    {
        EnvironmentGuard::assertFor([
            ...$this->validRemoteState('staging'),
            'cors_origins' => ['https://staging-app.example.com', 'https://staging-admin.example.com'],
        ]);
        EnvironmentGuard::assertFor([
            ...$this->validRemoteState('production'),
            'cors_origins' => ['https://app.example.com'],
        ]);
        $this->addToAssertionCount(2);
    }

    /**
     * @return array<string, mixed>
     */
    private function validRemoteState(string $env): array
    {
        return [
            'env' => $env,
            'debug' => false,
            'url' => 'https://'.$env.'-api.example.com',
            'key' => 'base64:test-key',
            'db' => 'pgsql',
            'queue' => 'database',
            'cors_origins' => ['https://'.$env.'-app.example.com'],
            'reverb_key' => 'public-key',
            'reverb_secret' => 'server-secret',
            'reverb_host' => $env.'.reverb.example.com',
            'fcm_required' => false,
            'fcm_project_id' => '',
            'fcm_client_email' => '',
            'fcm_private_key' => '',
            'mailer' => $env === 'production' ? 'smtp' : 'log',
            'mail_host' => $env === 'production' ? 'smtp.example.com' : '',
            'mail_username' => $env === 'production' ? 'campus' : '',
            'mail_password' => $env === 'production' ? 'not-a-real-secret' : '',
            'mail_from_address' => $env === 'production' ? 'campus@example.com' : '',
        ];
    }
}
