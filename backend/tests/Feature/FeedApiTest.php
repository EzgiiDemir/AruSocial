<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Real bug fix (docs/EKSIKLER.md sosyal §1): FeedPost.liked_by_me used to
// be a single boolean column shared by every account — one real user
// liking a post made it show as "liked" for every other real account too.
// These tests prove likes are now genuinely per-user, and that a real
// second account gets a real notification when they like/comment on
// someone else's post.
class FeedApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_like_is_isolated_per_real_account(): void
    {
        $author = $this->actingAsAdmin(name: 'Author Person', email: 'author@arucad.edu.tr');
        $postId = $this->postJson('/api/v1/feed', ['text' => 'gerçek gönderi'])->json('data.id');

        $liker = User::create(['name' => 'Liker Person', 'email' => 'liker@arucad.edu.tr', 'password' => bcrypt('x')]);
        Sanctum::actingAs($liker);
        $afterLike = $this->postJson("/api/v1/feed/{$postId}/like")->json('data');
        $this->assertTrue($afterLike['likedByMe']);
        $this->assertEquals(1, $afterLike['likes']);

        // The author's own view of the same post must NOT show it as
        // liked by them — it was the second account that liked it.
        Sanctum::actingAs($author);
        $authorsView = $this->getJson('/api/v1/feed')->json('data');
        $post = collect($authorsView)->firstWhere('id', $postId);
        $this->assertFalse($post['likedByMe']);
        $this->assertEquals(1, $post['likes']);
    }

    public function test_liking_someone_elses_post_notifies_the_real_author(): void
    {
        $author = $this->actingAsAdmin(name: 'Author Person', email: 'author@arucad.edu.tr');
        $postId = $this->postJson('/api/v1/feed', ['text' => 'gerçek gönderi'])->json('data.id');

        $liker = User::create(['name' => 'Liker Person', 'email' => 'liker@arucad.edu.tr', 'password' => bcrypt('x')]);
        Sanctum::actingAs($liker);
        $this->postJson("/api/v1/feed/{$postId}/like")->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $author->id, 'kind' => 'like',
        ]);
    }

    public function test_liking_your_own_post_does_not_notify_yourself(): void
    {
        $this->actingAsAdmin();
        $postId = $this->postJson('/api/v1/feed', ['text' => 'kendi gönderim'])->json('data.id');

        $this->postJson("/api/v1/feed/{$postId}/like")->assertOk();

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_commenting_on_someone_elses_post_notifies_the_real_author(): void
    {
        $author = $this->actingAsAdmin(name: 'Author Person', email: 'author@arucad.edu.tr');
        $postId = $this->postJson('/api/v1/feed', ['text' => 'gerçek gönderi'])->json('data.id');

        $commenter = User::create(['name' => 'Commenter Person', 'email' => 'commenter@arucad.edu.tr', 'password' => bcrypt('x')]);
        Sanctum::actingAs($commenter);
        $this->postJson("/api/v1/feed/{$postId}/comments", ['text' => 'harika!'])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $author->id, 'kind' => 'comment',
        ]);
    }

    public function test_unliking_removes_the_like_for_that_account_only(): void
    {
        $this->actingAsAdmin(name: 'Author Person', email: 'author@arucad.edu.tr');
        $postId = $this->postJson('/api/v1/feed', ['text' => 'gerçek gönderi'])->json('data.id');

        $liker = User::create(['name' => 'Liker Person', 'email' => 'liker@arucad.edu.tr', 'password' => bcrypt('x')]);
        Sanctum::actingAs($liker);
        $this->postJson("/api/v1/feed/{$postId}/like")->assertOk();
        $afterUnlike = $this->postJson("/api/v1/feed/{$postId}/like")->json('data');

        $this->assertFalse($afterUnlike['likedByMe']);
        $this->assertEquals(0, $afterUnlike['likes']);
    }
}
