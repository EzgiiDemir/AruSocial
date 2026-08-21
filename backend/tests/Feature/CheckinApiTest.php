<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Place;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckinApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visible_checkin_actually_creates_a_real_feed_post(): void
    {
        $this->actingAsAdmin();
        $place = Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);

        $response = $this->postJson('/api/v1/checkins',
            ['placeId' => $place->id, 'visibleToOthers' => true, 'lat' => 1, 'lng' => 1]);

        $response->assertOk();
        $feed = $this->getJson('/api/v1/feed')->json('data');
        $this->assertTrue(collect($feed)->contains(fn ($p) => str_contains($p['text'], $place->name)));
    }

    public function test_a_hidden_checkin_does_not_create_a_feed_post(): void
    {
        $this->actingAsAdmin();
        $place = Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);

        $this->postJson('/api/v1/checkins',
            ['placeId' => $place->id, 'visibleToOthers' => false, 'lat' => 1, 'lng' => 1])->assertOk();

        $feed = $this->getJson('/api/v1/feed')->json('data');
        $this->assertFalse(collect($feed)->contains(fn ($p) => str_contains($p['text'], $place->name)));
    }

    public function test_checkin_real_activity_log_entry_and_xp(): void
    {
        $user = $this->actingAsAdmin();
        $place = Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);

        $this->postJson('/api/v1/checkins', ['placeId' => $place->id, 'lat' => 1, 'lng' => 1])->assertOk();

        $this->assertEquals(10, $user->fresh()->xp);
        $activity = $this->getJson('/api/v1/me/activity')->json('data');
        $this->assertTrue(collect($activity)->contains(fn ($a) => $a['kind'] === 'checkIn'));
    }

    // docs/EKSIKLER.md §18/§35: real, server-side proximity enforcement —
    // the server never trusts a client-asserted "I'm nearby" flag, it
    // recomputes the distance itself from the submitted coordinates.
    public function test_checkin_without_coordinates_is_rejected(): void
    {
        $this->actingAsAdmin();
        $place = Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);

        $response = $this->postJson('/api/v1/checkins', ['placeId' => $place->id]);

        $response->assertStatus(400);
        $this->assertEquals('LOCATION_REQUIRED', $response->json('error.code'));
    }

    public function test_checkin_too_far_from_the_place_is_rejected_with_a_real_distance(): void
    {
        $this->actingAsAdmin();
        // Nicosia campus coordinates vs. a point ~2.4km away.
        $place = Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 35.3373, 'lng' => 33.3213]);

        $response = $this->postJson('/api/v1/checkins',
            ['placeId' => $place->id, 'lat' => 35.3590, 'lng' => 33.3213]);

        $response->assertStatus(400);
        $this->assertEquals('TOO_FAR', $response->json('error.code'));
        $this->assertStringContainsString('km', $response->json('error.message'));
    }

    public function test_checkin_within_the_admin_configured_radius_is_accepted(): void
    {
        $this->actingAsAdmin();
        $place = Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 35.3373, 'lng' => 33.3213]);
        // ~500m away — would fail the default 150m radius but should pass
        // once an admin widens it.
        AppSetting::setValue('checkin.radiusMeters', '1000');

        $this->postJson('/api/v1/checkins',
            ['placeId' => $place->id, 'lat' => 35.3418, 'lng' => 33.3213])->assertOk();
    }

    public function test_admin_checkin_radius_settings_round_trip(): void
    {
        $this->actingAsAdmin();
        $before = $this->getJson('/api/v1/admin/settings/checkin-radius');
        $before->assertOk();
        $this->assertEquals(150, $before->json('data.radiusMeters'));

        $this->postJson('/api/v1/admin/settings/checkin-radius', ['radiusMeters' => 250])
            ->assertOk()->assertJsonPath('data.radiusMeters', 250);

        $after = $this->getJson('/api/v1/admin/settings/checkin-radius');
        $this->assertEquals(250, $after->json('data.radiusMeters'));
    }
}
