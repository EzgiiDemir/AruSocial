<?php

namespace Tests\Feature;

use App\Models\CampusPresence;
use App\Models\Place;
use App\Models\User;
use App\Services\LiveCrowd;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The map's crowd counts come from where people actually are, not from the
 * handful who deliberately check in. These tests pin down the two things
 * that matter about that: the counting is real, and the privacy rules are
 * enforced on the server rather than trusted to the client.
 */
class LivePresenceApiTest extends TestCase
{
    use RefreshDatabase;

    /** On the main campus, a short walk apart. */
    private const LIBRARY_LAT = 35.33715;

    private const LIBRARY_LNG = 33.32135;

    private const GALLERY_LAT = 35.33780;

    private const GALLERY_LNG = 33.32210;

    protected function setUp(): void
    {
        parent::setUp();
        Place::create([
            'id' => 'library',
            'name' => 'Library',
            'category' => 'Study',
            'lat' => self::LIBRARY_LAT,
            'lng' => self::LIBRARY_LNG,
        ]);
        Place::create([
            'id' => 'gallery',
            'name' => 'Gallery',
            'category' => 'Culture',
            'lat' => self::GALLERY_LAT,
            'lng' => self::GALLERY_LNG,
        ]);
    }

    private function sharingUser(string $email, string $visibility = 'public'): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => 'Student', 'password' => bcrypt('x')],
        );
        $user->location_visibility = $visibility;
        $user->save();

        return $user;
    }

    private function pingFrom(float $lat, float $lng)
    {
        return $this->postJson('/api/v1/presence/ping', [
            'latitude' => $lat,
            'longitude' => $lng,
        ]);
    }

    public function test_a_ping_resolves_to_the_nearest_place_and_counts_towards_it(): void
    {
        $this->actingAsUser($this->sharingUser('a@arucad.edu.tr'));

        $this->pingFrom(self::LIBRARY_LAT, self::LIBRARY_LNG)
            ->assertOk()
            ->assertJsonPath('data.placeId', 'library');

        $live = $this->getJson('/api/v1/presence/live')->assertOk()->json('data');
        $this->assertSame(1, $live['total']);
        $this->assertSame('library', $live['places'][0]['placeId']);
        $this->assertSame(1, $live['places'][0]['count']);
    }

    public function test_the_busiest_place_is_listed_first(): void
    {
        foreach (['a', 'b', 'c'] as $who) {
            Sanctum::actingAs($this->sharingUser($who.'@arucad.edu.tr'));
            $this->pingFrom(self::GALLERY_LAT, self::GALLERY_LNG)->assertOk();
        }
        Sanctum::actingAs($this->sharingUser('d@arucad.edu.tr'));
        $this->pingFrom(self::LIBRARY_LAT, self::LIBRARY_LNG)->assertOk();

        $live = $this->getJson('/api/v1/presence/live')->json('data');

        $this->assertSame(4, $live['total']);
        $this->assertSame(['gallery', 'library'], array_column($live['places'], 'placeId'));
        $this->assertSame([3, 1], array_column($live['places'], 'count'));
    }

    public function test_a_ghost_mode_student_is_never_recorded(): void
    {
        $this->actingAsUser($this->sharingUser('ghost@arucad.edu.tr', 'ghost'));

        $this->pingFrom(self::LIBRARY_LAT, self::LIBRARY_LNG)
            ->assertOk()
            ->assertJsonPath('data.placeId', null)
            ->assertJsonPath('data.sharing', false);

        $this->assertDatabaseCount('campus_presences', 0);
        $this->assertSame(0, $this->getJson('/api/v1/presence/live')->json('data.total'));
    }

    public function test_switching_to_ghost_mode_drops_an_existing_presence_row(): void
    {
        $user = $this->sharingUser('switcher@arucad.edu.tr');
        $this->actingAsUser($user);
        $this->pingFrom(self::LIBRARY_LAT, self::LIBRARY_LNG)->assertOk();
        $this->assertDatabaseCount('campus_presences', 1);

        $user->location_visibility = 'ghost';
        $user->save();

        $this->pingFrom(self::LIBRARY_LAT, self::LIBRARY_LNG)->assertOk();

        $this->assertDatabaseCount('campus_presences', 0);
    }

    public function test_a_ping_from_off_campus_is_not_recorded(): void
    {
        $this->actingAsUser($this->sharingUser('away@arucad.edu.tr'));

        $this->pingFrom(41.0082, 28.9784)
            ->assertOk()
            ->assertJsonPath('data.placeId', null);

        $this->assertDatabaseCount('campus_presences', 0);
    }

    public function test_a_ping_on_campus_but_far_from_every_place_is_not_attributed_to_one(): void
    {
        $this->actingAsUser($this->sharingUser('wandering@arucad.edu.tr'));

        // Inside the campus envelope, but hundreds of metres from both
        // places — further than the presence radius, so it belongs to
        // neither rather than being credited to the closest one.
        $this->pingFrom(35.34090, 33.32135)
            ->assertOk()
            ->assertJsonPath('data.placeId', null);

        $this->assertDatabaseCount('campus_presences', 0);
    }

    public function test_a_presence_older_than_the_window_stops_counting(): void
    {
        $user = $this->sharingUser('stale@arucad.edu.tr');
        CampusPresence::create([
            'user_id' => $user->id,
            'place_id' => 'library',
            'updated_at' => now()->subMinutes(LiveCrowd::WINDOW_MINUTES + 1),
        ]);
        $this->actingAsUser($user);

        $live = $this->getJson('/api/v1/presence/live')->json('data');

        $this->assertSame(0, $live['total']);
        $this->assertSame([], $live['places']);
    }

    public function test_the_live_feed_never_discloses_who_is_where(): void
    {
        $this->actingAsUser($this->sharingUser('named@arucad.edu.tr'));
        $this->pingFrom(self::LIBRARY_LAT, self::LIBRARY_LNG)->assertOk();

        $response = $this->getJson('/api/v1/presence/live');

        $this->assertStringNotContainsString('named@arucad.edu.tr', $response->getContent());
        $this->assertSame(
            ['placeId', 'name', 'category', 'lat', 'lng', 'count'],
            array_keys($response->json('data.places.0')),
        );
    }

    public function test_forget_removes_my_presence_immediately(): void
    {
        $this->actingAsUser($this->sharingUser('leaver@arucad.edu.tr'));
        $this->pingFrom(self::LIBRARY_LAT, self::LIBRARY_LNG)->assertOk();

        $this->postJson('/api/v1/presence/forget')->assertOk();

        $this->assertDatabaseCount('campus_presences', 0);
    }

    public function test_presence_endpoints_require_authentication(): void
    {
        $this->pingFrom(self::LIBRARY_LAT, self::LIBRARY_LNG)->assertStatus(401);
        $this->getJson('/api/v1/presence/live')->assertStatus(401);
    }

    public function test_a_ping_without_valid_coordinates_is_rejected(): void
    {
        $this->actingAsUser($this->sharingUser('sloppy@arucad.edu.tr'));

        // 400 with a VALIDATION code is this API's contract for a bad
        // request body (see FailsWithApiValidation), not 422.
        $this->postJson('/api/v1/presence/ping', [])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION');
        $this->pingFrom(999, 0)->assertStatus(400);
    }
}
