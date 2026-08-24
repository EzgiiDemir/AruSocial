<?php

namespace Tests\Feature;

use Tests\TestCase;

// P3-7 §4/§19: outside local/testing, config/cors.php disables the
// loopback-by-pattern convenience entirely — only origins named explicitly
// in CORS_ALLOWED_ORIGINS may talk to the API. HandleCors reads
// config('cors') fresh on every request (Illuminate\Http\Middleware\
// HandleCors::handle()), so setting it here reproduces exactly what a
// production/staging boot looks like without needing a second app instance.
class CorsProductionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reproduces config/cors.php's own production branch: no loopback
        // patterns, real approved web origins. Two origins on purpose —
        // Fruitcake\Cors optimizes a *single* configured origin by always
        // echoing it back verbatim (safe: the browser itself then rejects
        // the mismatch against its own page origin), which would make an
        // explicitly-denied-origin assertion here misleading. With two or
        // more, it takes the dynamic isOriginAllowed() path this suite
        // means to exercise.
        config([
            'cors.allowed_origins' => ['https://app.example.com', 'https://admin.example.com'],
            'cors.allowed_origins_patterns' => [],
        ]);
    }

    public function test_the_approved_production_origin_is_allowed(): void
    {
        $this->call('OPTIONS', '/api/v1/health', server: [
            'HTTP_ORIGIN' => 'https://app.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com');
    }

    public function test_a_random_origin_is_denied(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/health', server: [
            'HTTP_ORIGIN' => 'https://evil.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    // The loopback dev convenience (config/cors.php's allowed_origins_patterns)
    // must not leak into a production-shaped config — this is what makes
    // "staging/production only answer their own origins" actually true.
    public function test_a_localhost_origin_is_no_longer_special_cased(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/health', server: [
            'HTTP_ORIGIN' => 'http://localhost:56918',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    // A denied origin must get no CORS header at all, never a wildcard —
    // Fruitcake\Cors only ever echoes back a name from allowed_origins.
    public function test_a_denied_origin_never_receives_a_wildcard(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/health', server: [
            'HTTP_ORIGIN' => 'https://evil.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_a_real_request_from_the_approved_origin_carries_the_allow_origin_header(): void
    {
        $this->getJson('/api/v1/health', ['Origin' => 'https://app.example.com'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com');
    }
}
