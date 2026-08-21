<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Place;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// docs/EKSIKLER.md harita/check-in/XP — "xp_transactions mantığı": every
// real XP grant must leave a permanent, explained row behind, not just
// bump a counter.
class XpLedgerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_checkin_records_a_real_xp_transaction(): void
    {
        $user = $this->actingAsAdmin();
        $place = Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);

        $this->postJson('/api/v1/checkins', ['placeId' => $place->id, 'lat' => 1, 'lng' => 1])->assertOk();

        $this->assertEquals(10, $user->fresh()->xp);
        $response = $this->getJson('/api/v1/me/xp-transactions');
        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertEquals(10, $rows[0]['amount']);
        $this->assertEquals('checkin', $rows[0]['sourceType']);
        $this->assertNotNull($rows[0]['sourceId']);
    }

    public function test_an_event_join_records_a_real_xp_transaction(): void
    {
        $user = $this->actingAsAdmin();
        $event = Event::create(['id' => 'e1', 'title' => 'Test', 'time' => '10:00', 'place_name' => 'X', 'category' => 'C', 'xp' => 25]);

        $this->postJson("/api/v1/events/{$event->id}/join")->assertOk();

        $this->assertEquals(25, $user->fresh()->xp);
        $rows = $this->getJson('/api/v1/me/xp-transactions')->json('data');
        $this->assertCount(1, $rows);
        $this->assertEquals(25, $rows[0]['amount']);
        $this->assertEquals('event_join', $rows[0]['sourceType']);
    }

    public function test_transactions_accumulate_across_multiple_real_grants(): void
    {
        $user = $this->actingAsAdmin();
        $place = Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);
        $event = Event::create(['id' => 'e1', 'title' => 'Test', 'time' => '10:00', 'place_name' => 'X', 'category' => 'C', 'xp' => 25]);

        $this->postJson('/api/v1/checkins', ['placeId' => $place->id, 'lat' => 1, 'lng' => 1])->assertOk();
        $this->postJson("/api/v1/events/{$event->id}/join")->assertOk();

        $this->assertEquals(35, $user->fresh()->xp);
        $rows = $this->getJson('/api/v1/me/xp-transactions')->json('data');
        $this->assertCount(2, $rows);
        $types = collect($rows)->pluck('sourceType')->sort()->values();
        $this->assertEquals(['checkin', 'event_join'], $types->all());
    }
}
