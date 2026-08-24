<?php

namespace Tests\Feature;

use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class ChatConversationTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_a_plus_b_is_a_single_conversation_even_when_opened_twice(): void
    {
        [, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $this->withToken($tokenA)->postJson("/api/v1/chat/{$b->name}/messages", ['text' => 'bir'])->assertOk();
        $this->withToken($tokenA)->postJson("/api/v1/chat/{$b->name}/messages", ['text' => 'iki'])->assertOk();

        $this->assertEquals(1, Conversation::count());
        $thread = $this->withToken($tokenA)->getJson("/api/v1/chat/{$b->name}/messages")->assertOk()->json('data');
        $this->assertCount(2, $thread);
    }

    public function test_renaming_b_does_not_split_the_conversation(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Eski Ad', 'b@arucad.edu.tr');

        $this->withToken($tokenA)->postJson('/api/v1/chat/Eski Ad/messages', ['text' => 'kalıcı'])->assertOk();
        $conversationId = Conversation::where('pair_key', Conversation::pairKey($a->id, $b->id))->value('id');

        $b->update(['name' => 'Yeni Ad']);

        $this->assertEquals(1, Conversation::count());
        $this->assertEquals($conversationId, Conversation::where('pair_key', Conversation::pairKey($a->id, $b->id))->value('id'));

        $asA = $this->withToken($tokenA)->getJson('/api/v1/chat/Yeni Ad/messages')->assertOk()->json('data');
        $this->assertCount(1, $asA);
        $this->assertEquals('kalıcı', $asA[0]['text']);

        $asB = $this->withToken($tokenB)->getJson("/api/v1/chat/{$a->name}/messages")->assertOk()->json('data');
        $this->assertCount(1, $asB);
        $this->assertEquals('kalıcı', $asB[0]['text']);
    }
}
