<?php

namespace Tests\Feature;

use App\Models\PostComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class CommentIsolationTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_b_sees_a_as_author_and_c_is_not_the_author(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');
        [$c, $tokenC] = $this->signInChatUser('Kullanıcı C', 'c@arucad.edu.tr');

        $postId = $this->withToken($tokenA)
            ->postJson('/api/v1/feed', ['text' => 'gönderi'])
            ->assertOk()
            ->json('data.id');

        $this->withToken($tokenA)
            ->postJson("/api/v1/feed/{$postId}/comments", ['text' => 'Hello'])
            ->assertOk();
        $this->travel(1)->seconds();
        $this->withToken($tokenB)
            ->postJson("/api/v1/feed/{$postId}/comments", ['text' => 'from B'])
            ->assertOk();

        $comments = $this->withToken($tokenB)->getJson('/api/v1/feed')->assertOk()->json('data.0.comments');
        $this->assertCount(2, $comments);
        $this->assertEquals('Kullanıcı A', $comments[0]['author']);
        $this->assertEquals('Hello', $comments[0]['text']);
        $this->assertEquals('Kullanıcı B', $comments[1]['author']);
        $this->assertEquals('from B', $comments[1]['text']);

        $asC = $this->withToken($tokenC)->getJson('/api/v1/feed')->assertOk()->json('data.0.comments');
        $this->assertEquals(['Kullanıcı A', 'Kullanıcı B'], array_column($asC, 'author'));
        $this->assertNotContains('Kullanıcı C', array_column($asC, 'author'));
        $this->assertEquals(0, PostComment::where('user_id', $c->id)->count());
        $this->assertEquals($a->id, PostComment::where('text', 'Hello')->value('user_id'));
        $this->assertEquals($b->id, PostComment::where('text', 'from B')->value('user_id'));
    }
}
