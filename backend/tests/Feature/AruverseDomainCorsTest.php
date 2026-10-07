<?php

namespace Tests\Feature;

use App\Support\EnvironmentGuard;
use App\Support\UnsafeEnvironmentException;
use Tests\TestCase;

/**
 * The three ARUVERSE hostnames, pinned.
 *
 * CorsProductionTest already proves the allow-list mechanism works with
 * placeholder origins. This file pins the *actual* production values from
 * backend/.env.production.example, so a future edit that widens them — or
 * quietly drops the admin origin and breaks the panel — fails here rather
 * than in a browser console after deploy.
 */
class AruverseDomainCorsTest extends TestCase
{
    private const APP_ORIGIN = 'https://app-aruverse.arucad.edu.tr';

    private const ADMIN_ORIGIN = 'https://admin-aruverse.arucad.edu.tr';

    private const API_ORIGIN = 'https://api-aruverse.arucad.edu.tr';

    protected function setUp(): void
    {
        parent::setUp();

        // Exactly the CORS_ALLOWED_ORIGINS line shipped in
        // .env.production.example, with the dev loopback patterns off the way
        // config/cors.php turns them off outside local/testing.
        config([
            'cors.allowed_origins' => [self::APP_ORIGIN, self::ADMIN_ORIGIN],
            'cors.allowed_origins_patterns' => [],
        ]);
    }

    private function preflight(string $origin)
    {
        return $this->call('OPTIONS', '/api/v1/health', server: [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
    }

    public function test_the_student_app_origin_may_call_the_api(): void
    {
        $this->preflight(self::APP_ORIGIN)
            ->assertHeader('Access-Control-Allow-Origin', self::APP_ORIGIN);
    }

    public function test_the_admin_origin_may_call_the_api(): void
    {
        $this->preflight(self::ADMIN_ORIGIN)
            ->assertHeader('Access-Control-Allow-Origin', self::ADMIN_ORIGIN);
    }

    /**
     * The API's own hostname is deliberately NOT in the list. Nothing serves
     * a browser page from there, so an Origin claiming to be it is either a
     * misconfiguration or forged.
     */
    public function test_the_api_hostname_itself_is_not_an_allowed_origin(): void
    {
        $this->assertNull(
            $this->preflight(self::API_ORIGIN)->headers->get('Access-Control-Allow-Origin')
        );
    }

    public function test_a_lookalike_hostname_is_denied(): void
    {
        foreach ([
            'https://app-aruverse.arucad.edu.tr.evil.example',
            'http://app-aruverse.arucad.edu.tr',   // plain HTTP is a different origin
            'https://aruverse.arucad.edu.tr',
            'https://app-aruverse.arucad.edu.tr:8443',
        ] as $origin) {
            $this->assertNull(
                $this->preflight($origin)->headers->get('Access-Control-Allow-Origin'),
                "origin should have been denied: {$origin}",
            );
        }
    }

    public function test_credentials_are_not_enabled_for_the_api(): void
    {
        // The API authenticates with bearer tokens, never cookies. If this
        // ever flips to true, a browser would start attaching the admin
        // session cookie to cross-origin API calls.
        $this->assertFalse((bool) config('cors.supports_credentials'));

        $this->assertNull(
            $this->preflight(self::APP_ORIGIN)->headers->get('Access-Control-Allow-Credentials')
        );
    }

    public function test_environment_guard_rejects_a_wildcard_origin_in_production(): void
    {
        $this->expectException(UnsafeEnvironmentException::class);

        EnvironmentGuard::assertFor($this->productionState(['cors_origins' => ['*']]));
    }

    public function test_environment_guard_accepts_the_two_aruverse_origins(): void
    {
        EnvironmentGuard::assertFor($this->productionState([
            'cors_origins' => [self::APP_ORIGIN, self::ADMIN_ORIGIN],
        ]));

        // assertFor() throws on failure and returns nothing on success.
        $this->addToAssertionCount(1);
    }

    /**
     * A minimal production-shaped state for EnvironmentGuard, so these two
     * tests exercise the CORS rule rather than tripping over an unrelated
     * missing value.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function productionState(array $overrides): array
    {
        return array_merge([
            'env' => 'production',
            'debug' => false,
            'url' => self::API_ORIGIN,
            'key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'db' => 'pgsql',
            'queue' => 'database',
            'broadcast' => 'reverb',
            'cors_origins' => [self::APP_ORIGIN, self::ADMIN_ORIGIN],
            'reverb_key' => 'realkey',
            'reverb_secret' => 'realsecret',
            'reverb_host' => 'api-aruverse.arucad.edu.tr',
            'fcm_required' => false,
            'fcm_project_id' => '',
            'fcm_client_email' => '',
            'fcm_private_key' => '',
            'mailer' => 'smtp',
            'mail_host' => 'smtp.arucad.edu.tr',
            'mail_username' => 'noreply',
            'mail_password' => 'secret',
            'mail_from_address' => 'noreply@arucad.edu.tr',
        ], $overrides);
    }
}
