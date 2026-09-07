<?php

namespace Tests\Feature;

use App\Models\ChatThreadPref;
use App\Models\ModerationReport;
use App\Models\Notification as InboxNotification;
use App\Models\SocialFollow;
use App\Models\StoryView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class SocialGapsApiTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_chat_thread_prefs_toggle_and_appear_on_threads(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $this->withToken($tokenA)->postJson($this->chatMessagesUrl($b), ['text' => 'merhaba'])->assertOk();

        $this->withToken($tokenA)
            ->postJson('/api/v1/chat/prefs/toggle', ['peer' => $b->name, 'field' => 'mute'])
            ->assertOk()
            ->assertJsonPath('data.muted', true)
            ->assertJsonPath('data.archived', false);

        $prefs = $this->withToken($tokenA)->getJson('/api/v1/chat/prefs')->assertOk()->json('data');
        $this->assertCount(1, $prefs);
        $this->assertTrue($prefs[0]['muted']);

        $threads = $this->withToken($tokenA)->getJson('/api/v1/chat/threads')->assertOk()->json('data');
        $this->assertTrue(collect($threads)->firstWhere('name', $b->name)['muted']);
        $this->assertDatabaseHas('chat_thread_prefs', [
            'user_id' => $a->id,
            'peer_user_id' => $b->id,
        ]);
        $this->assertNotNull(ChatThreadPref::first()->muted_at);
    }

    public function test_report_user_creates_moderation_report(): void
    {
        [, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $this->withToken($tokenA)
            ->postJson('/api/v1/social/report-user', ['peer' => $b->name, 'reason' => 'spam'])
            ->assertOk()
            ->assertJsonPath('data.reported', true);

        $this->assertEquals(1, ModerationReport::where('kind', 'user')->where('target_id', (string) $b->id)->count());
    }

    public function test_followers_list_and_profile_counts(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        SocialFollow::create([
            'follower_user_id' => $b->id,
            'followed_user_id' => $a->id,
            'status' => 'accepted',
        ]);

        $this->assertEquals([$b->name], $this->withToken($tokenA)->getJson('/api/v1/social/followers')->assertOk()->json('data'));

        $profile = $this->withToken($tokenB)->getJson('/api/v1/social/users/'.$a->id)->assertOk()->json('data');
        $this->assertSame(1, $profile['followerCount']);
        $this->assertSame(0, $profile['followingCount']);
    }

    public function test_notification_data_persisted_for_like_and_follow(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $postId = $this->withToken($tokenB)
            ->postJson('/api/v1/feed', ['text' => 'gönderi'])
            ->assertOk()
            ->json('data.id');

        $this->withToken($tokenA)->postJson("/api/v1/feed/{$postId}/like")->assertOk();
        $likeNotif = InboxNotification::where('user_id', $b->id)->where('kind', 'like')->first();
        $this->assertSame($postId, $likeNotif->data['postId'] ?? null);

        $inbox = $this->withToken($tokenB)->getJson('/api/v1/notifications')->assertOk()->json('data');
        $this->assertSame($postId, $inbox[0]['data']['postId']);

        $this->withToken($tokenA)->postJson('/api/v1/social/follow', ['peer' => $b->name])->assertOk();
        $followNotif = InboxNotification::where('user_id', $b->id)->where('kind', 'follow')->first();
        $this->assertSame($a->name, $followNotif->data['actorName'] ?? null);
    }

    public function test_story_views_and_viewers(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $storyId = $this->withToken($tokenA)
            ->postJson('/api/v1/stories', ['text' => 'hello'])
            ->assertOk()
            ->json('data.id');

        $this->withToken($tokenB)->postJson("/api/v1/stories/{$storyId}/view")->assertOk();
        $this->assertEquals(1, StoryView::where('story_id', $storyId)->count());

        $viewers = $this->withToken($tokenA)->getJson("/api/v1/stories/{$storyId}/viewers")->assertOk()->json('data');
        $this->assertCount(1, $viewers);
        $this->assertSame('Kullanıcı B', $viewers[0]['name']);

        $list = $this->withToken($tokenA)->getJson('/api/v1/stories')->assertOk()->json('data');
        $this->assertSame(1, collect($list)->firstWhere('id', $storyId)['viewCount']);
    }

    public function test_group_chat_create_and_message_sync(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $group = $this->withToken($tokenA)
            ->postJson('/api/v1/chat/groups', ['name' => 'Kulüp', 'memberNames' => [$b->name]])
            ->assertCreated()
            ->json('data');

        $this->assertFalse($group['muted']);
        $this->assertFalse($group['archived']);

        $this->withToken($tokenA)
            ->postJson("/api/v1/chat/groups/{$group['id']}/messages", ['text' => 'selam grup'])
            ->assertOk();

        $msgs = $this->withToken($tokenB)
            ->getJson("/api/v1/chat/groups/{$group['id']}/messages")
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $msgs);
        $this->assertSame('selam grup', $msgs[0]['text']);

        $groups = $this->withToken($tokenB)->getJson('/api/v1/chat/groups')->assertOk()->json('data');
        $this->assertCount(1, $groups);
        $this->assertFalse($groups[0]['muted']);
        $this->assertFalse($groups[0]['archived']);
    }

    public function test_group_leave_mute_archive_and_report(): void
    {
        [, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $group = $this->withToken($tokenA)
            ->postJson('/api/v1/chat/groups', ['name' => 'Kulüp', 'memberNames' => [$b->name]])
            ->assertCreated()
            ->json('data');

        $this->withToken($tokenB)
            ->postJson("/api/v1/chat/groups/{$group['id']}/prefs/toggle", ['field' => 'mute'])
            ->assertOk()
            ->assertJsonPath('data.muted', true)
            ->assertJsonPath('data.archived', false);

        $this->withToken($tokenB)
            ->postJson("/api/v1/chat/groups/{$group['id']}/prefs/toggle", ['field' => 'archive'])
            ->assertOk()
            ->assertJsonPath('data.muted', true)
            ->assertJsonPath('data.archived', true);

        $listed = $this->withToken($tokenB)->getJson('/api/v1/chat/groups')->assertOk()->json('data.0');
        $this->assertTrue($listed['muted']);
        $this->assertTrue($listed['archived']);

        $this->withToken($tokenB)
            ->postJson("/api/v1/chat/groups/{$group['id']}/report", ['reason' => 'spam'])
            ->assertOk()
            ->assertJsonPath('data.reported', true);
        $this->assertEquals(1, ModerationReport::where('kind', 'chat_group')->where('target_id', $group['id'])->count());

        $this->withToken($tokenB)
            ->postJson("/api/v1/chat/groups/{$group['id']}/leave")
            ->assertOk()
            ->assertJsonPath('data.left', true);

        $this->withToken($tokenB)->getJson('/api/v1/chat/groups')->assertOk()->assertJsonPath('data', []);
        $this->withToken($tokenB)
            ->postJson("/api/v1/chat/groups/{$group['id']}/leave")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'GROUP_NOT_FOUND');
    }

    public function test_leaderboard_includes_avatar_url(): void
    {
        [, $token] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        $row = $this->withToken($token)->getJson('/api/v1/leaderboard')->assertOk()->json('data.0');
        $this->assertArrayHasKey('avatarUrl', $row);
    }
}
