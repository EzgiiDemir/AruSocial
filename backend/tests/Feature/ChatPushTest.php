<?php

namespace Tests\Feature;

use App\Events\MessageCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class ChatPushTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_chat_dispatches_fcm_to_recipient_devices_not_sender(): void
    {
        $fcm = $this->fakeFcm();
        Event::fake([MessageCreated::class]);

        [, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $this->withToken($tokenA)
            ->postJson('/api/v1/push-tokens', ['token' => 'a-phone', 'platform' => 'android'])
            ->assertOk();
        $this->withToken($tokenB)
            ->postJson('/api/v1/push-tokens', ['token' => 'b-phone', 'platform' => 'ios'])
            ->assertOk();
        $this->withToken($tokenB)
            ->postJson('/api/v1/push-tokens', ['token' => 'b-tablet', 'platform' => 'android'])
            ->assertOk();

        $payload = $this->withToken($tokenA)
            ->postJson("/api/v1/chat/{$b->name}/messages", ['text' => 'push me'])
            ->assertOk()
            ->json('data');

        Event::assertDispatched(MessageCreated::class);
        $this->assertCount(2, $fcm->sent);
        $tokens = array_column($fcm->sent, 'token');
        sort($tokens);
        $this->assertSame(['b-phone', 'b-tablet'], $tokens);
        $this->assertEquals('message', $fcm->sent[0]['data']['type']);
        $this->assertEquals($payload['id'], $fcm->sent[0]['data']['messageId']);
        $this->assertEquals((string) $payload['conversationId'], $fcm->sent[0]['data']['conversationId']);
        $this->assertEquals('Kullanıcı A', $fcm->sent[0]['data']['peer']);
    }
}
