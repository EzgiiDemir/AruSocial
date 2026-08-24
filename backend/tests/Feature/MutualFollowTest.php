<?php

namespace Tests\Feature;

use App\Models\SocialFollow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MutualFollowTest extends TestCase
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

    public function test_a_following_b_and_b_following_a_are_two_independent_rows(): void
    {
        [$a, $tokenA] = $this->signIn('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signIn('Kullanıcı B', 'b@arucad.edu.tr');

        $this->withToken($tokenA)->postJson('/api/v1/social/follow', ['peer' => $b->name])->assertOk();
        $this->withToken($tokenB)->postJson('/api/v1/social/follow', ['peer' => $a->name])->assertOk();

        $this->assertEquals(2, SocialFollow::count());
        $this->assertDatabaseHas('social_follows', [
            'follower_user_id' => $a->id,
            'followed_user_id' => $b->id,
        ]);
        $this->assertDatabaseHas('social_follows', [
            'follower_user_id' => $b->id,
            'followed_user_id' => $a->id,
        ]);

        $this->assertEquals([$b->name], $this->withToken($tokenA)->getJson('/api/v1/social/following')->json('data'));
        $this->assertEquals([$a->name], $this->withToken($tokenB)->getJson('/api/v1/social/following')->json('data'));
    }
}
