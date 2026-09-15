<?php

namespace Tests\Feature;

use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesPlaces;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class ReviewAuthorRelationTest extends TestCase
{
    use CreatesPlaces, RefreshDatabase, SignsInChatUsers;

    public function test_author_comes_from_the_user_relation_not_a_stored_string(): void
    {
        $this->assertFalse(Schema::hasColumn('reviews', 'author'));

        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        $place = $this->seedPlace();

        $data = $this->withToken($tokenA)->postJson("/api/v1/places/{$place->id}/reviews", [
            'rating' => 5, 'comment' => 'güzel',
        ])->assertOk()->json('data');

        $this->assertEquals('Kullanıcı A', $data['author']);
        $this->assertDatabaseHas('reviews', [
            'id' => $data['id'],
            'user_id' => $a->id,
            'comment' => 'güzel',
        ]);
        $this->assertNull(Review::first()->getAttribute('author'));
    }
}
