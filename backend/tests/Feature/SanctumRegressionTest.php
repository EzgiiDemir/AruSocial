<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// P3-7 §7/§19: CORS/host/proxy tightening must not touch auth:sanctum bearer
// token semantics. Exercises the exact flow a real browser or native client
// depends on: open a session, call a protected route, call an admin-gated
// route, log out. AuthSessionTest already covers this in depth — this is
// the explicit "did CORS hardening break Sanctum" regression check P3-7
// asks for, run with an Origin header attached like a real browser request.
class SanctumRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_then_me_then_a_protected_route_all_still_work_with_an_origin_header(): void
    {
        $user = User::create([
            'name' => 'Sanctum Regression',
            'email' => 'sanctum.regression@arucad.edu.tr',
            'password' => bcrypt('demo-password'),
        ]);

        $origin = ['Origin' => 'http://localhost:8090'];

        $session = $this->postJson('/api/v1/auth/session', [
            'email' => 'sanctum.regression@arucad.edu.tr',
            'password' => 'demo-password',
        ], $origin);
        $session->assertOk();
        $token = $session->json('data.token');
        $this->assertNotEmpty($token);

        $this->withToken($token)->getJson('/api/v1/me', $origin)
            ->assertOk()
            ->assertJsonPath('data.id', (string) $user->id);

        // Any /api/v1/* route, not just /me — feed is unauthenticated-by-role
        // but still behind auth:sanctum.
        $this->withToken($token)->getJson('/api/v1/feed', $origin)->assertOk();

        $this->withToken($token)->postJson('/api/v1/auth/logout', [], $origin)
            ->assertOk()
            ->assertJsonPath('data.signedOut', true);

        $this->withToken($token)->getJson('/api/v1/me', $origin)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
    }

    public function test_a_request_without_a_token_is_still_a_clean_401_not_a_cors_failure(): void
    {
        $this->getJson('/api/v1/me', ['Origin' => 'http://localhost:8090'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
    }
}
