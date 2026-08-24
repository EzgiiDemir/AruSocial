<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditActorTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_actor_is_the_authenticated_user_not_the_request_body(): void
    {
        $admin = $this->actingAsRole();

        $this->postJson('/api/v1/admin/food-venues', [
            'id' => 'food-spoof',
            'name' => 'Spoof Cafe',
            'actorName' => 'hacker',
            'assignedBy' => 'hacker',
            'adminName' => 'hacker',
        ])->assertOk();

        $row = AdminAuditLog::first();
        $this->assertNotNull($row);
        $this->assertSame($admin->name, $row->actor_name);
        $this->assertNotSame('hacker', $row->actor_name);
    }
}
