<?php

namespace Tests\Feature;

use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlaces;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class ReviewOwnershipTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers, CreatesPlaces;

    public function test_a_created_review_is_owned_by_the_signed_in_user(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        $place = $this->seedPlace();

        $this->withToken($tokenA)->postJson("/api/v1/places/{$place->id}/reviews", [
            'rating' => 5,
            'comment' => 'harika',
            'author' => 'Sahte İsim',
        ])->assertOk();

        $this->assertDatabaseHas('reviews', [
            'place_id' => $place->id,
            'user_id' => $a->id,
            'comment' => 'harika',
            'rating' => 5,
        ]);
        $this->assertEquals(1, Review::count());
        $this->assertEquals($a->id, Review::value('user_id'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('reviews', 'author'));
    }
}
