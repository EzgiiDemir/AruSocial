<?php

namespace Tests\Feature;

use App\Events\MessageCreated;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class ChatBroadcastTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_a_successful_send_broadcasts_message_created(): void
    {
        Event::fake([MessageCreated::class]);

        [, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $payload = $this->withToken($tokenA)
            ->postJson("/api/v1/chat/{$b->name}/messages", ['text' => 'Merhaba B'])
            ->assertOk()
            ->json('data');

        Event::assertDispatched(MessageCreated::class, function (MessageCreated $event) use ($payload, $b) {
            $on = collect($event->broadcastOn())->map->name->all();

            return $event->broadcastWith()['id'] === $payload['id']
                && in_array('private-conversation.'.$event->message->conversation_id, $on, true)
                && in_array('private-user.'.$b->id, $on, true)
                && $event->broadcastAs() === 'message.created';
        });
        $this->assertEquals(1, Conversation::count());
    }
}
