<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedPostOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_student_can_edit_their_own_post(): void
    {
        $me = $this->actingAsUser();
        $post = FeedPost::create([
            'id' => 'post-1', 'author_id' => $me->id, 'name' => $me->name,
            'text' => 'original', 'meta' => 'now', 'created_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/feed/{$post->id}", ['text' => 'edited']);

        $response->assertOk();
        $this->assertSame('edited', $response->json('data.text'));
        $this->assertDatabaseHas('feed_posts', ['id' => 'post-1', 'text' => 'edited']);
    }

    public function test_a_student_cannot_edit_someone_elses_post(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@arucad.edu.tr', 'password' => bcrypt('x')]);
        FeedPost::create([
            'id' => 'post-1', 'author_id' => $owner->id, 'name' => $owner->name,
            'text' => 'original', 'meta' => 'now', 'created_at' => now(),
        ]);
        $this->actingAsUser();

        $response = $this->postJson('/api/v1/feed/post-1', ['text' => 'hijacked']);

        $response->assertStatus(404);
        $this->assertDatabaseHas('feed_posts', ['id' => 'post-1', 'text' => 'original']);
    }

    public function test_a_student_can_delete_their_own_post(): void
    {
        $me = $this->actingAsUser();
        FeedPost::create([
            'id' => 'post-1', 'author_id' => $me->id, 'name' => $me->name,
            'text' => 'hi', 'meta' => 'now', 'created_at' => now(),
        ]);

        $this->postJson('/api/v1/feed/post-1/delete')->assertOk();

        $this->assertDatabaseMissing('feed_posts', ['id' => 'post-1']);
    }

    public function test_a_student_cannot_delete_someone_elses_post(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@arucad.edu.tr', 'password' => bcrypt('x')]);
        FeedPost::create([
            'id' => 'post-1', 'author_id' => $owner->id, 'name' => $owner->name,
            'text' => 'hi', 'meta' => 'now', 'created_at' => now(),
        ]);
        $this->actingAsUser();

        $this->postJson('/api/v1/feed/post-1/delete')->assertStatus(404);

        $this->assertDatabaseHas('feed_posts', ['id' => 'post-1']);
    }

    public function test_a_blocked_edit_is_rejected_like_a_new_post_would_be(): void
    {
        $me = $this->actingAsUser();
        FeedPost::create([
            'id' => 'post-1', 'author_id' => $me->id, 'name' => $me->name,
            'text' => 'original', 'meta' => 'now', 'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/feed/post-1', ['text' => 'sen bir salaksın']);

        $response->assertStatus(400)->assertJsonPath('error.code', 'CONTENT_BLOCKED');
    }

    public function test_a_student_can_delete_their_own_story_but_not_someone_elses(): void
    {
        $me = $this->actingAsUser();
        $mine = Story::create([
            'id' => 'story-1', 'author_id' => $me->id, 'author_name' => $me->name,
            'text' => 'hi', 'created_at' => now(),
        ]);
        $other = User::create(['name' => 'Other', 'email' => 'other@arucad.edu.tr', 'password' => bcrypt('x')]);
        Story::create([
            'id' => 'story-2', 'author_id' => $other->id, 'author_name' => $other->name,
            'text' => 'hi', 'created_at' => now(),
        ]);

        $this->postJson("/api/v1/stories/{$mine->id}/delete")->assertOk();
        $this->assertDatabaseMissing('stories', ['id' => 'story-1']);

        $this->postJson('/api/v1/stories/story-2/delete')->assertStatus(404);
        $this->assertDatabaseHas('stories', ['id' => 'story-2']);
    }

    public function test_stories_now_round_trip_their_background_color(): void
    {
        $this->actingAsUser();
        $this->postJson('/api/v1/stories', ['backgroundColorValue' => 4278190080])->assertOk();

        $response = $this->getJson('/api/v1/stories');

        $response->assertOk();
        $this->assertSame(4278190080, $response->json('data.0.backgroundColorValue'));
    }
}
