<?php

namespace Tests\Feature;

use App\Events\MessageCreated;
use App\Models\ChatMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class ChatNoBroadcastOnFailureTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_failed_create_does_not_broadcast(): void
    {
        Event::fake([MessageCreated::class]);

        [, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $this->withToken($tokenA)
            ->postJson("/api/v1/chat/{$b->name}/messages", ['text' => ''])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION');

        $this->withToken($tokenA)
            ->postJson('/api/v1/chat/Kimse Yok/messages', ['text' => 'x'])
            ->assertStatus(404);

        Event::assertNotDispatched(MessageCreated::class);
        $this->assertEquals(0, ChatMessage::count());
    }
}
