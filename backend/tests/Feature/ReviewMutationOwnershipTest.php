<?php

namespace Tests\Feature;

use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlaces;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class ReviewMutationOwnershipTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers, CreatesPlaces;

    public function test_b_cannot_edit_or_delete_a_review_there_is_no_mutation_route(): void
    {
        [, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');
        $place = $this->seedPlace();

        $reviewId = $this->withToken($tokenA)->postJson("/api/v1/places/{$place->id}/reviews", [
            'rating' => 5, 'comment' => 'dokunma',
        ])->assertOk()->json('data.id');

        $this->withToken($tokenB)->patchJson("/api/v1/places/{$place->id}/reviews/{$reviewId}", ['comment' => 'hack'])->assertNotFound();
        $this->withToken($tokenB)->putJson("/api/v1/places/{$place->id}/reviews/{$reviewId}", ['comment' => 'hack'])->assertNotFound();
        $this->withToken($tokenB)->deleteJson("/api/v1/places/{$place->id}/reviews/{$reviewId}")->assertNotFound();
        $this->withToken($tokenB)->postJson("/api/v1/places/{$place->id}/reviews/{$reviewId}/delete")->assertNotFound();

        $this->assertDatabaseHas('reviews', ['id' => $reviewId, 'comment' => 'dokunma']);
        $this->assertEquals(1, Review::count());
    }
}
