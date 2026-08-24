<?php

namespace Tests\Feature;

use App\Models\Notification as InboxNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class ChatNotificationTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_a_message_notifies_b_and_not_a(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $this->withToken($tokenA)
            ->postJson("/api/v1/chat/{$b->name}/messages", ['text' => 'ping'])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $b->id,
            'actor_user_id' => $a->id,
            'kind' => 'message',
        ]);
        $this->assertEquals(0, InboxNotification::where('user_id', $a->id)->count());
        $this->assertEquals(1, InboxNotification::where('user_id', $b->id)->count());

        $inboxA = $this->withToken($tokenA)->getJson('/api/v1/notifications')->assertOk()->json('data');
        $inboxB = $this->withToken($tokenB)->getJson('/api/v1/notifications')->assertOk()->json('data');
        $this->assertCount(0, $inboxA);
        $this->assertEquals('message', $inboxB[0]['kind']);
    }
}
