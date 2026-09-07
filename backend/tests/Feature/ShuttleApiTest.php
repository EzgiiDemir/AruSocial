<?php

namespace Tests\Feature;

use App\Models\ShuttleRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Real replacement for the previously hardcoded `shuttleRoutes` const in
 * `shuttle_config.dart` — the only campus schedule with no backend table or
 * admin screen at all before this.
 */
class ShuttleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_shuttle_routes_list_starts_empty_and_reflects_created_routes(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/v1/shuttle-routes')->assertOk()->assertJson(['data' => []]);

        ShuttleRoute::create([
            'id' => 'nicosia', 'name' => 'Lefkoşa Servisi', 'color_key' => 'blue',
            'stops' => ['ARUCAD', 'Boğaz'], 'departures' => ['07:00', '11:00'],
        ]);

        $listed = $this->getJson('/api/v1/shuttle-routes')->assertOk()->json('data');
        $this->assertCount(1, $listed);
        $this->assertSame('Lefkoşa Servisi', $listed[0]['name']);
        $this->assertSame(['ARUCAD', 'Boğaz'], $listed[0]['stops']);
        $this->assertSame(['07:00', '11:00'], $listed[0]['departures']);
        $this->assertNull($listed[0]['returns']);
    }

    public function test_admin_can_create_update_and_delete_a_route(): void
    {
        $this->actingAsRole();

        $created = $this->postJson('/api/v1/admin/shuttle-routes', [
            'id' => 'bandabuliya', 'name' => 'Bandabuliya Servisi', 'colorKey' => 'warning',
            'stops' => ['ARUCAD', 'Bandabuliya'],
            'departures' => ['07:30', '10:00'],
            'returns' => ['09:00', '13:00'],
        ])->assertStatus(201)->json('data');
        $this->assertSame('warning', $created['colorKey']);
        $this->assertSame(['09:00', '13:00'], $created['returns']);

        $updated = $this->postJson('/api/v1/admin/shuttle-routes', [
            'id' => 'bandabuliya', 'name' => 'Bandabuliya Servisi (Güncel)', 'colorKey' => 'warning',
            'stops' => ['ARUCAD', 'Bandabuliya'],
            'departures' => ['08:00'],
        ])->assertOk()->json('data');
        $this->assertSame('Bandabuliya Servisi (Güncel)', $updated['name']);
        $this->assertNull($updated['returns']);

        $this->postJson('/api/v1/admin/shuttle-routes/bandabuliya/delete')->assertOk();
        $this->assertDatabaseMissing('shuttle_routes', ['id' => 'bandabuliya']);
    }

    public function test_route_write_requires_shuttle_manage_permission(): void
    {
        $this->actingAsRole('student');

        $this->postJson('/api/v1/admin/shuttle-routes', [
            'id' => 'x', 'name' => 'X', 'stops' => ['A', 'B'], 'departures' => ['08:00'],
        ])->assertStatus(403);
    }

    public function test_route_upsert_requires_stops_and_departures(): void
    {
        $this->actingAsRole();

        $this->postJson('/api/v1/admin/shuttle-routes', ['id' => 'x', 'name' => 'X'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION');
    }

    public function test_route_upsert_rejects_a_malformed_time(): void
    {
        $this->actingAsRole();

        $this->postJson('/api/v1/admin/shuttle-routes', [
            'id' => 'x', 'name' => 'X', 'stops' => ['A'], 'departures' => ['7am'],
        ])->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION');
    }

    public function test_route_upsert_rejects_an_unknown_color_key(): void
    {
        $this->actingAsRole();

        $this->postJson('/api/v1/admin/shuttle-routes', [
            'id' => 'x', 'name' => 'X', 'colorKey' => 'purple',
            'stops' => ['A'], 'departures' => ['08:00'],
        ])->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION');
    }
}
