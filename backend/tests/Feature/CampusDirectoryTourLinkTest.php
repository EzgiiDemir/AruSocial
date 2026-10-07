<?php

namespace Tests\Feature;

use App\Models\DirectoryEntry;
use App\Models\Place;
use App\Models\ServiceItem;
use App\Services\CampusDirectory360Sync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Binding each campus pin to *its own* 360 panorama.
 *
 * The 3DVista tours are deep-linkable: `index.htm?media-name=MC_EXT_56`
 * opens one specific panorama, while a bare `index.htm` always opens the
 * campus entrance. Every Place used to store the bare form, so tapping
 * "360 Tour" on the student office showed the front gate — the tour opened,
 * which is why it never looked like a bug, it was just always the wrong room.
 */
class CampusDirectoryTourLinkTest extends TestCase
{
    use RefreshDatabase;

    private function fakeDirectory(): void
    {
        config([
            'services.campus_directory.api_key' => 'test-key',
            'services.campus_directory.base_url' => 'https://360.arucad.edu.tr',
        ]);

        Http::fake(['360.arucad.edu.tr/*' => Http::response([
            'version' => 1,
            'generatedAt' => '2026-09-08T06:53:36.500Z',
            'campuses' => [['id' => 'main', 'name' => ['tr' => 'ARUCAD Kampüs']]],
            'buildings' => [
                // No tour of its own — must fall back to a room's deep link.
                ['id' => 'B1', 'name' => ['tr' => 'TITAN'], 'navigation' => ['tourUrl' => null]],
                ['id' => 'B2', 'name' => ['tr' => 'RODIN'], 'navigation' => ['tourUrl' => null]],
            ],
            'categories' => [],
            'rooms' => [
                [
                    'id' => 'R1',
                    'name' => ['tr' => 'MG Öğrenci İşleri'],
                    'building' => ['id' => 'B1', 'name' => ['tr' => 'TITAN']],
                    'category' => ['name' => ['tr' => 'OFİS']],
                    'navigation' => [
                        'tourUrl' => '/vista_export/Main/index.htm?media-name=MG_INT_1#media-name=MG_INT_1',
                        'tourTarget' => 'panorama_TITAN_1',
                    ],
                ],
                [
                    'id' => 'R2',
                    'name' => ['tr' => 'RO OFF01 Rektörlük'],
                    'building' => ['id' => 'B2', 'name' => ['tr' => 'RODIN']],
                    'category' => ['name' => ['tr' => 'OFİS']],
                    'navigation' => [
                        'tourUrl' => '/vista_export/Main/index.htm?media-name=MC_EXT_56#media-name=MC_EXT_56',
                        'tourTarget' => 'panorama_RODIN_1',
                    ],
                ],
            ],
            'people' => [],
        ])]);
    }

    private function place(string $id, string $name, ?string $tourUrl): Place
    {
        return Place::create([
            'id' => $id,
            'name' => $name,
            'category' => 'Campus building',
            'lat' => 35.34,
            'lng' => 33.31,
            'description' => '',
            'distance' => '',
            'density' => 'quiet',
            'tour_url' => $tourUrl,
            'accessible' => true,
            'photos' => 0,
            'rating' => 0,
        ]);
    }

    public function test_a_generic_tour_url_is_upgraded_to_the_places_own_panorama(): void
    {
        $this->fakeDirectory();
        // The state every pin was in: a real tour, but the default view.
        $titan = $this->place('titan', 'Titan', 'https://360.arucad.edu.tr/vista_export/Main/index.htm');

        (new CampusDirectory360Sync)->sync();

        $this->assertStringContainsString(
            'media-name=MG_INT_1',
            (string) $titan->fresh()->tour_url,
            'Titan should open its own panorama, not the campus entrance.',
        );
    }

    /**
     * The catalogue really does hold two rows for one building (a curated
     * pin plus a legacy `place-*` seed). Updating only the first left the
     * other on the entrance panorama, and which one a student tapped was
     * luck.
     */
    public function test_every_duplicate_of_a_building_gets_the_tour_not_just_the_first(): void
    {
        $this->fakeDirectory();
        $curated = $this->place('rodin', 'Rodin', null);
        $legacy = $this->place('place-rodin', 'Rodin', null);

        (new CampusDirectory360Sync)->sync();

        foreach ([$curated, $legacy] as $place) {
            $this->assertStringContainsString(
                'media-name=MC_EXT_56',
                (string) $place->fresh()->tour_url,
                "Duplicate place {$place->id} was left pointing at the wrong panorama.",
            );
        }
    }

    public function test_an_existing_deep_link_is_never_downgraded(): void
    {
        $this->fakeDirectory();
        $curated = 'https://360.arucad.edu.tr/vista_export/Main/index.htm?media-name=CURATED#media-name=CURATED';
        $place = $this->place('titan', 'Titan', $curated);

        (new CampusDirectory360Sync)->sync();

        // The building pass may rebind to the room tour, but the result must
        // always still be a deep link — never a bare index.htm.
        $after = (string) $place->fresh()->tour_url;
        $this->assertTrue(
            str_contains($after, 'media-name=') || str_contains($after, 'media-index='),
            "Tour URL was downgraded to a non-deep link: {$after}",
        );
    }

    public function test_every_room_is_listed_with_its_own_tour_for_the_directory_screen(): void
    {
        $this->fakeDirectory();

        $summary = (new CampusDirectory360Sync)->sync();

        $this->assertSame(2, $summary['roomsSynced']);
        $this->assertSame(2, $summary['roomsWithTours']);

        // Each room keeps its own panorama, so picking a room in the
        // building directory opens that room rather than the building.
        $office = DirectoryEntry::find('360-R1');
        $this->assertNotNull($office);
        $this->assertStringContainsString('media-name=MG_INT_1', (string) $office->tour_url);
        $this->assertSame('panorama_TITAN_1', $office->tour_target);
    }

    public function test_student_affairs_service_is_pinned_to_its_exact_room_scene(): void
    {
        $this->fakeDirectory();
        ServiceItem::create([
            'id' => 'student-affairs',
            'title' => 'Öğrenci İşleri',
            'category' => 'İdari',
            'description' => '',
            'contact' => '',
        ]);
        DirectoryEntry::create([
            'id' => 'dir-student-affairs',
            'building' => 'TITAN',
            'floor' => 'Zemin Kat',
            'room' => 'Öğrenci İşleri',
            'occupant_name' => 'Öğrenci İşleri',
            'related_service_id' => 'student-affairs',
        ]);

        $summary = (new CampusDirectory360Sync)->sync();
        $service = DirectoryEntry::findOrFail('dir-student-affairs');

        $this->assertSame(1, $summary['serviceLinksUpdated']);
        $this->assertStringContainsString('media-name=MG_INT_1', (string) $service->tour_url);
        $this->assertSame('panorama_TITAN_1', $service->tour_target);
        $this->assertSame('OFİS', $service->category_name);
        $this->assertNotNull($service->directory_synced_at);
    }
}
