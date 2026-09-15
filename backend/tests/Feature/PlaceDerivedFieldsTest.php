<?php

namespace Tests\Feature;

use App\Models\Checkin;
use App\Models\Review;
use App\Services\PlacePresence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlaces;
use Tests\TestCase;

class PlaceDerivedFieldsTest extends TestCase
{
    use CreatesPlaces, RefreshDatabase;

    public function test_a_place_with_no_activity_is_quiet_with_zero_rating(): void
    {
        $this->actingAsUser();
        $place = $this->seedPlace('p-empty');
        $place->update(['density' => 'busy', 'rating' => 4.9]);

        $data = $this->getJson("/api/v1/places/{$place->id}")->assertOk()->json('data');

        $this->assertEquals('quiet', $data['density']);
        $this->assertEquals(0, $data['rating']);
        $this->assertEquals(0, $data['recentCheckins']);
        $this->assertEquals(0, $data['totalCheckins']);
        $this->assertNull($data['coverUrl']);
    }

    public function test_density_is_derived_from_recent_checkins(): void
    {
        $me = $this->actingAsUser();
        $place = $this->seedPlace('p-busy');

        foreach (range(1, 5) as $i) {
            Checkin::create([
                'id' => "c-$i",
                'place_id' => $place->id,
                'user_id' => $me->id,
                'visible_to_others' => true,
                'created_at' => now(),
            ]);
        }

        $this->assertEquals('busy', $this->getJson("/api/v1/places/{$place->id}")->json('data.density'));
        $this->assertEquals(5, $this->getJson("/api/v1/places/{$place->id}")->json('data.recentCheckins'));
    }

    public function test_recent_checkin_entries_are_real_initials_capped_and_most_recent_first(): void
    {
        $me = $this->actingAsUser();
        $place = $this->seedPlace('p-entries');

        foreach (range(1, 5) as $i) {
            Checkin::create([
                'id' => "ce-$i",
                'place_id' => $place->id,
                'user_id' => $me->id,
                'visible_to_others' => true,
                'created_at' => now()->addSeconds($i),
            ]);
        }

        $entries = $this->getJson("/api/v1/places/{$place->id}")
            ->assertOk()
            ->json('data.recentCheckinEntries');

        // 5 real check-ins exist in the window, but the list is capped.
        $this->assertCount(PlacePresence::MAX_RECENT_ENTRIES, $entries);
        // "Test Student" -> "T.", not a name-hash-derived fake initial.
        $this->assertEquals('T.', $entries[0]['initial']);
        $this->assertNotNull($entries[0]['checkedInAt']);
        // Most recent check-in (i=5) sorts first.
        $this->assertStringContainsString(
            now()->addSeconds(5)->toIso8601String(),
            $entries[0]['checkedInAt'],
        );
    }

    public function test_recent_checkin_entries_are_empty_and_do_not_include_invisible_checkins(): void
    {
        $me = $this->actingAsUser();
        $place = $this->seedPlace('p-hidden');

        Checkin::create([
            'id' => 'ch-hidden',
            'place_id' => $place->id,
            'user_id' => $me->id,
            'visible_to_others' => false,
            'created_at' => now(),
        ]);

        $data = $this->getJson("/api/v1/places/{$place->id}")->assertOk()->json('data');
        $this->assertSame([], $data['recentCheckinEntries']);
        $this->assertEquals(1, $data['totalCheckins']);
    }

    public function test_two_recent_checkins_are_moderate_and_stale_ones_do_not_count(): void
    {
        $me = $this->actingAsUser();
        $place = $this->seedPlace('p-mod');

        Checkin::create([
            'id' => 'c-old',
            'place_id' => $place->id,
            'user_id' => $me->id,
            'visible_to_others' => true,
            'created_at' => now()->subHours(PlacePresence::WINDOW_HOURS + 1),
        ]);
        Checkin::create([
            'id' => 'c-1',
            'place_id' => $place->id,
            'user_id' => $me->id,
            'visible_to_others' => true,
            'created_at' => now(),
        ]);
        Checkin::create([
            'id' => 'c-2',
            'place_id' => $place->id,
            'user_id' => $me->id,
            'visible_to_others' => true,
            'created_at' => now(),
        ]);

        $data = $this->getJson("/api/v1/places/{$place->id}")->assertOk()->json('data');
        $this->assertEquals('moderate', $data['density']);
        $this->assertEquals(2, $data['recentCheckins']);
        $this->assertEquals(3, $data['totalCheckins']);
    }

    public function test_rating_is_the_average_of_reviews(): void
    {
        $me = $this->actingAsUser();
        $place = $this->seedPlace('p-rated');

        Review::create([
            'id' => 'r-1', 'place_id' => $place->id, 'user_id' => $me->id,
            'rating' => 5, 'comment' => '', 'meta' => '', 'created_at' => now(), 'moderation_status' => 'approved',
        ]);
        Review::create([
            'id' => 'r-2', 'place_id' => $place->id, 'user_id' => $me->id,
            'rating' => 3, 'comment' => '', 'meta' => '', 'created_at' => now(), 'moderation_status' => 'approved',
        ]);

        $this->assertEquals(4.0, $this->getJson("/api/v1/places/{$place->id}")->json('data.rating'));
    }

    public function test_setting_a_cover_url_round_trips_on_get(): void
    {
        $this->actingAsRole();
        $place = $this->seedPlace('p-cover');

        $data = $this->postJson("/api/v1/places/{$place->id}/cover", [
            'url' => 'https://cdn.example/cover.jpg',
        ])->assertOk()->json('data');

        $this->assertEquals('https://cdn.example/cover.jpg', $data['coverUrl']);
        $this->assertEquals(
            'https://cdn.example/cover.jpg',
            $this->getJson("/api/v1/places/{$place->id}")->json('data.coverUrl'),
        );
    }

    public function test_cover_requires_url_and_a_real_place(): void
    {
        $this->actingAsRole();

        $this->postJson('/api/v1/places/p-cover/cover', [])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION');
        $this->postJson('/api/v1/places/missing/cover', ['url' => 'https://x'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'PLACE_NOT_FOUND');
    }

    public function test_cover_requires_a_signed_in_account(): void
    {
        $this->seedPlace('p-cover');

        $this->postJson('/api/v1/places/p-cover/cover', ['url' => 'https://x'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
    }
}
