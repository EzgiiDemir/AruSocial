<?php

namespace Tests\Feature;

use App\Models\Checkin;
use App\Models\Event;
use App\Models\EventJoin;
use App\Models\Place;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminStatsApiTest extends TestCase
{
    use RefreshDatabase;

    private function seedUser(): User
    {
        return $this->actingAsAdmin();
    }

    public function test_stats_honestly_reports_app_downloads_as_untrackable(): void
    {
        $this->seedUser();

        $response = $this->getJson('/api/v1/admin/stats');

        $response->assertOk();
        $this->assertFalse($response->json('data.appUsage.trackable'));
        $this->assertNotEmpty($response->json('data.appUsage.note'));
    }

    public function test_most_checked_in_places_is_a_real_aggregate_not_a_guess(): void
    {
        $user = $this->seedUser();
        $garden = Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);
        $studio = Place::create(['id' => 'p2', 'name' => 'Studio', 'category' => 'Indoor', 'lat' => 1, 'lng' => 1]);

        Checkin::create(['id' => 'c1', 'place_id' => $garden->id, 'user_id' => $user->id, 'created_at' => now()]);
        Checkin::create(['id' => 'c2', 'place_id' => $garden->id, 'user_id' => $user->id, 'created_at' => now()]);
        Checkin::create(['id' => 'c3', 'place_id' => $studio->id, 'user_id' => $user->id, 'created_at' => now()]);

        $response = $this->getJson('/api/v1/admin/stats');

        $response->assertOk();
        $this->assertEquals(3, $response->json('data.checkins.total'));
        $this->assertEquals(3, $response->json('data.checkins.today'));
        $this->assertGreaterThan(0, $response->json('data.checkins.shareRate') + $response->json('data.checkins.hiddenXpRate'));
        $top = $response->json('data.checkins.mostCheckedInPlaces');
        $this->assertEquals('Garden', $top[0]['place_name']);
        $this->assertEquals(2, $top[0]['total']);
        $this->assertEquals('Studio', $top[1]['place_name']);
        $this->assertEquals(1, $top[1]['total']);
    }

    public function test_checkins_by_day_only_counts_within_the_requested_window(): void
    {
        $user = $this->seedUser();
        $place = Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);

        Checkin::create(['id' => 'c1', 'place_id' => $place->id, 'user_id' => $user->id, 'created_at' => now()]);
        Checkin::create(['id' => 'c2', 'place_id' => $place->id, 'user_id' => $user->id, 'created_at' => now()->subDays(30)]);

        $response = $this->getJson('/api/v1/admin/stats?days=7');

        $response->assertOk();
        $byDay = $response->json('data.checkins.byDay');
        $total = array_sum(array_column($byDay, 'total'));
        $this->assertEquals(1, $total);
    }

    public function test_most_joined_events_and_attendance_funnel_are_real(): void
    {
        $user = $this->seedUser();
        $event = Event::create(['id' => 'e1', 'title' => 'Test', 'time' => '10:00', 'place_name' => 'X', 'category' => 'C']);
        EventJoin::create([
            'id' => 'j1', 'event_id' => $event->id, 'user_id' => $user->id, 'joined_at' => now(),
            'form_submitted_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/admin/stats');

        $response->assertOk();
        $this->assertEquals(1, $response->json('data.events.totalJoins'));
        $this->assertEquals(1, $response->json('data.events.formsSubmitted'));
        $this->assertEquals(0, $response->json('data.events.attendanceApproved'));
        $mostJoined = $response->json('data.events.mostJoinedEvents');
        $this->assertEquals('Test', $mostJoined[0]['title']);
        $this->assertEquals(1, $mostJoined[0]['total']);
    }
}
