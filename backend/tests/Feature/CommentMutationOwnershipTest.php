<?php

namespace Tests\Feature;

use App\Models\PostComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class CommentMutationOwnershipTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_b_cannot_edit_or_delete_a_comment_there_is_no_mutation_route(): void
    {
        [, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $postId = $this->withToken($tokenA)
            ->postJson('/api/v1/feed', ['text' => 'gönderi'])
            ->assertOk()
            ->json('data.id');

        $commentId = $this->withToken($tokenA)
            ->postJson("/api/v1/feed/{$postId}/comments", ['text' => 'dokunma'])
            ->assertOk()
            ->json('data.comments.0.id');

        $this->withToken($tokenB)->patchJson("/api/v1/feed/{$postId}/comments/{$commentId}", ['text' => 'hack'])->assertNotFound();
        $this->withToken($tokenB)->putJson("/api/v1/feed/{$postId}/comments/{$commentId}", ['text' => 'hack'])->assertNotFound();
        $this->withToken($tokenB)->deleteJson("/api/v1/feed/{$postId}/comments/{$commentId}")->assertNotFound();
        $this->withToken($tokenB)->postJson("/api/v1/feed/{$postId}/comments/{$commentId}/delete")->assertNotFound();

        $this->assertDatabaseHas('post_comments', ['id' => $commentId, 'text' => 'dokunma']);
        $this->assertEquals(1, PostComment::count());
    }
}
