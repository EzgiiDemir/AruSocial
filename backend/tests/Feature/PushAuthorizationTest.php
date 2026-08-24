<?php

namespace Tests\Feature;

use App\Models\PushToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class PushAuthorizationTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_unauthenticated_register_is_401(): void
    {
        $this->postJson('/api/v1/push-tokens', ['token' => 'x', 'platform' => 'android'])
            ->assertUnauthorized();
    }

    public function test_authenticated_register_is_ok(): void
    {
        [, $token] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        $this->withToken($token)
            ->postJson('/api/v1/push-tokens', ['token' => 'x', 'platform' => 'web'])
            ->assertOk();
    }

    public function test_cannot_unregister_another_users_token(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $this->withToken($tokenA)
            ->postJson('/api/v1/push-tokens', ['token' => 'secret-device', 'platform' => 'android'])
            ->assertOk();

        $this->withToken($tokenB)
            ->postJson('/api/v1/push-tokens/unregister', ['token' => 'secret-device'])
            ->assertOk();

        $this->assertTrue(PushToken::where('user_id', $a->id)->where('token', 'secret-device')->exists());
    }

    public function test_registering_moves_token_off_the_previous_owner(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $this->withToken($tokenA)
            ->postJson('/api/v1/push-tokens', ['token' => 'shared-device', 'platform' => 'android'])
            ->assertOk();
        $this->withToken($tokenB)
            ->postJson('/api/v1/push-tokens', ['token' => 'shared-device', 'platform' => 'android'])
            ->assertOk();

        $this->assertEquals(0, PushToken::where('user_id', $a->id)->count());
        $this->assertEquals(1, PushToken::where('user_id', $b->id)->where('token', 'shared-device')->count());
    }
}
