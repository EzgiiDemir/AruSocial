<?php

namespace Tests\Feature;

use App\Models\PushToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class PushTokenRegistrationTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_register_update_same_token_and_multiple_devices(): void
    {
        [, $token] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');

        $this->withToken($token)
            ->postJson('/api/v1/push-tokens', ['token' => 'fcm-phone', 'platform' => 'android'])
            ->assertOk()
            ->assertJsonPath('data.registered', true);

        $this->withToken($token)
            ->postJson('/api/v1/push-tokens', ['token' => 'fcm-phone', 'platform' => 'android'])
            ->assertOk();

        $this->withToken($token)
            ->postJson('/api/v1/push-tokens', ['token' => 'fcm-tablet', 'platform' => 'android'])
            ->assertOk();

        $this->assertEquals(2, PushToken::count());
        $this->assertEquals(1, PushToken::where('token', 'fcm-phone')->count());
    }

    public function test_unregister_removes_only_that_token(): void
    {
        [$a, $token] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        $this->withToken($token)
            ->postJson('/api/v1/push-tokens', ['token' => 'keep', 'platform' => 'ios'])
            ->assertOk();
        $this->withToken($token)
            ->postJson('/api/v1/push-tokens', ['token' => 'drop', 'platform' => 'ios'])
            ->assertOk();

        $this->withToken($token)
            ->postJson('/api/v1/push-tokens/unregister', ['token' => 'drop'])
            ->assertOk()
            ->assertJsonPath('data.unregistered', true);

        $this->assertEquals(1, PushToken::where('user_id', $a->id)->count());
        $this->assertTrue(PushToken::where('token', 'keep')->exists());
    }
}
