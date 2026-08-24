<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_older_audit_rows_are_reachable_past_the_old_200_cap(): void
    {
        $this->actingAsRole();
        for ($i = 1; $i <= 210; $i++) {
            AdminAuditLog::create([
                'id' => 'audit-'.$i,
                'actor_name' => 'Admin',
                'action' => 'create',
                'target_type' => 'club',
                'target_label' => 'row '.$i,
                'at' => now()->subMinutes(210 - $i),
            ]);
        }

        $page1 = $this->getJson('/api/v1/admin/audit-log?page=1&perPage=50')->assertOk();
        $page5 = $this->getJson('/api/v1/admin/audit-log?page=5&perPage=50')->assertOk();

        $this->assertSame(210, $page1->json('meta.pagination.total'));
        $this->assertSame(5, $page1->json('meta.pagination.lastPage'));
        $this->assertCount(10, $page5->json('data'));
        $this->assertSame('row 1', $page5->json('data.9.targetLabel'));
        $this->assertSame('row 210', $page1->json('data.0.targetLabel'));
        $this->assertSame('Admin', $page1->json('data.0.actorName'));
    }

    public function test_a_student_still_cannot_read_the_audit_log(): void
    {
        $this->actingAsUser();
        $this->getJson('/api/v1/admin/audit-log?page=1')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }
}
