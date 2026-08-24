<?php

namespace Tests\Feature;

use App\Events\MessageCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class ChatEventPayloadTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_broadcast_payload_matches_rest_message_shape(): void
    {
        Event::fake([MessageCreated::class]);

        [, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $payload = $this->withToken($tokenA)
            ->postJson("/api/v1/chat/{$b->name}/messages", ['text' => 'şekil'])
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('conversationId', $payload);
        $this->assertArrayHasKey('fromMe', $payload);
        $this->assertArrayHasKey('text', $payload);
        $this->assertArrayHasKey('sentAt', $payload);
        $this->assertArrayHasKey('sender', $payload);
        $this->assertArrayHasKey('peer', $payload);

        Event::assertDispatched(MessageCreated::class, function (MessageCreated $event) use ($payload) {
            $broadcast = $event->broadcastWith();

            return $broadcast['id'] === $payload['id']
                && $broadcast['text'] === $payload['text']
                && $broadcast['sender'] === $payload['sender']
                && $broadcast['peer'] === $payload['peer']
                && $broadcast['conversationId'] === $payload['conversationId']
                && $broadcast['fromMe'] === $payload['fromMe']
                && isset($broadcast['sentAt']);
        });
    }
}
