<?php

namespace Tests\Feature;

use App\Models\Notification as InboxNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FollowNotificationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: string} */
    private function signIn(string $name, string $email): array
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => 'GizliSifre1']);
        $token = $this->postJson('/api/v1/auth/session', [
            'email' => $email, 'password' => 'GizliSifre1',
        ])->assertOk()->json('data.token');

        return [$user, $token];
    }

    public function test_a_follow_notifies_b_and_not_a(): void
    {
        [$a, $tokenA] = $this->signIn('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signIn('Kullanıcı B', 'b@arucad.edu.tr');

        $this->withToken($tokenA)->postJson('/api/v1/social/follow', ['peer' => $b->name])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $b->id,
            'actor_user_id' => $a->id,
            'kind' => 'follow',
        ]);
        $this->assertEquals(0, InboxNotification::where('user_id', $a->id)->count());
        $this->assertEquals(1, InboxNotification::where('user_id', $b->id)->count());

        $inboxA = $this->withToken($tokenA)->getJson('/api/v1/notifications')->assertOk()->json('data');
        $inboxB = $this->withToken($tokenB)->getJson('/api/v1/notifications')->assertOk()->json('data');

        $this->assertCount(0, $inboxA);
        $this->assertCount(1, $inboxB);
        $this->assertEquals('follow', $inboxB[0]['kind']);
    }
}
