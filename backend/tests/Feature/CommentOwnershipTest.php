<?php

namespace Tests\Feature;

use App\Models\PostComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class CommentOwnershipTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_a_comment_is_owned_by_a_and_b_comment_is_owned_by_b(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $postId = $this->withToken($tokenA)
            ->postJson('/api/v1/feed', ['text' => 'gönderi'])
            ->assertOk()
            ->json('data.id');

        $this->withToken($tokenA)
            ->postJson("/api/v1/feed/{$postId}/comments", ['text' => 'Hello'])
            ->assertOk();
        $this->travel(1)->seconds();
        $this->withToken($tokenB)
            ->postJson("/api/v1/feed/{$postId}/comments", ['text' => 'Hi'])
            ->assertOk();

        $this->assertDatabaseHas('post_comments', [
            'post_id' => $postId,
            'user_id' => $a->id,
            'text' => 'Hello',
        ]);
        $this->assertDatabaseHas('post_comments', [
            'post_id' => $postId,
            'user_id' => $b->id,
            'text' => 'Hi',
        ]);
        $this->assertEquals(2, PostComment::where('post_id', $postId)->count());
        $this->assertEquals($a->id, PostComment::where('text', 'Hello')->value('user_id'));
        $this->assertEquals($b->id, PostComment::where('text', 'Hi')->value('user_id'));
    }
}
