<?php

namespace Tests\Feature;

use App\Models\Place;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampusGeofenceCheckinTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkin_outside_every_campus_site_is_rejected(): void
    {
        $this->actingAsUser();
        Place::create([
            'id' => 'p-nyc',
            'name' => 'Remote',
            'category' => 'Other',
            'lat' => 40.7128,
            'lng' => -74.0060,
        ]);

        $this->postJson('/api/v1/checkins', [
            'placeId' => 'p-nyc',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'visibleToOthers' => true,
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CHECKIN_OFF_CAMPUS');

        $this->assertDatabaseCount('checkins', 0);
    }

    public function test_a_kyrenia_point_outside_the_campus_polygon_is_off_campus(): void
    {
        $this->actingAsUser();
        Place::create([
            'id' => 'p-street',
            'name' => 'Street',
            'category' => 'Outdoor',
            'lat' => 35.33715,
            'lng' => 33.32135,
        ]);

        $this->postJson('/api/v1/checkins', [
            'placeId' => 'p-street',
            'latitude' => 35.35,
            'longitude' => 33.35,
            'visibleToOthers' => true,
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CHECKIN_OFF_CAMPUS');

        $this->assertDatabaseCount('checkins', 0);
    }

    public function test_on_campus_but_too_far_from_the_place_is_still_too_far(): void
    {
        $this->actingAsUser();
        Place::create([
            'id' => 'p1',
            'name' => 'Garden',
            'category' => 'Outdoor',
            'lat' => 35.33715,
            'lng' => 33.32135,
        ]);

        $this->postJson('/api/v1/checkins', [
            'placeId' => 'p1',
            'latitude' => 35.33920,
            'longitude' => 33.32135,
            'visibleToOthers' => true,
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CHECKIN_TOO_FAR');
    }
}
