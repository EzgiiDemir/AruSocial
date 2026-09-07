<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class ChatSendTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_sending_stores_the_authenticated_user_as_sender(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $payload = $this->withToken($tokenA)
            ->postJson("/api/v1/chat/{$b->name}/messages", ['text' => 'Merhaba B'])
            ->assertOk()
            ->json('data');

        $this->assertTrue($payload['fromMe']);
        $this->assertEquals('Merhaba B', $payload['text']);
        $this->assertDatabaseHas('messages', [
            'id' => $payload['id'],
            'sender_id' => $a->id,
            'body' => 'Merhaba B',
        ]);
        $this->assertEquals(1, ChatMessage::count());
        $this->assertEquals($a->id, ChatMessage::value('sender_id'));
    }

    public function test_unknown_and_self_peers_are_rejected(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');

        $this->withToken($tokenA)->postJson('/api/v1/chat/Kimse Yok/messages', ['text' => 'x'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'USER_NOT_FOUND');

        $this->withToken($tokenA)->postJson("/api/v1/chat/{$a->name}/messages", ['text' => 'x'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION');

        $this->assertEquals(0, ChatMessage::count());
    }

    public function test_abusive_chat_message_is_blocked_before_it_is_stored(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $this->withToken($tokenA)
            ->postJson("/api/v1/chat/{$b->name}/messages", ['text' => 'Я тебя убью'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');

        $this->assertSame(0, ChatMessage::count());
        $this->assertSame(1, $a->fresh()->strikes);
    }
}
