<?php

namespace Tests\Feature;

use App\Models\SocialFollow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RenameSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_renaming_the_followed_user_does_not_break_the_relation(): void
    {
        $a = $this->actingAsUser();
        $b = User::create(['name' => 'Eski Ad', 'email' => 'b@arucad.edu.tr', 'password' => 'x']);

        $this->postJson('/api/v1/social/follow', ['peer' => 'Eski Ad'])->assertOk();

        $b->update(['name' => 'Yeni Ad']);

        $this->assertDatabaseHas('social_follows', [
            'follower_user_id' => $a->id,
            'followed_user_id' => $b->id,
        ]);
        $this->assertEquals(1, SocialFollow::count());
        $this->assertEquals(['Yeni Ad'], $this->getJson('/api/v1/social/following')->json('data'));
        $this->assertFalse(Schema::hasColumn('social_follows', 'followed_name'));
    }
}
