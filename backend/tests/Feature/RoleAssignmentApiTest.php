<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAssignmentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Reading and writing role assignments is the super-admin bucket.
        $this->actingAsRole();
    }

    public function test_assigning_a_role_persists_and_is_readable_back(): void
    {
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
        $response = $this->postJson('/api/v1/admin/roles', [
            'email' => 'x@arucad.edu.tr', 'role' => 'not_a_real_role',
        ]);

        $response->assertStatus(400);
    }

    public function test_unassigned_email_has_no_role(): void
    {
        $response = $this->getJson('/api/v1/admin/roles/nobody@arucad.edu.tr');

        $response->assertOk();
        $this->assertNull($response->json('data.role'));
    }

    public function test_last_super_admin_cannot_be_removed(): void
    {
        $this->postJson('/api/v1/admin/roles/superadmin@arucad.edu.tr/delete')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LAST_SUPER_ADMIN');

        $this->postJson('/api/v1/admin/roles', [
            'email' => 'superadmin@arucad.edu.tr', 'role' => 'student',
        ])->assertStatus(409)->assertJsonPath('error.code', 'LAST_SUPER_ADMIN');

        $this->postJson('/api/v1/admin/roles', [
            'email' => 'second.admin@arucad.edu.tr', 'role' => 'superAdmin',
        ])->assertOk();

        $this->postJson('/api/v1/admin/roles/second.admin@arucad.edu.tr/delete')->assertOk();
        $this->postJson('/api/v1/admin/roles/superadmin@arucad.edu.tr/delete')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LAST_SUPER_ADMIN');
    }
}
