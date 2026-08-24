<?php

namespace Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

// P3-7 §6: the API authenticates with Sanctum bearer tokens, not cookies —
// ApiClient never sends `credentials: 'include'` — so CORS must not enable
// credentialed requests. Confirms config/cors.php's supports_credentials
// stays false end-to-end, both on preflight and on the real request.
class CorsCredentialsTest extends TestCase
{
    public function test_preflight_never_sets_allow_credentials(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/health', server: [
            'HTTP_ORIGIN' => 'http://localhost:8090',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Credentials'));
    }

    public function test_a_real_request_never_sets_allow_credentials(): void
    {
        $this->getJson('/api/v1/health', ['Origin' => 'http://localhost:8090'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:8090');

        $response = $this->getJson('/api/v1/health', ['Origin' => 'http://localhost:8090']);
        $this->assertNull($response->headers->get('Access-Control-Allow-Credentials'));
    }

    public function test_supports_credentials_config_is_false(): void
    {
        $this->assertFalse(config('cors.supports_credentials'));
    }

    // Bearer-token auth doesn't need Sanctum's SPA cookie/session mode at
    // all — asserting it stays off documents that CORS tightening never
    // quietly turned it on.
    public function test_sanctum_stateful_api_middleware_is_not_enabled(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $property = new \ReflectionProperty($kernel, 'middlewareGroups');
        $property->setAccessible(true);
        $apiGroup = $property->getValue($kernel)['api'] ?? [];

        $this->assertNotContains(
            EnsureFrontendRequestsAreStateful::class,
            $apiGroup,
        );
    }
}
