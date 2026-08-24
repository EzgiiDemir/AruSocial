<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditDuplicateTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_admin_mutation_creates_exactly_one_server_audit_row(): void
    {
        $this->actingAsRole();

        $this->postJson('/api/v1/admin/food-venues', [
            'id' => 'food-once',
            'name' => 'Once Cafe',
        ])->assertOk();

        $this->assertSame(1, AdminAuditLog::count());
        $this->assertSame('create', AdminAuditLog::first()->action);
    }
}
