<?php

namespace Tests\Feature;

use App\Models\SocialBlock;
use App\Models\SocialFollow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SelfRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_follow_is_rejected(): void
    {
        $me = $this->actingAsUser();

        $this->postJson('/api/v1/social/follow', ['peer' => $me->name])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION');

        $this->postJson('/api/v1/social/follow', ['peerId' => $me->id])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION');

        $this->assertEquals(0, SocialFollow::count());
    }

    public function test_self_block_is_rejected(): void
    {
        $me = $this->actingAsUser();

        $this->postJson('/api/v1/social/block', ['peer' => $me->name])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION');

        $this->assertEquals(0, SocialBlock::count());
    }
}
