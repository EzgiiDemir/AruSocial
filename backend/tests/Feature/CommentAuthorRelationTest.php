<?php

namespace Tests\Feature;

use App\Models\PostComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class CommentAuthorRelationTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_author_comes_from_the_user_relation_not_a_stored_string(): void
    {
        $this->assertFalse(Schema::hasColumn('post_comments', 'author'));

        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        $postId = $this->withToken($tokenA)
            ->postJson('/api/v1/feed', ['text' => 'gönderi'])
            ->assertOk()
            ->json('data.id');

        $data = $this->withToken($tokenA)
            ->postJson("/api/v1/feed/{$postId}/comments", ['text' => 'Hello'])
            ->assertOk()
            ->json('data');

        $this->assertEquals('Kullanıcı A', $data['comments'][0]['author']);
        $this->assertDatabaseHas('post_comments', [
            'id' => $data['comments'][0]['id'],
            'user_id' => $a->id,
            'text' => 'Hello',
        ]);
        $this->assertNull(PostComment::first()->getAttribute('author'));
    }
}
