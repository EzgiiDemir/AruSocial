<?php

namespace Tests\Feature;

use App\Models\Notification as InboxNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class NotificationReadOwnershipTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_b_cannot_mark_a_notification_read(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $this->withToken($tokenB)->postJson('/api/v1/social/follow', ['peer' => $a->name])->assertOk();

        $notificationId = InboxNotification::where('user_id', $a->id)->value('id');
        $this->assertNotNull($notificationId);
        $this->assertNull(InboxNotification::where('id', $notificationId)->value('read_at'));

        $this->withToken($tokenB)
            ->postJson("/api/v1/notifications/{$notificationId}/read")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOTIFICATION_NOT_FOUND');

        $this->assertNull(InboxNotification::where('id', $notificationId)->value('read_at'));

        $this->withToken($tokenA)
            ->postJson("/api/v1/notifications/{$notificationId}/read")
            ->assertOk();

        $this->assertNotNull(InboxNotification::find($notificationId)?->read_at);

        $inbox = $this->withToken($tokenA)->getJson('/api/v1/notifications')->assertOk()->json('data');
        $this->assertTrue($inbox[0]['read']);
    }
}
