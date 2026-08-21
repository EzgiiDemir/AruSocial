<?php

namespace Tests\Feature;

use App\Models\Club;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClubApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_delete_a_club(): void
    {
        $create = $this->postJson('/api/v1/admin/clubs', [
            'id' => 'club-1', 'name' => 'Test Club', 'category' => 'Sanat',
        ]);
        $create->assertOk();
        $this->assertDatabaseHas('clubs', ['id' => 'club-1', 'name' => 'Test Club']);

        $this->getJson('/api/v1/clubs')->assertOk()->assertJsonFragment(['id' => 'club-1']);

        $this->postJson('/api/v1/admin/clubs/club-1/delete')->assertOk();
        $this->assertDatabaseMissing('clubs', ['id' => 'club-1']);
    }

    public function test_club_upsert_requires_name(): void
    {
        $response = $this->postJson('/api/v1/admin/clubs', ['id' => 'club-1']);

        $response->assertStatus(400);
        $this->assertEquals('VALIDATION', $response->json('error.code'));
    }

    public function test_every_admin_write_is_recorded_in_the_audit_log(): void
    {
        Club::create(['id' => 'club-1', 'name' => 'Existing', 'category' => 'Sanat']);

        $this->postJson('/api/v1/admin/clubs', ['id' => 'club-1', 'name' => 'Renamed', 'category' => 'Sanat']);

        $this->assertDatabaseHas('admin_audit_log', ['target_type' => 'club', 'action' => 'update']);
    }
}
