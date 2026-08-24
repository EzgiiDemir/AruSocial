<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The whole point of post_likes, walked end to end with two real accounts
// and two real tokens: A's like must never change what B sees as their own
// like state, while the shared count moves for both of them.
//
// This is the failure the old feed_posts.liked_by_me column produced —
// one person tapping the heart made the post look liked to everybody.
class MultiUserLikeIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $name, string $email): string
    {
        User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('GizliSifre1')]);

        return $this->postJson('/api/v1/auth/session', [
            'email' => $email, 'password' => 'GizliSifre1',
        ])->assertOk()->json('data.token');
    }

    /** @return array{likes:int, likedByMe:bool} */
    private function feedAs(string $token, string $postId): array
    {
        $post = collect($this->withToken($token)->getJson('/api/v1/feed')->assertOk()->json('data'))
            ->firstWhere('id', $postId);

        return ['likes' => $post['likes'], 'likedByMe' => $post['likedByMe']];
    }

    public function test_two_accounts_hold_their_own_like_state_on_a_shared_post(): void
    {
        $tokenA = $this->signIn('Kullanıcı A', 'a@arucad.edu.tr');
        $tokenB = $this->signIn('Kullanıcı B', 'b@arucad.edu.tr');

        $postId = $this->withToken($tokenA)
            ->postJson('/api/v1/feed', ['text' => 'ortak gönderi'])
            ->assertOk()->json('data.id');

        // Nobody has liked it yet.
        $this->assertEquals(['likes' => 0, 'likedByMe' => false], $this->feedAs($tokenA, $postId));
        $this->assertEquals(['likes' => 0, 'likedByMe' => false], $this->feedAs($tokenB, $postId));

        // A likes it: A sees their own like, B sees the count but not a
        // like of their own.
        $this->withToken($tokenA)->postJson("/api/v1/feed/{$postId}/like")->assertOk();
        $this->assertEquals(['likes' => 1, 'likedByMe' => true], $this->feedAs($tokenA, $postId));
        $this->assertEquals(['likes' => 1, 'likedByMe' => false], $this->feedAs($tokenB, $postId));

        // B likes it too: now both see their own like, and the shared count
        // is 2 for both.
        $this->withToken($tokenB)->postJson("/api/v1/feed/{$postId}/like")->assertOk();
        $this->assertEquals(['likes' => 2, 'likedByMe' => true], $this->feedAs($tokenB, $postId));
        $this->assertEquals(['likes' => 2, 'likedByMe' => true], $this->feedAs($tokenA, $postId));

        // A takes theirs back. Only A's own like goes — B's survives, and
        // the count follows the rows.
        $this->withToken($tokenA)->postJson("/api/v1/feed/{$postId}/like")->assertOk();
        $this->assertEquals(['likes' => 1, 'likedByMe' => false], $this->feedAs($tokenA, $postId));
        $this->assertEquals(['likes' => 1, 'likedByMe' => true], $this->feedAs($tokenB, $postId));
    }

    public function test_the_like_endpoints_own_response_matches_what_the_feed_reports(): void
    {
        $tokenA = $this->signIn('Kullanıcı A', 'a@arucad.edu.tr');
        $tokenB = $this->signIn('Kullanıcı B', 'b@arucad.edu.tr');
        $postId = $this->withToken($tokenA)
            ->postJson('/api/v1/feed', ['text' => 'ortak gönderi'])
            ->assertOk()->json('data.id');

        // The client uses the toggle's own response, so it has to agree
        // with the feed rather than being a second opinion.
        $toggled = $this->withToken($tokenB)
            ->postJson("/api/v1/feed/{$postId}/like")->assertOk()->json('data');

        $this->assertEquals(
            ['likes' => $toggled['likes'], 'likedByMe' => $toggled['likedByMe']],
            $this->feedAs($tokenB, $postId),
        );
        $this->assertEquals(['likes' => 1, 'likedByMe' => false], $this->feedAs($tokenA, $postId));
    }

    public function test_a_signed_out_token_can_no_longer_read_the_feed(): void
    {
        $tokenA = $this->signIn('Kullanıcı A', 'a@arucad.edu.tr');
        $postId = $this->withToken($tokenA)
            ->postJson('/api/v1/feed', ['text' => 'ortak gönderi'])
            ->assertOk()->json('data.id');
        $this->withToken($tokenA)->postJson("/api/v1/feed/{$postId}/like")->assertOk();

        $this->withToken($tokenA)->postJson('/api/v1/auth/logout')->assertOk();

        $this->withToken($tokenA)->getJson('/api/v1/feed')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');

        // Signing out revokes the token, not the like they left behind.
        $this->assertDatabaseCount('post_likes', 1);
    }

    public function test_one_account_cannot_remove_another_accounts_like(): void
    {
        $tokenA = $this->signIn('Kullanıcı A', 'a@arucad.edu.tr');
        $tokenB = $this->signIn('Kullanıcı B', 'b@arucad.edu.tr');
        $postId = $this->withToken($tokenA)
            ->postJson('/api/v1/feed', ['text' => 'ortak gönderi'])
            ->assertOk()->json('data.id');
        $this->withToken($tokenA)->postJson("/api/v1/feed/{$postId}/like")->assertOk();

        // There is no way to address someone else's like: the only handle
        // the endpoint offers is the post, and it always resolves the row
        // through the caller's own identity. B toggling adds B's like
        // rather than clearing A's.
        $this->withToken($tokenB)->postJson("/api/v1/feed/{$postId}/like")->assertOk();

        $this->assertDatabaseHas('post_likes', [
            'post_id' => $postId,
            'user_id' => User::where('email', 'a@arucad.edu.tr')->value('id'),
        ]);
        $this->assertEquals(['likes' => 2, 'likedByMe' => true], $this->feedAs($tokenA, $postId));
    }
}
