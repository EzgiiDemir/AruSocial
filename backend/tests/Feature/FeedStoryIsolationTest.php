<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class FeedStoryIsolationTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_a_and_b_posts_and_stories_keep_separate_owners(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');
        [, $tokenC] = $this->signInChatUser('Kullanıcı C', 'c@arucad.edu.tr');

        $this->withToken($tokenA)->postJson('/api/v1/feed', ['text' => 'A gönderisi'])->assertOk();
        $this->travel(1)->seconds();
        $this->withToken($tokenB)->postJson('/api/v1/feed', ['text' => 'B gönderisi'])->assertOk();
        $this->withToken($tokenA)->postJson('/api/v1/stories', ['text' => 'A hikayesi'])->assertOk();
        $this->travel(1)->seconds();
        $this->withToken($tokenB)->postJson('/api/v1/stories', ['text' => 'B hikayesi'])->assertOk();

        $posts = $this->withToken($tokenC)->getJson('/api/v1/feed')->assertOk()->json('data');
        $byText = collect($posts)->keyBy('text');
        $this->assertSame('Kullanıcı A', $byText['A gönderisi']['name']);
        $this->assertSame('Kullanıcı B', $byText['B gönderisi']['name']);
        $this->assertSame((string) $a->id, $byText['A gönderisi']['authorId']);
        $this->assertSame((string) $b->id, $byText['B gönderisi']['authorId']);
        $this->assertNotContains('Kullanıcı C', array_column($posts, 'name'));

        $stories = $this->withToken($tokenC)->getJson('/api/v1/stories')->assertOk()->json('data');
        $storyByText = collect($stories)->keyBy('text');
        $this->assertSame('Kullanıcı A', $storyByText['A hikayesi']['authorName']);
        $this->assertSame('Kullanıcı B', $storyByText['B hikayesi']['authorName']);

        $this->assertEquals($a->id, FeedPost::where('text', 'A gönderisi')->value('author_id'));
        $this->assertEquals($b->id, FeedPost::where('text', 'B gönderisi')->value('author_id'));
        $this->assertEquals($a->id, Story::where('text', 'A hikayesi')->value('author_id'));
        $this->assertEquals($b->id, Story::where('text', 'B hikayesi')->value('author_id'));
    }
}
