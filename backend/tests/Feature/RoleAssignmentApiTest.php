<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAssignmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigning_a_role_persists_and_is_readable_back(): void
    {
        $this->actingAsAdmin();
        $assign = $this->postJson('/api/v1/admin/roles', [
            'email' => 'baskan@arucad.edu.tr', 'role' => 'clubManager', 'assignedBy' => 'admin',
        ]);
        $assign->assertOk();

        $fetch = $this->getJson('/api/v1/admin/roles/baskan@arucad.edu.tr');
        $fetch->assertOk();
        $this->assertEquals('clubManager', $fetch->json('data.role'));
    }

    public function test_rejects_a_role_not_in_the_real_userrole_enum(): void
    {
        $this->actingAsAdmin();
        $response = $this->postJson('/api/v1/admin/roles', [
            'email' => 'x@arucad.edu.tr', 'role' => 'not_a_real_role',
        ]);

        $response->assertStatus(400);
    }

    public function test_unassigned_email_has_no_role(): void
    {
        // roleFor() is deliberately reachable by any signed-in user, not
        // just admins — every account needs to check its own role right
        // after login (see routes/api.php's comment on this route).
        $this->actingAsUser();
        $response = $this->getJson('/api/v1/admin/roles/nobody@arucad.edu.tr');

        $response->assertOk();
        $this->assertNull($response->json('data.role'));
    }
}
