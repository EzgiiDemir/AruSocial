<?php

namespace Tests\Feature;

use App\Events\MessageCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class ChatDuplicateSafetyTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_one_send_emits_one_event_with_a_stable_id(): void
    {
        Event::fake([MessageCreated::class]);

        [, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $payload = $this->withToken($tokenA)
            ->postJson("/api/v1/chat/{$b->name}/messages", ['text' => 'tek'])
            ->assertOk()
            ->json('data');

        Event::assertDispatchedTimes(MessageCreated::class, 1);
        Event::assertDispatched(MessageCreated::class, function (MessageCreated $event) use ($payload) {
            return $event->broadcastWith()['id'] === $payload['id'];
        });
    }
}
