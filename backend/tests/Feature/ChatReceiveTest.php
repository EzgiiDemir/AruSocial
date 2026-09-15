<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class ChatReceiveTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_b_sees_the_same_row_a_sent(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $sent = $this->withToken($tokenA)
            ->postJson("/api/v1/chat/{$b->name}/messages", ['text' => 'Merhaba B'])
            ->assertOk()
            ->json('data');

        $received = $this->withToken($tokenB)
            ->getJson("/api/v1/chat/{$a->name}/messages")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $received);
        $this->assertEquals($sent['id'], $received[0]['id']);
        $this->assertEquals('Merhaba B', $received[0]['text']);
        $this->assertFalse($received[0]['fromMe']);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_thread_unread_count_is_persistent_and_clears_when_opened(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Okuyan', 'reader@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Gönderen', 'sender@arucad.edu.tr');

        $this->withToken($tokenB)
            ->postJson("/api/v1/chat/{$a->name}/messages", ['text' => 'Kalıcı yeni mesaj'])
            ->assertOk();

        $this->withToken($tokenA)->getJson('/api/v1/chat/threads')
            ->assertOk()
            ->assertJsonPath('data.0.name', $b->name)
            ->assertJsonPath('data.0.lastMessage', 'Kalıcı yeni mesaj')
            ->assertJsonPath('data.0.unreadCount', 1);

        $this->withToken($tokenA)
            ->getJson("/api/v1/chat/{$b->name}/messages")
            ->assertOk();

        $this->assertDatabaseHas('chat_thread_prefs', [
            'user_id' => $a->id,
            'peer_user_id' => $b->id,
        ]);
        $this->withToken($tokenA)->getJson('/api/v1/chat/threads')
            ->assertOk()
            ->assertJsonPath('data.0.unreadCount', 0);
    }
}
