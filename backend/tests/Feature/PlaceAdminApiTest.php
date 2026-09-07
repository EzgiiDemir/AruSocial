<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Events\CampusDataChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Tests\TestCase;

class PlaceAdminApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole();
    }

    public function test_admin_can_create_update_and_delete_a_place(): void
    {
        EventFacade::fake([CampusDataChanged::class]);
        $create = $this->postJson('/api/v1/admin/places', [
            'id' => 'place-1', 'name' => 'Test Place', 'category' => 'Social',
            'lat' => 35.3, 'lng' => 33.3,
        ]);
        $create->assertStatus(201);
        $this->assertDatabaseHas('places', ['id' => 'place-1', 'name' => 'Test Place']);
        EventFacade::assertDispatched(CampusDataChanged::class, fn (CampusDataChanged $change) =>
            $change->resources === ['places'] && $change->action === 'created' && $change->id === 'place-1'
        );

        $this->getJson('/api/v1/places')->assertOk()->assertJsonFragment(['id' => 'place-1']);

        $update = $this->postJson('/api/v1/admin/places', [
            'id' => 'place-1', 'name' => 'Renamed Place', 'category' => 'Social',
            'lat' => 35.3, 'lng' => 33.3,
        ]);
        $update->assertOk();
        $this->assertDatabaseHas('places', ['id' => 'place-1', 'name' => 'Renamed Place']);

        $this->postJson('/api/v1/admin/places/place-1/delete')->assertOk();
        $this->assertDatabaseMissing('places', ['id' => 'place-1']);
        EventFacade::assertDispatched(CampusDataChanged::class, fn (CampusDataChanged $change) =>
            $change->resources === ['places'] && $change->action === 'deleted' && $change->id === 'place-1'
        );
    }

    public function test_place_upsert_requires_name_lat_lng(): void
    {
        $response = $this->postJson('/api/v1/admin/places', ['id' => 'place-1']);

        $response->assertStatus(400);
        $this->assertEquals('VALIDATION', $response->json('error.code'));
    }

    public function test_place_upsert_rejects_an_unlisted_category(): void
    {
        $response = $this->postJson('/api/v1/admin/places', [
            'id' => 'place-1', 'name' => 'Test Place', 'category' => 'Not A Real Category',
            'lat' => 35.3, 'lng' => 33.3,
        ]);

        $response->assertStatus(400);
    }

    public function test_places_manage_permission_is_required(): void
    {
        $this->actingAsRole('student');

        $this->postJson('/api/v1/admin/places', [
            'id' => 'place-1', 'name' => 'Test Place', 'lat' => 35.3, 'lng' => 33.3,
        ])->assertStatus(403);
    }

    public function test_every_admin_write_is_recorded_in_the_audit_log(): void
    {
        Place::create(['id' => 'place-1', 'name' => 'Existing', 'category' => 'Social', 'lat' => 1, 'lng' => 1]);

        $this->postJson('/api/v1/admin/places', [
            'id' => 'place-1', 'name' => 'Renamed', 'category' => 'Social', 'lat' => 1, 'lng' => 1,
        ]);

        $this->assertDatabaseHas('admin_audit_log', ['target_type' => 'place', 'action' => 'update']);
    }
}
