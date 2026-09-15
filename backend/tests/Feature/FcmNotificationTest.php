<?php

namespace Tests\Feature;

use App\Models\Notification as InboxNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class FcmNotificationTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_notification_row_and_fcm_dispatch_on_follow(): void
    {
        $fcm = $this->fakeFcm();
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $this->withToken($tokenB)
            ->postJson('/api/v1/push-tokens', ['token' => 'b-phone', 'platform' => 'android'])
            ->assertOk();

        $this->withToken($tokenA)
            ->postJson('/api/v1/social/follow', ['peer' => $b->name])
            ->assertOk();

        $this->assertEquals(1, InboxNotification::where('user_id', $b->id)->where('kind', 'follow')->count());
        $this->assertCount(1, $fcm->sent);
        $this->assertEquals('b-phone', $fcm->sent[0]['token']);
        $this->assertEquals('follow', $fcm->sent[0]['data']['type']);
        $this->assertArrayHasKey('notificationId', $fcm->sent[0]['data']);
        $this->assertArrayNotHasKey('token', $fcm->sent[0]['data']);
    }
}
