<?php

namespace Tests\Feature;

use App\Models\Notification as InboxNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class LikeNotificationTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_a_like_notifies_b_and_not_a(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $postId = $this->withToken($tokenB)
            ->postJson('/api/v1/feed', ['text' => 'gönderi'])
            ->assertOk()
            ->json('data.id');

        $this->withToken($tokenA)->postJson("/api/v1/feed/{$postId}/like")->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $b->id,
            'actor_user_id' => $a->id,
            'kind' => 'like',
        ]);
        $this->assertEquals(0, InboxNotification::where('user_id', $a->id)->count());
        $this->assertEquals(1, InboxNotification::where('user_id', $b->id)->count());

        $inboxA = $this->withToken($tokenA)->getJson('/api/v1/notifications')->assertOk()->json('data');
        $inboxB = $this->withToken($tokenB)->getJson('/api/v1/notifications')->assertOk()->json('data');
        $this->assertCount(0, $inboxA);
        $this->assertEquals('like', $inboxB[0]['kind']);
        $this->assertEquals((string) $a->id, $inboxB[0]['actorUserId']);
    }

    public function test_unliking_does_not_create_a_second_notification(): void
    {
        [, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $postId = $this->withToken($tokenB)
            ->postJson('/api/v1/feed', ['text' => 'gönderi'])
            ->assertOk()
            ->json('data.id');

        $this->withToken($tokenA)->postJson("/api/v1/feed/{$postId}/like")->assertOk();
        $this->withToken($tokenA)->postJson("/api/v1/feed/{$postId}/like")->assertOk();

        $this->assertEquals(1, InboxNotification::where('user_id', $b->id)->where('kind', 'like')->count());
    }
}
