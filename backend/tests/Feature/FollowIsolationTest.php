<?php

namespace Tests\Feature;

use App\Models\SocialFollow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FollowIsolationTest extends TestCase
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

    public function test_a_follow_is_visible_to_a_and_b_but_does_not_touch_c(): void
    {
        [$a, $tokenA] = $this->signIn('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signIn('Kullanıcı B', 'b@arucad.edu.tr');
        [, $tokenC] = $this->signIn('Kullanıcı C', 'c@arucad.edu.tr');

        $this->withToken($tokenA)->postJson('/api/v1/social/follow', ['peer' => $b->name])
            ->assertOk()
            ->assertJsonPath('data.following', true);

        $this->assertDatabaseHas('social_follows', [
            'follower_user_id' => $a->id,
            'followed_user_id' => $b->id,
        ]);
        $this->assertEquals(1, SocialFollow::count());
        $this->assertEquals($a->id, SocialFollow::where('followed_user_id', $b->id)->value('follower_user_id'));

        $this->assertEquals([$b->name], $this->withToken($tokenA)->getJson('/api/v1/social/following')->json('data'));
        $this->assertEquals([], $this->withToken($tokenB)->getJson('/api/v1/social/following')->json('data'));
        $this->assertEquals([], $this->withToken($tokenC)->getJson('/api/v1/social/following')->json('data'));
    }
}
