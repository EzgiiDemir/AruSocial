<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class MutualChatTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_a_to_b_and_b_to_a_share_one_conversation(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $this->withToken($tokenA)->postJson("/api/v1/chat/{$b->name}/messages", ['text' => 'Merhaba B'])->assertOk();
        $this->travel(1)->seconds();
        $this->withToken($tokenB)->postJson("/api/v1/chat/{$a->name}/messages", ['text' => 'Merhaba A'])->assertOk();

        $this->assertEquals(1, Conversation::count());
        $this->assertEquals(2, ChatMessage::count());
        $this->assertEquals(
            Conversation::pairKey($a->id, $b->id),
            Conversation::value('pair_key'),
        );

        $asA = $this->withToken($tokenA)->getJson("/api/v1/chat/{$b->name}/messages")->json('data');
        $asB = $this->withToken($tokenB)->getJson("/api/v1/chat/{$a->name}/messages")->json('data');
        $this->assertCount(2, $asA);
        $this->assertEquals(array_column($asA, 'id'), array_column($asB, 'id'));
        $this->assertEquals(['Merhaba B', 'Merhaba A'], array_column($asA, 'text'));
    }
}
