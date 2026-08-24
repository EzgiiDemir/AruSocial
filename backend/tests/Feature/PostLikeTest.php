<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\PostLike;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

// A like is a row in post_likes, and nothing else: no boolean on the post,
// no counter to drift out of step with it.
class PostLikeTest extends TestCase
{
    use RefreshDatabase;

    private function seedPost(string $id = 'post-1'): FeedPost
    {
        $author = User::first() ?? User::create([
            'name' => 'Yazar',
            'email' => 'yazar@arucad.edu.tr',
            'password' => bcrypt('x'),
        ]);

        return FeedPost::create([
            'id' => $id, 'author_id' => $author->id, 'name' => $author->name, 'text' => 'gönderi',
            'meta' => 'az önce', 'created_at' => now(),
        ]);
    }

    public function test_liking_creates_a_row_owned_by_the_signed_in_account(): void
    {
        $me = $this->actingAsUser();
        $post = $this->seedPost();

        $data = $this->postJson("/api/v1/feed/{$post->id}/like")->assertOk()->json('data');

        $this->assertTrue($data['likedByMe']);
        $this->assertEquals(1, $data['likes']);
        $this->assertDatabaseHas('post_likes', ['post_id' => $post->id, 'user_id' => $me->id]);
    }

    public function test_liking_again_removes_the_row(): void
    {
        $me = $this->actingAsUser();
        $post = $this->seedPost();

        $this->postJson("/api/v1/feed/{$post->id}/like")->assertOk();
        $data = $this->postJson("/api/v1/feed/{$post->id}/like")->assertOk()->json('data');

        $this->assertFalse($data['likedByMe']);
        $this->assertEquals(0, $data['likes']);
        $this->assertDatabaseMissing('post_likes', ['post_id' => $post->id, 'user_id' => $me->id]);
    }

    public function test_repeated_taps_never_accumulate_rows(): void
    {
        $me = $this->actingAsUser();
        $post = $this->seedPost();

        foreach (range(1, 5) as $_) {
            $this->postJson("/api/v1/feed/{$post->id}/like")->assertOk();
        }

        // Five taps: like, unlike, like, unlike, like — one row, not five.
        $this->assertEquals(1, PostLike::where('post_id', $post->id)->count());
        $this->assertEquals(1, $this->getJson('/api/v1/feed')->json('data.0.likes'));
        $this->assertTrue((bool) $post->fresh()->likedBy($me));
    }

    // The guarantee lives in the schema, not in the controller remembering
    // to check first — so a direct second insert has to fail too.
    public function test_the_database_itself_refuses_a_duplicate_like(): void
    {
        $me = $this->actingAsUser();
        $post = $this->seedPost();
        PostLike::create([
            'id' => 'like-'.Str::uuid(), 'post_id' => $post->id,
            'user_id' => $me->id, 'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        PostLike::create([
            'id' => 'like-'.Str::uuid(), 'post_id' => $post->id,
            'user_id' => $me->id, 'created_at' => now(),
        ]);
    }

    public function test_the_count_is_the_number_of_people_not_the_number_of_taps(): void
    {
        $post = $this->seedPost();

        foreach (['a', 'b', 'c'] as $name) {
            $user = User::create([
                'name' => $name, 'email' => "$name@arucad.edu.tr", 'password' => bcrypt('x'),
            ]);
            $this->actingAsUser($user);
            $this->postJson("/api/v1/feed/{$post->id}/like")->assertOk();
        }

        $this->assertEquals(3, $this->getJson('/api/v1/feed')->json('data.0.likes'));
    }

    public function test_deleting_a_post_takes_its_likes_with_it(): void
    {
        $this->actingAsUser();
        $post = $this->seedPost();
        $this->postJson("/api/v1/feed/{$post->id}/like")->assertOk();

        $post->delete();

        $this->assertDatabaseCount('post_likes', 0);
    }

    public function test_liking_a_post_that_does_not_exist_is_a_404(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/feed/yok/like')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'POST_NOT_FOUND');
    }

    public function test_liking_requires_a_signed_in_account(): void
    {
        $this->seedPost();

        $this->postJson('/api/v1/feed/post-1/like')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
        $this->assertDatabaseCount('post_likes', 0);
    }

    public function test_the_feed_no_longer_carries_a_like_column(): void
    {
        $this->assertFalse(\Schema::hasColumn('feed_posts', 'liked_by_me'));
        $this->assertFalse(\Schema::hasColumn('feed_posts', 'likes'));
    }
}
