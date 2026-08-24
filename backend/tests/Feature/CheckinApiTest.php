<?php

namespace Tests\Feature;

use App\Models\Place;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckinApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visible_checkin_actually_creates_a_real_feed_post(): void
    {
        $user = $this->actingAsUser();
        $place = Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);

        $response = $this->postJson('/api/v1/checkins', ['placeId' => $place->id, 'visibleToOthers' => true]);

        $response->assertOk();
        $this->assertDatabaseHas('feed_posts', [
            'author_id' => $user->id,
            'name' => $user->name,
        ]);
        $feed = $this->getJson('/api/v1/feed')->json('data');
        $this->assertTrue(collect($feed)->contains(fn ($p) => str_contains($p['text'], $place->name)));
        $checkinPost = collect($feed)->first(fn ($p) => str_contains($p['text'], $place->name));
        $this->assertIsString($checkinPost['authorId']);
        $this->assertSame((string) $user->id, $checkinPost['authorId']);
        $this->assertSame($user->name, $checkinPost['name']);
    }

    public function test_a_hidden_checkin_does_not_create_a_feed_post(): void
    {
        $this->actingAsUser();
        $place = Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);

        $this->postJson('/api/v1/checkins', ['placeId' => $place->id, 'visibleToOthers' => false])->assertOk();

        $feed = $this->getJson('/api/v1/feed')->json('data');
        $this->assertFalse(collect($feed)->contains(fn ($p) => str_contains($p['text'], $place->name)));
    }

    public function test_checkin_real_activity_log_entry_and_xp(): void
    {
        $user = $this->actingAsUser();
        $place = Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);

        $this->postJson('/api/v1/checkins', ['placeId' => $place->id])->assertOk();

        $this->assertEquals(10, $user->fresh()->xp);
        $activity = $this->getJson('/api/v1/me/activity')->json('data');
        $this->assertTrue(collect($activity)->contains(fn ($a) => $a['kind'] === 'checkIn'));
    }
}
