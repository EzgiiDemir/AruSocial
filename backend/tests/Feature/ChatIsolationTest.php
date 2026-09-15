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

        // Asserted field by field rather than as a whole array: the thread
        // payload gained a message preview, and pinning the exact shape
        // turned "the list grew a useful field" into a failing isolation
        // test. What this test is actually about is who appears in whose
        // list — so that is what it checks.
        $this->assertCount(1, $aThreads);
        $this->assertSame($b->name, $aThreads[0]['name']);
        $this->assertNull($aThreads[0]['avatarUrl']);
        $this->assertFalse($aThreads[0]['muted']);
        $this->assertFalse($aThreads[0]['archived']);
        $this->assertFalse($aThreads[0]['restricted']);
        // A sends, so A's own copy has nothing unread.
        $this->assertSame('gizli', $aThreads[0]['lastMessage']);
        $this->assertSame(0, $aThreads[0]['unreadCount']);

        $this->assertCount(1, $bThreads);
        $this->assertSame($a->name, $bThreads[0]['name']);
        $this->assertSame('gizli', $bThreads[0]['lastMessage']);
        $this->assertSame(1, $bThreads[0]['unreadCount']);
    }

    public function test_threads_include_the_peers_real_avatar_url(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');
        $b->update(['avatar_url' => 'https://example.com/b.png']);

        $this->withToken($tokenB)->postJson("/api/v1/chat/{$a->name}/messages", ['text' => 'selam'])->assertOk();

        $aThreads = $this->withToken($tokenA)->getJson('/api/v1/chat/threads')->assertOk()->json('data');
        $this->assertCount(1, $aThreads);
        $this->assertSame($b->name, $aThreads[0]['name']);
        $this->assertSame('https://example.com/b.png', $aThreads[0]['avatarUrl']);
    }
}
