<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Notification as InboxNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class FcmFailureTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_fcm_failure_does_not_rollback_message_or_inbox(): void
    {
        $fcm = $this->fakeFcm();
        $fcm->throw = true;

        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');
        $this->withToken($tokenB)
            ->postJson('/api/v1/push-tokens', ['token' => 'b-phone', 'platform' => 'android'])
            ->assertOk();

        $this->withToken($tokenA)
            ->postJson("/api/v1/chat/{$b->name}/messages", ['text' => 'hala kaydet'])
            ->assertOk();

        $this->assertEquals(1, ChatMessage::count());
        $this->assertEquals(1, InboxNotification::where('user_id', $b->id)->count());
        $this->withToken($tokenB)
            ->getJson($this->chatMessagesUrl($a))
            ->assertOk()
            ->assertJsonPath('data.0.text', 'hala kaydet');
    }
}
