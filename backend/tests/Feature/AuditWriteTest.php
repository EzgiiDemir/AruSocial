<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\MediaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuditWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_food_venue_write_creates_one_audit_row(): void
    {
        $admin = $this->actingAsRole();

        $this->postJson('/api/v1/admin/food-venues', [
            'id' => 'food-audit',
            'name' => 'Audit Cafe',
            'hours' => '09:00–17:00',
        ])->assertOk();

        $this->assertDatabaseHas('food_venues', ['id' => 'food-audit', 'name' => 'Audit Cafe']);
        $this->assertSame(1, AdminAuditLog::count());

        $row = AdminAuditLog::first();
        $this->assertSame($admin->name, $row->actor_name);
        $this->assertSame('create', $row->action);
        $this->assertSame('food_venue', $row->target_type);
        $this->assertSame('Audit Cafe', $row->target_label);
    }

    public function test_a_successful_media_rename_is_audited(): void
    {
        Storage::fake(MediaItem::disk());
        $this->actingAsRole();

        $created = $this->post('/api/v1/media', [
            'file' => $this->fakeJpeg('garden.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        AdminAuditLog::query()->delete();

        $this->postJson('/api/v1/media/'.$created['id'], ['fileName' => 'garden-cover.jpg'])
            ->assertOk();

        $this->assertSame(1, AdminAuditLog::count());
        $row = AdminAuditLog::first();
        $this->assertSame('update', $row->action);
        $this->assertSame('media', $row->target_type);
        $this->assertSame('garden-cover.jpg', $row->target_label);
    }
}
