<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class ChatIsolationTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_c_does_not_see_a_and_b_s_conversation(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');
        [, $tokenC] = $this->signInChatUser('Kullanıcı C', 'c@arucad.edu.tr');

        $this->withToken($tokenA)->postJson("/api/v1/chat/{$b->name}/messages", ['text' => 'gizli'])->assertOk();

        $this->assertEquals(1, Conversation::count());
        $this->assertEquals(1, ChatMessage::count());

        $cSeesB = $this->withToken($tokenC)->getJson("/api/v1/chat/{$b->name}/messages")->assertOk()->json('data');
        $this->assertSame([], $cSeesB);

        $cSeesA = $this->withToken($tokenC)->getJson("/api/v1/chat/{$a->name}/messages")->assertOk()->json('data');
        $this->assertSame([], $cSeesA);

        $cThreads = $this->withToken($tokenC)->getJson('/api/v1/chat/threads')->assertOk()->json('data');
        $this->assertSame([], $cThreads);

        $aThreads = $this->withToken($tokenA)->getJson('/api/v1/chat/threads')->assertOk()->json('data');
        $bThreads = $this->withToken($tokenB)->getJson('/api/v1/chat/threads')->assertOk()->json('data');
        $this->assertEquals([[
            'name' => $b->name,
            'avatarUrl' => null,
            'muted' => false,
            'archived' => false,
            'restricted' => false,
        ]], $aThreads);
        $this->assertEquals([[
            'name' => $a->name,
            'avatarUrl' => null,
            'muted' => false,
            'archived' => false,
            'restricted' => false,
        ]], $bThreads);
    }

    public function test_threads_include_the_peers_real_avatar_url(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');
        $b->update(['avatar_url' => 'https://example.com/b.png']);

        $this->withToken($tokenB)->postJson("/api/v1/chat/{$a->name}/messages", ['text' => 'selam'])->assertOk();

        $aThreads = $this->withToken($tokenA)->getJson('/api/v1/chat/threads')->assertOk()->json('data');
        $this->assertEquals([[
            'name' => $b->name,
            'avatarUrl' => 'https://example.com/b.png',
            'muted' => false,
            'archived' => false,
            'restricted' => false,
        ]], $aThreads);
    }
}
