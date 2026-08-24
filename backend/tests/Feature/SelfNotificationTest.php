<?php

namespace Tests\Feature;

use App\Models\Notification as InboxNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class SelfNotificationTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_liking_or_commenting_on_your_own_post_does_not_notify_you(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');

        $postId = $this->withToken($tokenA)
            ->postJson('/api/v1/feed', ['text' => 'kendi gönderim'])
            ->assertOk()
            ->json('data.id');

        $this->withToken($tokenA)->postJson("/api/v1/feed/{$postId}/like")->assertOk();
        $this->withToken($tokenA)
            ->postJson("/api/v1/feed/{$postId}/comments", ['text' => 'not to myself'])
            ->assertOk();

        $this->assertEquals(0, InboxNotification::where('user_id', $a->id)->count());
        $this->assertEquals(0, InboxNotification::count());
        $this->assertCount(0, $this->withToken($tokenA)->getJson('/api/v1/notifications')->json('data'));
    }
}
