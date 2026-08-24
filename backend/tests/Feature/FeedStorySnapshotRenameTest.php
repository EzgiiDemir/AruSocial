<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class FeedStorySnapshotRenameTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_renaming_the_author_leaves_post_and_story_snapshots_unchanged(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Eski Ad', 'a@arucad.edu.tr');
        [, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $post = $this->withToken($tokenA)->postJson('/api/v1/feed', [
            'text' => 'eski gönderi',
        ])->assertOk()->json('data');
        $story = $this->withToken($tokenA)->postJson('/api/v1/stories', [
            'text' => 'eski hikaye',
        ])->assertOk()->json('data');

        $this->assertSame($a->id, (int) FeedPost::where('id', $post['id'])->value('author_id'));
        $this->assertSame($a->id, (int) Story::where('id', $story['id'])->value('author_id'));

        $a->update(['name' => 'Yeni Ad']);

        $this->assertSame($a->id, (int) FeedPost::where('id', $post['id'])->value('author_id'));
        $this->assertSame($a->id, (int) Story::where('id', $story['id'])->value('author_id'));
        $this->assertSame('Eski Ad', FeedPost::where('id', $post['id'])->value('name'));
        $this->assertSame('Eski Ad', Story::where('id', $story['id'])->value('author_name'));

        $feed = $this->withToken($tokenB)->getJson('/api/v1/feed')->assertOk()->json('data.0');
        $this->assertSame('Eski Ad', $feed['name']);
        $this->assertSame((string) $a->id, $feed['authorId']);

        $stories = $this->withToken($tokenB)->getJson('/api/v1/stories')->assertOk()->json('data.0');
        $this->assertSame('Eski Ad', $stories['authorName']);
        $this->assertSame((string) $a->id, $stories['authorId']);
    }
}
