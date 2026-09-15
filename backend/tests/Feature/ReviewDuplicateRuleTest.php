<?php

namespace Tests\Feature;

use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesPlaces;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class ReviewDuplicateRuleTest extends TestCase
{
    use CreatesPlaces, RefreshDatabase, SignsInChatUsers;

    public function test_the_product_allows_the_same_user_to_review_a_place_more_than_once(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        $place = $this->seedPlace();

        $this->withToken($tokenA)->postJson("/api/v1/places/{$place->id}/reviews", [
            'rating' => 5, 'comment' => 'ilk',
        ])->assertOk();
        $this->travel(1)->seconds();
        $this->withToken($tokenA)->postJson("/api/v1/places/{$place->id}/reviews", [
            'rating' => 3, 'comment' => 'ikinci',
        ])->assertOk();

        $this->assertEquals(2, Review::where('place_id', $place->id)->where('user_id', $a->id)->count());
        $this->assertFalse(Schema::hasColumn('reviews', 'author'));
        $indexes = collect(Schema::getIndexes('reviews'))->pluck('columns');
        $this->assertFalse($indexes->contains(fn ($cols) => $cols === ['user_id', 'place_id'] || $cols === ['place_id', 'user_id']));
    }
}
