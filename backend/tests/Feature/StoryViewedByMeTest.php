<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

/**
 * Regression: the Flutter "seen" ring used to read only from a device-local
 * SharedPreferences cache, never from the server, so a story someone had
 * genuinely already viewed came back unseen on a fresh install/other device.
 * The server now reports a real `viewedByMe` per story from `story_views`.
 */
class StoryViewedByMeTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_a_story_starts_unseen_for_someone_other_than_its_author(): void
    {
        [, $tokenAuthor] = $this->signInChatUser('Yazar', 'author@arucad.edu.tr');
        $story = $this->withToken($tokenAuthor)->postJson('/api/v1/stories', [
            'text' => 'merhaba',
        ])->assertOk()->json('data');

        [, $tokenViewer] = $this->signInChatUser('İzleyici', 'viewer@arucad.edu.tr');
        $listed = $this->withToken($tokenViewer)->getJson('/api/v1/stories')->assertOk()->json('data');
        $mine = collect($listed)->firstWhere('id', $story['id']);

        $this->assertFalse($mine['viewedByMe']);
    }

    public function test_viewing_a_story_makes_it_seen_on_a_later_list_call(): void
    {
        [, $tokenAuthor] = $this->signInChatUser('Yazar', 'author2@arucad.edu.tr');
        $story = $this->withToken($tokenAuthor)->postJson('/api/v1/stories', [
            'text' => 'merhaba',
        ])->assertOk()->json('data');

        [, $tokenViewer] = $this->signInChatUser('İzleyici', 'viewer2@arucad.edu.tr');
        $this->withToken($tokenViewer)->postJson("/api/v1/stories/{$story['id']}/view")->assertOk();

        $listed = $this->withToken($tokenViewer)->getJson('/api/v1/stories')->assertOk()->json('data');
        $mine = collect($listed)->firstWhere('id', $story['id']);

        $this->assertTrue($mine['viewedByMe']);
    }

    public function test_an_authors_own_story_is_always_seen(): void
    {
        [, $token] = $this->signInChatUser('Yazar', 'author3@arucad.edu.tr');
        $story = $this->withToken($token)->postJson('/api/v1/stories', [
            'text' => 'kendi hikayem',
        ])->assertOk()->json('data');

        $this->assertTrue($story['viewedByMe']);

        $listed = $this->withToken($token)->getJson('/api/v1/stories')->assertOk()->json('data');
        $this->assertTrue(collect($listed)->firstWhere('id', $story['id'])['viewedByMe']);
    }
}
