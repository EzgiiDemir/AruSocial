<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_admin_writes_do_not_create_a_successful_audit_row(): void
    {
        $this->postJson('/api/v1/admin/food-venues', [
            'id' => 'food-unauth',
            'name' => 'No Token',
        ])->assertStatus(401)->assertJsonPath('error.code', 'AUTH_REQUIRED');
        $this->assertSame(0, AdminAuditLog::count());

        $this->actingAsUser();
        $this->postJson('/api/v1/admin/food-venues', [
            'id' => 'food-forbidden',
            'name' => 'Student Cafe',
        ])->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertSame(0, AdminAuditLog::count());

        $this->actingAsRole();
        $this->postJson('/api/v1/admin/food-venues', [
            'id' => 'food-invalid',
        ])->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION');
        $this->assertSame(0, AdminAuditLog::count());

        $this->postJson('/api/v1/admin/food-venues/missing-venue/menus', [
            'date' => '2026-08-23',
            'items' => ['Çorba'],
        ])->assertStatus(404)->assertJsonPath('error.code', 'FOOD_VENUE_NOT_FOUND');
        $this->assertSame(0, AdminAuditLog::count());
    }
}
