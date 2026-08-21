<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckinApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visible_checkin_actually_creates_a_real_feed_post(): void
    {
        User::create(['name' => 'Test Student', 'email' => 'test@arucad.edu.tr', 'password' => bcrypt('x')]);
        $place = Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);

        $response = $this->postJson('/api/v1/checkins', ['placeId' => $place->id, 'visibleToOthers' => true]);

        $response->assertOk();
        $feed = $this->getJson('/api/v1/feed')->json('data');
        $this->assertTrue(collect($feed)->contains(fn ($p) => str_contains($p['text'], $place->name)));
    }

    public function test_a_hidden_checkin_does_not_create_a_feed_post(): void
    {
        User::create(['name' => 'Test Student', 'email' => 'test@arucad.edu.tr', 'password' => bcrypt('x')]);
        $place = Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);

        $this->postJson('/api/v1/checkins', ['placeId' => $place->id, 'visibleToOthers' => false])->assertOk();

        $feed = $this->getJson('/api/v1/feed')->json('data');
        $this->assertFalse(collect($feed)->contains(fn ($p) => str_contains($p['text'], $place->name)));
    }

    public function test_checkin_real_activity_log_entry_and_xp(): void
    {
        $user = User::create(['name' => 'Test Student', 'email' => 'test@arucad.edu.tr', 'password' => bcrypt('x')]);
        $place = Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);

        $this->postJson('/api/v1/checkins', ['placeId' => $place->id])->assertOk();

        $this->assertEquals(10, $user->fresh()->xp);
        $activity = $this->getJson('/api/v1/me/activity')->json('data');
        $this->assertTrue(collect($activity)->contains(fn ($a) => $a['kind'] === 'checkIn'));
    }
}
