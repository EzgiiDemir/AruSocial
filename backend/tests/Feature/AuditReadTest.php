<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authorized_admin_reads_the_server_audit_log(): void
    {
        $admin = $this->actingAsRole();
        $this->postJson('/api/v1/admin/food-venues', [
            'id' => 'food-read',
            'name' => 'Read Cafe',
        ])->assertOk();

        $response = $this->getJson('/api/v1/admin/audit-log')->assertOk();
        $row = collect($response->json('data'))->firstWhere('targetLabel', 'Read Cafe');

        $this->assertNotNull($row);
        $this->assertSame($admin->name, $row['actorName']);
        $this->assertSame('create', $row['action']);
        $this->assertSame('food_venue', $row['targetType']);
        $this->assertArrayHasKey('id', $row);
        $this->assertArrayHasKey('at', $row);
    }

    public function test_a_student_cannot_read_the_audit_log(): void
    {
        $this->actingAsUser();
        $this->getJson('/api/v1/admin/audit-log')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_unauthenticated_audit_log_access_is_401(): void
    {
        $this->getJson('/api/v1/admin/audit-log')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
    }
}
