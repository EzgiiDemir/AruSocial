<?php

namespace Tests\Feature;

use App\Models\PushToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class InvalidTokenCleanupTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_permanent_fcm_error_deletes_the_token(): void
    {
        $fcm = $this->fakeFcm();
        $fcm->invalidTokens['dead-token'] = true;

        [, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');
        $this->withToken($tokenB)
            ->postJson('/api/v1/push-tokens', ['token' => 'dead-token', 'platform' => 'android'])
            ->assertOk();
        $this->withToken($tokenB)
            ->postJson('/api/v1/push-tokens', ['token' => 'live-token', 'platform' => 'android'])
            ->assertOk();

        $this->withToken($tokenA)
            ->postJson('/api/v1/social/follow', ['peer' => $b->name])
            ->assertOk();

        $this->assertFalse(PushToken::where('token', 'dead-token')->exists());
        $this->assertTrue(PushToken::where('token', 'live-token')->exists());
    }
}
