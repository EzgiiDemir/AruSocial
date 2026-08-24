<?php

namespace Tests\Feature;

use Tests\TestCase;

// `flutter run -d chrome` serves the app from a different random localhost
// port on every launch, so the fixed CORS_ALLOWED_ORIGINS list can't cover
// it and every preflight was blocked. config/cors.php answers loopback
// origins by pattern instead — outside production only.
class CorsApiTest extends TestCase
{
    public function test_preflight_allows_a_random_flutter_debug_port(): void
    {
        $this->call('OPTIONS', '/api/v1/health', server: [
            'HTTP_ORIGIN' => 'http://localhost:56918',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertHeader('Access-Control-Allow-Origin', 'http://localhost:56918');
    }

    public function test_preflight_allows_the_loopback_ip_form(): void
    {
        $this->call('OPTIONS', '/api/v1/health', server: [
            'HTTP_ORIGIN' => 'http://127.0.0.1:8080',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertHeader('Access-Control-Allow-Origin', 'http://127.0.0.1:8080');
    }

    // The patterns are a local-development convenience, not a blanket
    // opening: anything that isn't loopback still has to be named
    // explicitly in CORS_ALLOWED_ORIGINS.
    public function test_an_unrelated_origin_is_still_not_allowed(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/health', server: [
            'HTTP_ORIGIN' => 'https://evil.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_a_real_request_from_a_debug_port_carries_the_allow_origin_header(): void
    {
        $this->getJson('/api/v1/health', ['Origin' => 'http://localhost:56918'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:56918');
    }
}
