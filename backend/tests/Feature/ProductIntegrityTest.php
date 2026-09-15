<?php

namespace Tests\Feature;

use App\Models\Checkin;
use App\Models\Club;
use App\Models\Event;
use App\Models\FeedPost;
use App\Models\Place;
use App\Models\SocialBlock;
use App\Models\SocialFollow;
use App\Models\StaffProfile;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlaces;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class ProductIntegrityTest extends TestCase
{
    use CreatesPlaces, RefreshDatabase, SignsInChatUsers;

    public function test_a_student_cannot_set_a_place_cover(): void
    {
        $this->actingAsUser();
        $place = $this->seedPlace('p-cover');

        $this->postJson("/api/v1/places/{$place->id}/cover", [
            'url' => 'https://cdn.example/cover.jpg',
        ])->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertNull($place->fresh()->cover_url);
    }

    public function test_only_me_posts_and_stories_are_hidden_from_peers(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $postId = $this->withToken($tokenA)->postJson('/api/v1/feed', [
            'text' => 'gizli not',
            'visibility' => 'onlyMe',
        ])->assertOk()->json('data.id');

        $this->withToken($tokenA)->postJson('/api/v1/stories', [
            'text' => 'gizli hikaye',
            'visibility' => 'onlyMe',
        ])->assertOk();

        $peerFeed = collect($this->withToken($tokenB)->getJson('/api/v1/feed')->json('data'))
            ->pluck('id');
        $this->assertFalse($peerFeed->contains($postId));

        $ownFeed = collect($this->withToken($tokenA)->getJson('/api/v1/feed')->json('data'))
            ->pluck('id');
        $this->assertTrue($ownFeed->contains($postId));

        $peerStories = collect($this->withToken($tokenB)->getJson('/api/v1/stories')->json('data'))
            ->pluck('text');
        $this->assertFalse($peerStories->contains('gizli hikaye'));

        $this->withToken($tokenB)->postJson("/api/v1/feed/{$postId}/like")
            ->assertStatus(404)->assertJsonPath('error.code', 'POST_NOT_FOUND');

        $this->assertEquals('onlyMe', FeedPost::find($postId)->visibility);
        $this->assertEquals('onlyMe', Story::where('text', 'gizli hikaye')->value('visibility'));
        $this->assertEquals($a->id, FeedPost::find($postId)->author_id);
    }

    public function test_friends_visibility_is_mutual_follow_only(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');
        [$c, $tokenC] = $this->signInChatUser('Kullanıcı C', 'c@arucad.edu.tr');

        SocialFollow::create(['follower_user_id' => $a->id, 'followed_user_id' => $b->id]);
        SocialFollow::create(['follower_user_id' => $b->id, 'followed_user_id' => $a->id]);
        SocialFollow::create(['follower_user_id' => $c->id, 'followed_user_id' => $a->id]);

        $postId = $this->withToken($tokenA)->postJson('/api/v1/feed', [
            'text' => 'sadece arkadaşlar',
            'visibility' => 'friends',
        ])->assertOk()->assertJsonPath('data.visibility', 'friends')->json('data.id');

        $this->withToken($tokenA)->postJson('/api/v1/stories', [
            'text' => 'arkadaş hikaye',
            'visibility' => 'friends',
        ])->assertOk();

        $friendFeed = collect($this->withToken($tokenB)->getJson('/api/v1/feed')->json('data'))->pluck('id');
        $this->assertTrue($friendFeed->contains($postId));

        $strangerFeed = collect($this->withToken($tokenC)->getJson('/api/v1/feed')->json('data'))->pluck('id');
        $this->assertFalse($strangerFeed->contains($postId));

        $friendStories = collect($this->withToken($tokenB)->getJson('/api/v1/stories')->json('data'))->pluck('text');
        $this->assertTrue($friendStories->contains('arkadaş hikaye'));
        $strangerStories = collect($this->withToken($tokenC)->getJson('/api/v1/stories')->json('data'))->pluck('text');
        $this->assertFalse($strangerStories->contains('arkadaş hikaye'));
    }

    public function test_students_cannot_list_or_join_unpublished_events(): void
    {
        $this->actingAsUser();
        Event::create([
            'id' => 'e-live', 'title' => 'Live', 'time' => '10:00',
            'place_name' => 'X', 'category' => 'C', 'draft' => false, 'workflow_status' => 'published',
        ]);
        Event::create([
            'id' => 'e-draft', 'title' => 'Secret', 'time' => '10:00',
            'place_name' => 'X', 'category' => 'C', 'draft' => true, 'workflow_status' => 'draft',
        ]);

        $ids = collect($this->getJson('/api/v1/events?includeUnpublished=true')->json('data'))->pluck('id');
        $this->assertTrue($ids->contains('e-live'));
        $this->assertFalse($ids->contains('e-draft'));

        $this->getJson('/api/v1/events/e-draft')
            ->assertStatus(404)->assertJsonPath('error.code', 'EVENT_NOT_FOUND');
        $this->postJson('/api/v1/events/e-draft/join')
            ->assertStatus(404)->assertJsonPath('error.code', 'EVENT_NOT_FOUND');
    }

    public function test_an_admin_can_list_unpublished_events(): void
    {
        $this->actingAsRole();
        Event::create([
            'id' => 'e-draft', 'title' => 'Secret', 'time' => '10:00',
            'place_name' => 'X', 'category' => 'C', 'draft' => true, 'workflow_status' => 'draft',
        ]);

        $ids = collect($this->getJson('/api/v1/events?includeUnpublished=true')->json('data'))->pluck('id');
        $this->assertTrue($ids->contains('e-draft'));
        $this->getJson('/api/v1/events/e-draft')->assertOk()->assertJsonPath('data.title', 'Secret');
    }

    public function test_blocked_users_cannot_send_chat_messages(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $this->withToken($tokenA)->postJson("/api/v1/chat/{$b->name}/messages", ['text' => 'önce'])
            ->assertOk();

        SocialBlock::create([
            'blocker_user_id' => $a->id,
            'blocked_user_id' => $b->id,
        ]);

        $this->withToken($tokenB)->postJson("/api/v1/chat/{$a->name}/messages", ['text' => 'selam'])
            ->assertStatus(403)->assertJsonPath('error.code', 'BLOCKED');
        $this->withToken($tokenA)->postJson("/api/v1/chat/{$b->name}/messages", ['text' => 'selam'])
            ->assertStatus(403)->assertJsonPath('error.code', 'BLOCKED');

        $aThreads = collect($this->withToken($tokenA)->getJson('/api/v1/chat/threads')->json('data'))->pluck('name');
        $bThreads = collect($this->withToken($tokenB)->getJson('/api/v1/chat/threads')->json('data'))->pluck('name');
        $this->assertFalse($aThreads->contains($b->name));
        $this->assertFalse($bThreads->contains($a->name));
    }

    public function test_client_cannot_override_responsible_staff_on_applications(): void
    {
        $this->actingAsUser();
        $catalog = StaffProfile::create([
            'id' => 'staff-club', 'name' => 'Catalog Head', 'department' => 'Student Affairs',
            'is_department_head' => true, 'active' => true,
        ]);
        StaffProfile::create([
            'id' => 'staff-other', 'name' => 'Other', 'department' => 'IT',
            'is_department_head' => true, 'active' => true,
        ]);
        Club::create([
            'id' => 'club-1', 'name' => 'Satranç', 'category' => 'Hobi',
            'description' => 'x', 'responsible_staff_id' => $catalog->id,
        ]);

        $app = $this->postJson('/api/v1/applications', [
            'targetType' => 'club',
            'targetId' => 'club-1',
            'responsibleStaffId' => 'staff-other',
        ])->assertCreated()->json('data');

        $this->assertSame('staff-club', $app['responsibleStaffId']);
    }

    public function test_trainer_update_preserves_attendee_count(): void
    {
        $user = $this->actingAsRole('trainer');
        $staff = StaffProfile::create([
            'id' => 'staff-arch-head', 'name' => 'Architecture Head', 'department' => 'Architecture',
            'is_department_head' => true, 'active' => true, 'user_id' => $user->id,
        ]);
        Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Social', 'lat' => 1, 'lng' => 1]);
        $event = $this->postJson('/api/v1/trainer/events', [
            'title' => 'V1', 'placeId' => 'p1',
        ])->json('data');
        Event::where('id', $event['id'])->update(['attendees' => 9]);

        $update = $this->postJson('/api/v1/trainer/events', [
            'id' => $event['id'], 'title' => 'V2', 'placeId' => 'p1',
        ]);
        $update->assertOk();
        $this->assertSame('V2', $update->json('data.title'));
        $this->assertSame(9, $update->json('data.attendees'));
        $this->assertSame($staff->id, $update->json('data.responsibleStaffId'));
    }

    public function test_hidden_checkins_do_not_affect_place_density(): void
    {
        $me = $this->actingAsUser();
        $place = $this->seedPlace('p-hidden');

        foreach (range(1, 5) as $i) {
            Checkin::create([
                'id' => "h-$i",
                'place_id' => $place->id,
                'user_id' => $me->id,
                'visible_to_others' => false,
                'created_at' => now(),
            ]);
        }

        $data = $this->getJson("/api/v1/places/{$place->id}")->assertOk()->json('data');
        $this->assertEquals('quiet', $data['density']);
        $this->assertEquals(0, $data['recentCheckins']);
    }

    public function test_joining_an_event_does_not_award_xp_until_approval(): void
    {
        $me = $this->actingAsRole();
        $event = Event::create([
            'id' => 'e-xp', 'title' => 'XP', 'time' => '10:00',
            'place_name' => 'X', 'category' => 'C', 'xp' => 15,
        ]);

        $this->postJson("/api/v1/events/{$event->id}/join")->assertOk();
        $this->assertEquals(0, $me->fresh()->xp);
        $this->assertEquals(0, $event->fresh()->attendees);

        $joinId = $this->getJson("/api/v1/admin/events/{$event->id}/participants")->json('data.0.id');
        $this->postJson("/api/v1/events/{$event->id}/join/form")->assertOk();
        $this->postJson("/api/v1/admin/events/{$event->id}/participants/{$joinId}/approve")->assertOk();

        $this->assertEquals(15, $me->fresh()->xp);
        $this->assertEquals(1, $event->fresh()->attendees);
        $this->assertEquals(1, $me->fresh()->events);
    }
}
