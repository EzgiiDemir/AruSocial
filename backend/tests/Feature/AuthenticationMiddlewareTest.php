<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The API used to answer every request without any credentials at all,
// including /admin/* (docs/AUDIT_GERCEK_URUN.md §7). These tests pin down
// exactly which routes are still public and prove the rest are not.
class AuthenticationMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_protected_endpoints_reject_a_request_with_no_token(): void
    {
        $endpoints = [
            ['GET', '/api/v1/me'],
            ['GET', '/api/v1/feed'],
            ['POST', '/api/v1/places/place-x/reviews'],
            ['POST', '/api/v1/feed/post-x/comments'],
            ['GET', '/api/v1/events'],
            ['POST', '/api/v1/checkins'],
            ['GET', '/api/v1/notifications'],
            ['POST', '/api/v1/notifications/x/read'],
            ['POST', '/api/v1/social/follow'],
            ['POST', '/api/v1/social/block'],
            ['GET', '/api/v1/social/following'],
            ['GET', '/api/v1/chat/threads'],
            ['POST', '/api/v1/chat/Someone/messages'],
            ['POST', '/api/v1/auth/logout'],
            ['GET', '/api/v1/admin/stats'],
            ['POST', '/api/v1/admin/events'],
            ['GET', '/api/v1/admin/roles'],
        ];

        foreach ($endpoints as [$method, $uri]) {
            $response = $this->json($method, $uri);

            $response->assertStatus(401, "$method $uri should require a token");
            $this->assertEquals('AUTH_REQUIRED', $response->json('error.code'), $uri);
            $this->assertNull($response->json('data'), $uri);
            $this->assertStringStartsWith('req-', $response->json('meta.request_id'), $uri);
        }
    }

    // Regression test: a request that doesn't ask for JSON used to get a
    // 500 "Route [login] not defined" from Laravel's guest-redirect default
    // — so curl and a browser address bar reported a broken server where
    // the real answer is "you need a token". See bootstrap/app.php.
    public function test_a_request_that_does_not_ask_for_json_still_gets_the_401_envelope(): void
    {
        $response = $this->get('/api/v1/me');

        $response->assertStatus(401);
        $this->assertEquals('AUTH_REQUIRED', $response->json('error.code'));
    }

    public function test_a_garbage_token_is_rejected_the_same_way(): void
    {
        $this->withToken('not-a-real-token')->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
    }

    public function test_diagnostics_stay_public(): void
    {
        $this->getJson('/api/v1')->assertOk();
        $this->getJson('/api/v1/health')->assertOk();
    }

    public function test_opening_a_session_does_not_itself_require_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/session', [
            'email' => 'acilis@arucad.edu.tr',
            'password' => 'bir-sifre',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.token'));
    }
}
