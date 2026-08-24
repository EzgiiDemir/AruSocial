<?php

namespace Tests\Feature;

use App\Models\Notification as InboxNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class NotificationIsolationTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_b_receives_a_events_and_c_sees_none_of_them(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');
        [, $tokenC] = $this->signInChatUser('Kullanıcı C', 'c@arucad.edu.tr');

        $postId = $this->withToken($tokenB)
            ->postJson('/api/v1/feed', ['text' => 'B gönderisi'])
            ->assertOk()
            ->json('data.id');

        $this->withToken($tokenA)->postJson('/api/v1/social/follow', ['peer' => $b->name])->assertOk();
        $this->withToken($tokenA)->postJson("/api/v1/feed/{$postId}/like")->assertOk();
        $this->withToken($tokenA)->postJson("/api/v1/feed/{$postId}/comments", ['text' => 'Hello'])->assertOk();
        $this->withToken($tokenA)->postJson("/api/v1/chat/{$b->name}/messages", ['text' => 'selam'])->assertOk();

        $this->assertEquals(0, InboxNotification::where('user_id', $a->id)->count());
        $this->assertEquals(4, InboxNotification::where('user_id', $b->id)->count());
        $this->assertEquals(0, InboxNotification::where('actor_user_id', '!=', $a->id)->where('user_id', $b->id)->count());

        $inboxA = $this->withToken($tokenA)->getJson('/api/v1/notifications')->assertOk()->json('data');
        $inboxB = $this->withToken($tokenB)->getJson('/api/v1/notifications')->assertOk()->json('data');
        $inboxC = $this->withToken($tokenC)->getJson('/api/v1/notifications')->assertOk()->json('data');

        $this->assertCount(0, $inboxA);
        $this->assertCount(0, $inboxC);
        $this->assertCount(4, $inboxB);
        $kinds = array_column($inboxB, 'kind');
        sort($kinds);
        $this->assertEquals(['comment', 'follow', 'like', 'message'], $kinds);
        foreach ($inboxB as $row) {
            $this->assertEquals((string) $a->id, $row['actorUserId']);
        }
    }
}
