<?php

namespace Tests\Feature;

use App\Models\AchievementDefinition;
use App\Models\Club;
use App\Models\Event;
use App\Models\Place;
use App\Models\User;
use App\Models\UserAchievement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AchievementTest extends TestCase
{
    use RefreshDatabase;

    private function seedDefinitions(): void
    {
        foreach ([
            ['ach-first-checkin', 'İlk Adım', 'checkin_count', 1],
            ['ach-explorer-5', 'Kampüs Kâşifi', 'distinct_checkins', 5],
            ['ach-first-event', 'Sahneye Çık', 'event_joins', 1],
            ['ach-first-club', 'Kulüp Üyesi', 'club_joins', 1],
            ['ach-first-review', 'Geri Bildirim', 'reviews', 1],
        ] as $i => [$id, $title, $kind, $threshold]) {
            AchievementDefinition::create([
                'id' => $id,
                'title' => $title,
                'subtitle' => $title,
                'trigger_kind' => $kind,
                'threshold' => $threshold,
                'sort_order' => $i + 1,
            ]);
        }
    }

    private function seedPlace(string $id = 'place-1'): Place
    {
        return Place::create([
            'id' => $id, 'name' => "Place $id", 'category' => 'Studio',
            'lat' => 35.337502, 'lng' => 33.321226, 'description' => 'x',
            'distance' => '10m', 'density' => 'quiet', 'street' => 'x',
            'accessible' => true, 'photos' => 0, 'rating' => 4.0,
        ]);
    }

    public function test_fresh_user_sees_all_achievements_locked(): void
    {
        $this->seedDefinitions();
        $this->actingAsUser();

        $data = $this->getJson('/api/v1/me/achievements')->assertOk()->json('data');

        $this->assertCount(5, $data);
        $this->assertTrue(collect($data)->every(fn ($row) => $row['unlocked'] === false));
        $this->assertDatabaseCount('user_achievements', 0);
    }

    public function test_checkin_unlocks_first_checkin_achievement(): void
    {
        $this->seedDefinitions();
        $me = $this->actingAsUser();
        $place = $this->seedPlace();

        $this->postJson('/api/v1/checkins', $this->checkinNear($place->id))->assertOk();

        $this->assertDatabaseHas('user_achievements', [
            'user_id' => $me->id, 'achievement_id' => 'ach-first-checkin',
        ]);
        $row = collect($this->getJson('/api/v1/me/achievements')->json('data'))
            ->firstWhere('id', 'ach-first-checkin');
        $this->assertTrue($row['unlocked']);
        $this->assertNotNull($row['unlockedAt']);
    }

    public function test_duplicate_checkin_does_not_duplicate_unlock(): void
    {
        $this->seedDefinitions();
        $me = $this->actingAsUser();
        $place = $this->seedPlace();

        $this->postJson('/api/v1/checkins', $this->checkinNear($place->id))->assertOk();
        $this->postJson('/api/v1/checkins', $this->checkinNear($place->id))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ALREADY_CHECKED_IN');

        $this->assertEquals(1, UserAchievement::where('user_id', $me->id)
            ->where('achievement_id', 'ach-first-checkin')->count());
    }

    public function test_club_join_unlocks_club_achievement(): void
    {
        $this->seedDefinitions();
        $me = $this->actingAsUser();
        Club::create(['id' => 'club-1', 'name' => 'Club', 'category' => 'Art']);
        \App\Models\ParticipationApplication::create([
            'id' => 'app-ach-club',
            'user_id' => $me->id,
            'target_type' => 'club',
            'target_id' => 'club-1',
            'status' => 'approved',
            'form_payload' => [],
            'submitted_at' => now(),
        ]);

        $this->postJson('/api/v1/clubs/club-1/join')->assertOk();

        $this->assertDatabaseHas('user_achievements', [
            'user_id' => $me->id, 'achievement_id' => 'ach-first-club',
        ]);
    }

    public function test_review_unlocks_review_achievement(): void
    {
        $this->seedDefinitions();
        $me = $this->actingAsUser();
        $place = $this->seedPlace();

        $this->postJson("/api/v1/places/{$place->id}/reviews", [
            'rating' => 5, 'comment' => 'güzel',
        ])->assertOk();

        $this->assertDatabaseHas('user_achievements', [
            'user_id' => $me->id, 'achievement_id' => 'ach-first-review',
        ]);
    }

    public function test_event_join_unlocks_event_achievement(): void
    {
        $this->seedDefinitions();
        $me = $this->actingAsUser();
        Event::create([
            'id' => 'event-1', 'title' => 'Test', 'time' => '14:00',
            'place_name' => 'Garden', 'category' => 'Etkinlik',
            'attendees' => 0, 'xp' => 20, 'draft' => false,
            'workflow_status' => 'published',
        ]);

        $this->postJson('/api/v1/events/event-1/join')->assertOk();

        $this->assertDatabaseHas('user_achievements', [
            'user_id' => $me->id, 'achievement_id' => 'ach-first-event',
        ]);
    }

    public function test_achievements_are_isolated_per_user(): void
    {
        $this->seedDefinitions();
        $other = User::create(['name' => 'Other', 'email' => 'other@arucad.edu.tr', 'password' => bcrypt('x')]);
        $this->actingAsUser($other);
        $place = $this->seedPlace();
        $this->postJson('/api/v1/checkins', $this->checkinNear($place->id))->assertOk();

        $this->actingAsUser();
        $data = $this->getJson('/api/v1/me/achievements')->json('data');
        $this->assertTrue(collect($data)->every(fn ($row) => $row['unlocked'] === false));
    }

    public function test_achievements_require_auth(): void
    {
        $this->getJson('/api/v1/me/achievements')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
    }

    public function test_there_is_no_client_unlock_endpoint(): void
    {
        $this->actingAsUser();
        $this->postJson('/api/v1/me/achievements/ach-first-checkin/unlock')->assertStatus(404);
    }
}
