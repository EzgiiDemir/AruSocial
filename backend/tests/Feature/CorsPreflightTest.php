<?php

namespace Tests\Feature;

use Tests\TestCase;

// P3-7 §8/§10: verifies the exact preflight contract a browser client
// relies on — the methods/headers ApiClient (frontend/lib/core/network/
// api_client.dart) actually sends must be answered, and nothing wider than
// the real 108-route inventory's method set is advertised.
class CorsPreflightTest extends TestCase
{
    public function test_authorization_header_is_accepted_in_preflight(): void
    {
        // Fruitcake\Cors answers a valid preflight with 204 No Content —
        // the browser only cares about the headers, not the body/status.
        $this->call('OPTIONS', '/api/v1/health', server: [
            'HTTP_ORIGIN' => 'http://localhost:8090',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization, content-type',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Headers');
    }

    public function test_post_with_json_content_type_preflights_successfully(): void
    {
        $this->call('OPTIONS', '/api/v1/feed', server: [
            'HTTP_ORIGIN' => 'http://localhost:8090',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization, content-type, accept',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:8090');
    }

    public function test_allowed_methods_cover_get_post_and_delete(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/health', server: [
            'HTTP_ORIGIN' => 'http://localhost:8090',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $allowed = (string) $response->headers->get('Access-Control-Allow-Methods');
        foreach (['GET', 'POST', 'DELETE', 'OPTIONS'] as $method) {
            $this->assertStringContainsString($method, $allowed);
        }
    }

    // No route in the 108-route inventory (docs/API_CONTRACT.md) uses PUT or
    // PATCH — the allowlist must not advertise a method the API never
    // implements.
    public function test_allowed_methods_do_not_include_put_or_patch(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/health', server: [
            'HTTP_ORIGIN' => 'http://localhost:8090',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $allowed = (string) $response->headers->get('Access-Control-Allow-Methods');
        $this->assertStringNotContainsString('PUT', $allowed);
        $this->assertStringNotContainsString('PATCH', $allowed);
    }

    // /up is the framework health route, deliberately outside cors.php's
    // `paths` — it must not gain browser CORS headers just because /api/v1
    // does (P3-7 §11).
    public function test_the_framework_health_route_is_not_a_cors_endpoint(): void
    {
        $response = $this->call('OPTIONS', '/up', server: [
            'HTTP_ORIGIN' => 'http://localhost:8090',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }
}
