<?php

namespace Tests\Feature;

use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlaces;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class ReviewIsolationTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers, CreatesPlaces;

    public function test_a_and_b_reviews_keep_separate_owners(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');
        [, $tokenC] = $this->signInChatUser('Kullanıcı C', 'c@arucad.edu.tr');
        $place = $this->seedPlace();

        $this->withToken($tokenA)->postJson("/api/v1/places/{$place->id}/reviews", [
            'rating' => 5, 'comment' => 'A yorumu',
        ])->assertOk();
        $this->travel(1)->seconds();
        $this->withToken($tokenB)->postJson("/api/v1/places/{$place->id}/reviews", [
            'rating' => 4, 'comment' => 'B yorumu',
        ])->assertOk();

        $rows = $this->withToken($tokenC)
            ->getJson("/api/v1/places/{$place->id}/reviews")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $rows);
        $byComment = collect($rows)->keyBy('comment');
        $this->assertEquals('Kullanıcı A', $byComment['A yorumu']['author']);
        $this->assertEquals('Kullanıcı B', $byComment['B yorumu']['author']);
        $this->assertNotContains('Kullanıcı C', array_column($rows, 'author'));
        $this->assertEquals($a->id, Review::where('comment', 'A yorumu')->value('user_id'));
        $this->assertEquals($b->id, Review::where('comment', 'B yorumu')->value('user_id'));
    }
}
