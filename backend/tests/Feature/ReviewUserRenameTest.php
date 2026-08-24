<?php

namespace Tests\Feature;

use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlaces;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class ReviewUserRenameTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers, CreatesPlaces;

    public function test_renaming_the_author_updates_the_display_name_not_user_id(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Eski Ad', 'a@arucad.edu.tr');
        [, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');
        $place = $this->seedPlace();

        $this->withToken($tokenA)->postJson("/api/v1/places/{$place->id}/reviews", [
            'rating' => 5, 'comment' => 'beğendim',
        ])->assertOk();

        $reviewId = Review::where('comment', 'beğendim')->value('id');
        $this->assertEquals($a->id, Review::where('id', $reviewId)->value('user_id'));

        $a->update(['name' => 'Yeni Ad']);

        $this->assertEquals($a->id, Review::where('id', $reviewId)->value('user_id'));

        $asB = $this->withToken($tokenB)
            ->getJson("/api/v1/places/{$place->id}/reviews")
            ->assertOk()
            ->json('data.0');
        $this->assertEquals('Yeni Ad', $asB['author']);
        $this->assertEquals('beğendim', $asB['comment']);
        $this->assertEquals($reviewId, $asB['id']);
    }
}
