<?php

namespace Tests\Feature;

use App\Models\Checkin;
use App\Models\Place;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// docs/EKSIKLER.md harita/heatmap — real density from real Checkin rows,
// not the static seeded `density` string.
class PlaceDensityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_density_reflects_real_checkin_counts_with_documented_thresholds(): void
    {
        $user = $this->actingAsAdmin();
        $busy = Place::create(['id' => 'p1', 'name' => 'Busy', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);
        $moderate = Place::create(['id' => 'p2', 'name' => 'Moderate', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);
        $quiet = Place::create(['id' => 'p3', 'name' => 'Quiet', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);

        foreach (range(1, 3) as $i) {
            Checkin::create(['id' => "c-busy-$i", 'place_id' => $busy->id, 'user_id' => $user->id, 'created_at' => now()]);
        }
        Checkin::create(['id' => 'c-mod-1', 'place_id' => $moderate->id, 'user_id' => $user->id, 'created_at' => now()]);

        $response = $this->getJson('/api/v1/places/density?window=today');
        $response->assertOk();
        $byId = collect($response->json('data'))->keyBy('placeId');

        $this->assertEquals(3, $byId[$busy->id]['checkins']);
        $this->assertEquals('busy', $byId[$busy->id]['level']);
        $this->assertEquals(1, $byId[$moderate->id]['checkins']);
        $this->assertEquals('moderate', $byId[$moderate->id]['level']);
        $this->assertEquals(0, $byId[$quiet->id]['checkins']);
        $this->assertEquals('quiet', $byId[$quiet->id]['level']);
    }

    public function test_density_window_excludes_checkins_outside_the_range(): void
    {
        $user = $this->actingAsAdmin();
        $place = Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);

        Checkin::create(['id' => 'c1', 'place_id' => $place->id, 'user_id' => $user->id, 'created_at' => now()->subDays(10)]);

        $today = $this->getJson('/api/v1/places/density?window=today')->json('data');
        $this->assertEquals(0, collect($today)->firstWhere('placeId', $place->id)['checkins']);

        $last30 = $this->getJson('/api/v1/places/density?window=30d')->json('data');
        $this->assertEquals(1, collect($last30)->firstWhere('placeId', $place->id)['checkins']);
    }
}
