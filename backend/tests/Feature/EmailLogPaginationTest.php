<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailLogPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_log_pages_cover_history_beyond_the_old_200_cap(): void
    {
        $this->actingAsRole();
        for ($i = 1; $i <= 210; $i++) {
            EmailLog::create([
                'id' => 'mail-'.$i,
                'to_email' => "user{$i}@arucad.edu.tr",
                'subject' => 'Subject '.$i,
                'template' => 'bulk-announcement',
                'status' => 'sent',
                'attempts' => 1,
                'sent_at' => now()->subMinutes(210 - $i),
            ]);
        }

        $page1 = $this->getJson('/api/v1/admin/email-logs?page=1&perPage=50')->assertOk();
        $page5 = $this->getJson('/api/v1/admin/email-logs?page=5&perPage=50')->assertOk();

        $this->assertSame(210, $page1->json('meta.pagination.total'));
        $this->assertCount(50, $page1->json('data'));
        $this->assertCount(10, $page5->json('data'));
        $this->assertSame('user210@arucad.edu.tr', $page1->json('data.0.toEmail'));
        $this->assertSame('user1@arucad.edu.tr', $page5->json('data.9.toEmail'));
        $ids = collect($page1->json('data'))->pluck('id')
            ->concat(collect($page5->json('data'))->pluck('id'));
        $this->assertCount($ids->count(), $ids->unique());
    }

    public function test_invalid_per_page_is_rejected(): void
    {
        $this->actingAsRole();
        $this->getJson('/api/v1/admin/email-logs?perPage=0')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION');
    }
}
