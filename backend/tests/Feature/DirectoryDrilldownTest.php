<?php

namespace Tests\Feature;

use App\Models\DirectoryEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Building → floor → room has to survive the data as it actually arrives.
 *
 * Both bugs pinned here were live 404s in the browser console, and both
 * came from the same mistake: a value that means one thing in one place
 * and something else two functions away.
 */
class DirectoryDrilldownTest extends TestCase
{
    use RefreshDatabase;

    private function entry(string $building, ?string $floor, string $room): void
    {
        DirectoryEntry::create([
            'id' => (string) Str::uuid(),
            'building' => $building,
            'floor' => $floor,
            'room' => $room,
            // Rooms synced from 360 have no occupant; the column is NOT
            // NULL, so the sync writes an empty string.
            'occupant_name' => '',
        ]);
    }

    /**
     * "Kat belirtilmemiş" is a label the API synthesises for rows with no
     * floor — and also a literal value the upstream 360 directory stores.
     * `floors()` listed it, `rooms()` read it as "floor IS NULL", and every
     * room in AGE OF BRONZE and ETERNAL SPRING 404'd from a floor the app
     * had just been handed.
     */
    public function test_rooms_filed_under_the_literal_unspecified_floor_are_reachable(): void
    {
        $this->actingAsUser();
        $this->entry('AGE OF BRONZE', 'Kat belirtilmemiş', 'AB SAR01 Art Studio 1');
        $this->entry('AGE OF BRONZE', 'Kat belirtilmemiş', 'AB SSC01 Sculpture Studio');

        $floors = $this->getJson('/api/v1/directory/buildings/'.rawurlencode('AGE OF BRONZE').'/floors')
            ->assertOk()->json('data');
        $this->assertCount(1, $floors);

        $this->getJson('/api/v1/directory/buildings/'.rawurlencode('AGE OF BRONZE')
            .'/floors/'.rawurlencode($floors[0]['id']).'/rooms')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /** Rows with a genuinely empty floor land in the same bucket. */
    public function test_null_and_literal_unspecified_floors_are_one_floor(): void
    {
        $this->actingAsUser();
        $this->entry('IRIS', null, 'IR OFF01');
        $this->entry('IRIS', '', 'IR OFF02');
        $this->entry('IRIS', 'Kat belirtilmemiş', 'IR OFF03');

        $floors = $this->getJson('/api/v1/directory/buildings/IRIS/floors')
            ->assertOk()->json('data');

        $this->assertCount(1, $floors, 'The same floor was listed more than once.');
        $this->assertSame(3, $floors[0]['entryCount']);
    }

    /**
     * The 360 sync writes upstream names in upper case while older local
     * rows kept title case, so Titan, Minotaur, Meditation and Eternal
     * Idol each appeared twice with their rooms split between the two.
     */
    public function test_buildings_differing_only_in_case_are_one_building(): void
    {
        $this->actingAsUser();
        $this->entry('TITAN', 'Zemin', 'T Z01');
        $this->entry('TITAN', 'Zemin', 'T Z02');
        $this->entry('Titan', 'Zemin', 'T Z03');

        $buildings = $this->getJson('/api/v1/directory/buildings')->assertOk()->json('data');

        $this->assertCount(1, $buildings, 'Casing produced a duplicate building.');
        $this->assertSame(3, $buildings[0]['entryCount'],
            'Rooms were split across the casing variants.');
    }

    /**
     * `buildings()` hands back an upper-case id, so the two endpoints that
     * take it must resolve it against however the rows are actually spelled.
     */
    public function test_the_id_from_the_buildings_list_resolves_to_floors_and_rooms(): void
    {
        $this->actingAsUser();
        $this->entry('Minotaur', 'Kat 1', 'M 101');
        $this->entry('MINOTAUR', 'Kat 1', 'M 102');

        $id = $this->getJson('/api/v1/directory/buildings')->json('data.0.id');

        $floors = $this->getJson('/api/v1/directory/buildings/'.rawurlencode($id).'/floors')
            ->assertOk()->json('data');
        $this->assertCount(1, $floors);

        $this->getJson('/api/v1/directory/buildings/'.rawurlencode($id)
            .'/floors/'.rawurlencode($floors[0]['id']).'/rooms')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /** A building nobody has heard of is still a 404. */
    public function test_an_unknown_building_is_not_found(): void
    {
        $this->actingAsUser();
        $this->entry('IRIS', 'Kat 1', 'IR 101');

        $this->getJson('/api/v1/directory/buildings/NOPE/floors')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'BUILDING_NOT_FOUND');
    }
}
