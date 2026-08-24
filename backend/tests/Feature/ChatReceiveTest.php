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
}
