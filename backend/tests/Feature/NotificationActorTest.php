<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class NotificationActorTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_follow_stores_actor_a_and_recipient_b(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $this->withToken($tokenA)->postJson('/api/v1/social/follow', ['peer' => $b->name])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $b->id,
            'actor_user_id' => $a->id,
            'kind' => 'follow',
        ]);

        $row = $this->withToken($tokenB)->getJson('/api/v1/notifications')->assertOk()->json('data.0');
        $this->assertEquals((string) $a->id, $row['actorUserId']);
        $this->assertEquals('follow', $row['kind']);
    }
}
