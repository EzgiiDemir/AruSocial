<?php

namespace Tests\Feature;

use App\Models\Place;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckinApiTest extends TestCase
{
    use RefreshDatabase;

    private function placeNearOrigin(): Place
    {
        return Place::create([
            'id' => 'p1',
            'name' => 'Garden',
            'category' => 'Outdoor',
            'lat' => 35.33715,
            'lng' => 33.32135,
        ]);
    }

    private function nearbyPayload(string $placeId, bool $visible = true): array
    {
        return [
            'placeId' => $placeId,
            'latitude' => 35.33715,
            'longitude' => 33.32135,
            'visibleToOthers' => $visible,
        ];
    }

    public function test_a_visible_checkin_actually_creates_a_real_feed_post(): void
    {
        $user = $this->actingAsUser();
        $place = $this->placeNearOrigin();

        $response = $this->postJson('/api/v1/checkins', $this->nearbyPayload($place->id, true));

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
        $place = $this->placeNearOrigin();

        $this->postJson('/api/v1/checkins', $this->nearbyPayload($place->id, false))->assertOk();

        $feed = $this->getJson('/api/v1/feed')->json('data');
        $this->assertFalse(collect($feed)->contains(fn ($p) => str_contains($p['text'], $place->name)));
    }

    public function test_checkin_real_activity_log_entry_and_xp(): void
    {
        $user = $this->actingAsUser();
        $place = $this->placeNearOrigin();

        $this->postJson('/api/v1/checkins', $this->nearbyPayload($place->id))->assertOk()
            ->assertJsonPath('data.xpAwarded', 10);

        $this->assertEquals(10, $user->fresh()->xp);
        $activity = $this->getJson('/api/v1/me/activity')->json('data');
        $this->assertTrue(collect($activity)->contains(fn ($a) => $a['kind'] === 'checkIn'));
    }

    public function test_checkin_too_far_is_rejected_with_distance_details(): void
    {
        $this->actingAsUser();
        $place = $this->placeNearOrigin();

        $response = $this->postJson('/api/v1/checkins', [
            'placeId' => $place->id,
            'latitude' => 35.33920,
            'longitude' => 33.32135,
            'visibleToOthers' => true,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'CHECKIN_TOO_FAR');
        $this->assertGreaterThan(150, $response->json('error.details.distanceMeters'));
        $this->assertDatabaseCount('checkins', 0);
    }

    public function test_checkin_requires_coordinates(): void
    {
        $this->actingAsUser();
        $place = $this->placeNearOrigin();

        $this->postJson('/api/v1/checkins', ['placeId' => $place->id])
            ->assertStatus(400);
    }

    public function test_duplicate_checkin_within_cooldown_is_rejected(): void
    {
        $this->actingAsUser();
        $place = $this->placeNearOrigin();

        $this->postJson('/api/v1/checkins', $this->nearbyPayload($place->id))->assertOk();
        $this->postJson('/api/v1/checkins', $this->nearbyPayload($place->id))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ALREADY_CHECKED_IN');
    }
}
