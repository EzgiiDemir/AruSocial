<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class FromMeTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_from_me_is_computed_per_viewer_not_stored(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $sent = $this->withToken($tokenA)
            ->postJson("/api/v1/chat/{$b->name}/messages", ['text' => 'benim'])
            ->assertOk()
            ->json('data');

        $this->assertTrue($sent['fromMe']);
        $this->assertEquals($a->id, ChatMessage::where('id', $sent['id'])->value('sender_id'));
        $this->assertFalse(Schema::hasColumn('messages', 'from_me'));
        $this->assertFalse(Schema::hasColumn('messages', 'peer_name'));

        $asA = $this->withToken($tokenA)->getJson("/api/v1/chat/{$b->name}/messages")->json('data.0');
        $asB = $this->withToken($tokenB)->getJson("/api/v1/chat/{$a->name}/messages")->json('data.0');

        $this->assertEquals($sent['id'], $asA['id']);
        $this->assertEquals($sent['id'], $asB['id']);
        $this->assertTrue($asA['fromMe']);
        $this->assertFalse($asB['fromMe']);
    }
}
