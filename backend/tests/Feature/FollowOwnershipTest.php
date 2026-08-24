<?php

namespace Tests\Feature;

use App\Models\SocialFollow;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FollowOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_stores_user_ids_not_names(): void
    {
        $this->assertTrue(Schema::hasColumn('social_follows', 'follower_user_id'));
        $this->assertTrue(Schema::hasColumn('social_follows', 'followed_user_id'));
        $this->assertFalse(Schema::hasColumn('social_follows', 'followed_name'));
    }

    public function test_following_creates_a_row_from_the_caller_to_the_target(): void
    {
        $a = $this->actingAsUser();
        $b = User::create(['name' => 'Kullanıcı B', 'email' => 'b@arucad.edu.tr', 'password' => 'x']);

        $this->postJson('/api/v1/social/follow', ['peer' => $b->name])
            ->assertOk()
            ->assertJsonPath('data.following', true);

        $this->assertDatabaseHas('social_follows', [
            'follower_user_id' => $a->id,
            'followed_user_id' => $b->id,
        ]);
        $this->assertEquals(1, SocialFollow::count());
        $this->assertEquals([$b->name], $this->getJson('/api/v1/social/following')->json('data'));
    }

    public function test_following_the_same_person_again_unfollows_instead_of_duplicating(): void
    {
        $a = $this->actingAsUser();
        $b = User::create(['name' => 'Kullanıcı B', 'email' => 'b@arucad.edu.tr', 'password' => 'x']);

        $this->postJson('/api/v1/social/follow', ['peer' => $b->name])->assertOk();
        $this->postJson('/api/v1/social/follow', ['peer' => $b->name])
            ->assertOk()
            ->assertJsonPath('data.following', false);

        $this->assertDatabaseMissing('social_follows', [
            'follower_user_id' => $a->id,
            'followed_user_id' => $b->id,
        ]);
        $this->assertEquals(0, SocialFollow::count());
    }

    public function test_the_database_itself_refuses_a_duplicate_follow(): void
    {
        $a = $this->actingAsUser();
        $b = User::create(['name' => 'Kullanıcı B', 'email' => 'b@arucad.edu.tr', 'password' => 'x']);
        SocialFollow::create(['follower_user_id' => $a->id, 'followed_user_id' => $b->id]);

        $this->expectException(QueryException::class);
        SocialFollow::create(['follower_user_id' => $a->id, 'followed_user_id' => $b->id]);
    }

    public function test_logout_rejects_follow_with_401(): void
    {
        $user = User::create(['name' => 'A', 'email' => 'a@arucad.edu.tr', 'password' => 'GizliSifre1']);
        $token = $this->postJson('/api/v1/auth/session', [
            'email' => $user->email, 'password' => 'GizliSifre1',
        ])->assertOk()->json('data.token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->withToken($token)->postJson('/api/v1/social/follow', ['peer' => 'B'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
    }
}
