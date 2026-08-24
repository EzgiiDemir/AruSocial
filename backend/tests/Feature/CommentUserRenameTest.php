<?php

namespace Tests\Feature;

use App\Models\PostComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class CommentUserRenameTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_renaming_the_author_updates_the_display_name_not_user_id(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Eski Ad', 'a@arucad.edu.tr');
        [, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $postId = $this->withToken($tokenA)
            ->postJson('/api/v1/feed', ['text' => 'gönderi'])
            ->assertOk()
            ->json('data.id');

        $this->withToken($tokenA)
            ->postJson("/api/v1/feed/{$postId}/comments", ['text' => 'Hello'])
            ->assertOk();

        $commentId = PostComment::where('text', 'Hello')->value('id');
        $this->assertEquals($a->id, PostComment::where('id', $commentId)->value('user_id'));

        $a->update(['name' => 'Yeni Ad']);

        $this->assertEquals($a->id, PostComment::where('id', $commentId)->value('user_id'));

        $asB = $this->withToken($tokenB)->getJson('/api/v1/feed')->assertOk()->json('data.0.comments.0');
        $this->assertEquals('Yeni Ad', $asB['author']);
        $this->assertEquals('Hello', $asB['text']);
        $this->assertEquals($commentId, $asB['id']);
    }
}
