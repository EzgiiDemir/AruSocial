<?php

namespace Tests\Feature;

use App\Models\SocialBlock;
use App\Models\SocialFollow;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BlockOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_stores_user_ids_not_names(): void
    {
        $this->assertTrue(Schema::hasColumn('social_blocks', 'blocker_user_id'));
        $this->assertTrue(Schema::hasColumn('social_blocks', 'blocked_user_id'));
        $this->assertFalse(Schema::hasColumn('social_blocks', 'blocked_name'));
    }

    public function test_blocking_creates_a_row_from_the_caller_to_the_target(): void
    {
        $a = $this->actingAsUser();
        $b = User::create(['name' => 'Kullanıcı B', 'email' => 'b@arucad.edu.tr', 'password' => 'x']);

        $this->postJson('/api/v1/social/block', ['peer' => $b->name])
            ->assertOk()
            ->assertJsonPath('data.blocked', true);

        $this->assertDatabaseHas('social_blocks', [
            'blocker_user_id' => $a->id,
            'blocked_user_id' => $b->id,
        ]);
        $this->assertEquals(1, SocialBlock::count());
        $this->assertEquals([$b->name], $this->getJson('/api/v1/social/blocked')->json('data'));
    }

    public function test_blocking_also_drops_the_caller_s_follow_of_that_person(): void
    {
        $a = $this->actingAsUser();
        $b = User::create(['name' => 'Kullanıcı B', 'email' => 'b@arucad.edu.tr', 'password' => 'x']);
        SocialFollow::create(['follower_user_id' => $a->id, 'followed_user_id' => $b->id]);

        $this->postJson('/api/v1/social/block', ['peer' => $b->name])->assertOk();

        $this->assertDatabaseMissing('social_follows', [
            'follower_user_id' => $a->id,
            'followed_user_id' => $b->id,
        ]);
    }

    public function test_blocking_the_same_person_again_unblocks_instead_of_duplicating(): void
    {
        $a = $this->actingAsUser();
        $b = User::create(['name' => 'Kullanıcı B', 'email' => 'b@arucad.edu.tr', 'password' => 'x']);

        $this->postJson('/api/v1/social/block', ['peer' => $b->name])->assertOk();
        $this->postJson('/api/v1/social/block', ['peer' => $b->name])
            ->assertOk()
            ->assertJsonPath('data.blocked', false);

        $this->assertDatabaseMissing('social_blocks', [
            'blocker_user_id' => $a->id,
            'blocked_user_id' => $b->id,
        ]);
        $this->assertEquals(0, SocialBlock::count());
    }

    public function test_the_database_itself_refuses_a_duplicate_block(): void
    {
        $a = $this->actingAsUser();
        $b = User::create(['name' => 'Kullanıcı B', 'email' => 'b@arucad.edu.tr', 'password' => 'x']);
        SocialBlock::create(['blocker_user_id' => $a->id, 'blocked_user_id' => $b->id]);

        $this->expectException(QueryException::class);
        SocialBlock::create(['blocker_user_id' => $a->id, 'blocked_user_id' => $b->id]);
    }

    public function test_a_block_does_not_write_a_row_for_anyone_else(): void
    {
        $a = $this->actingAsUser();
        $b = User::create(['name' => 'Kullanıcı B', 'email' => 'b@arucad.edu.tr', 'password' => 'x']);
        $c = User::create(['name' => 'Kullanıcı C', 'email' => 'c@arucad.edu.tr', 'password' => 'x']);

        $this->postJson('/api/v1/social/block', ['peer' => $b->name])->assertOk();

        $this->assertEquals(1, SocialBlock::count());
        $this->assertDatabaseMissing('social_blocks', ['blocker_user_id' => $b->id]);
        $this->assertDatabaseMissing('social_blocks', ['blocker_user_id' => $c->id]);
        $this->assertDatabaseMissing('social_blocks', ['blocked_user_id' => $a->id]);
        $this->assertDatabaseMissing('social_blocks', ['blocked_user_id' => $c->id]);
    }
}
