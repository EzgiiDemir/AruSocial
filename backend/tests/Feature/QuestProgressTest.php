<?php

namespace Tests\Feature;

use App\Models\Checkin;
use App\Models\Event;
use App\Models\Quest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlaces;
use Tests\TestCase;

class QuestProgressTest extends TestCase
{
    use RefreshDatabase, CreatesPlaces;

    public function test_distinct_checkins_progress_counts_unique_places_and_caps_at_target(): void
    {
        $me = $this->actingAsUser();
        $a = $this->seedPlace('p-a');
        $b = $this->seedPlace('p-b');
        Quest::create([
            'id' => 'q-explore',
            'user_id' => $me->id,
            'title' => 'Kaşif',
            'subtitle' => '2 yer',
            'progress' => 99,
            'target' => 2,
            'reward' => 10,
            'kind' => 'distinct_checkins',
        ]);

        Checkin::create(['id' => 'c1', 'place_id' => $a->id, 'user_id' => $me->id, 'visible_to_others' => true, 'created_at' => now()]);
        Checkin::create(['id' => 'c2', 'place_id' => $a->id, 'user_id' => $me->id, 'visible_to_others' => true, 'created_at' => now()]);
        $this->assertEquals(1, $this->progressOf('q-explore'));

        Checkin::create(['id' => 'c3', 'place_id' => $b->id, 'user_id' => $me->id, 'visible_to_others' => true, 'created_at' => now()]);
        $this->assertEquals(2, $this->progressOf('q-explore'));
    }

    public function test_event_joins_progress_moves_when_the_student_joins(): void
    {
        $me = $this->actingAsUser();
        Quest::create([
            'id' => 'q-events',
            'user_id' => $me->id,
            'title' => 'Toplayıcı',
            'subtitle' => '2 etkinlik',
            'progress' => 0,
            'target' => 2,
            'reward' => 10,
            'kind' => 'event_joins',
        ]);
        $event = Event::create(['id' => 'e1', 'title' => 'Test', 'time' => '10:00', 'place_name' => 'X', 'category' => 'C', 'xp' => 20]);

        $this->assertEquals(0, $this->progressOf('q-events'));
        $this->postJson("/api/v1/events/{$event->id}/join")->assertOk();
        $this->assertEquals(1, $this->progressOf('q-events'));
    }

    public function test_static_kind_keeps_the_stored_progress_column(): void
    {
        $me = $this->actingAsUser();
        Quest::create([
            'id' => 'q-static',
            'user_id' => $me->id,
            'title' => 'Eski',
            'subtitle' => 'statik',
            'progress' => 3,
            'target' => 5,
            'reward' => 10,
            'kind' => 'static',
        ]);

        $this->assertEquals(3, $this->progressOf('q-static'));
    }

    public function test_activity_includes_created_at_and_xp_for_a_checkin(): void
    {
        $this->actingAsUser();
        $place = $this->seedPlace('p-xp');

        $this->postJson('/api/v1/checkins', $this->checkinNear($place->id))->assertOk();

        $row = collect($this->getJson('/api/v1/me/activity')->json('data'))
            ->first(fn ($a) => $a['kind'] === 'checkIn');

        $this->assertNotNull($row);
        $this->assertEquals(10, $row['xp']);
        $this->assertNotEmpty($row['createdAt']);
    }

    private function progressOf(string $id): int
    {
        $quests = collect($this->getJson('/api/v1/me/quests')->assertOk()->json('data'));

        return (int) $quests->firstWhere('id', $id)['progress'];
    }
}
