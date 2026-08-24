<?php

namespace Tests\Feature;

use App\Mail\BulkAnnouncementMail;
use App\Models\EmailLog;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BulkEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sends_one_mail_and_log_per_recipient(): void
    {
        Mail::fake();
        $this->actingAsRole();

        $response = $this->postJson('/api/v1/admin/email/bulk', [
            'recipients' => ['a@arucad.edu.tr', 'b@arucad.edu.tr'],
            'subject' => 'Toplu',
            'body' => 'Merhaba',
        ])->assertOk();

        $this->assertSame(2, $response->json('data.sent'));
        $this->assertSame('sent', $response->json('data.results.0.status'));
        Mail::assertSent(BulkAnnouncementMail::class, 2);
        $this->assertSame(2, EmailLog::count());
        $this->assertDatabaseHas('admin_audit_log', [
            'action' => 'send_email',
            'target_type' => 'bulk_announcement',
        ]);
    }

    public function test_one_failed_send_does_not_drop_later_recipients(): void
    {
        $failed = EmailService::send(
            'bad@arucad.edu.tr',
            'X',
            'bulk-announcement',
            new class extends Mailable
            {
                public function build(): self
                {
                    throw new \RuntimeException('SMTP 550');
                }
            },
        );
        Mail::fake();
        $ok = EmailService::send(
            'good@arucad.edu.tr',
            'X',
            'bulk-announcement',
            new BulkAnnouncementMail('X', 'ok'),
        );

        $this->assertSame('failed', $failed->status);
        $this->assertSame('sent', $ok->status);
        $this->assertSame(2, EmailLog::count());
    }

    public function test_empty_recipients_are_rejected(): void
    {
        $this->actingAsRole();
        $this->postJson('/api/v1/admin/email/bulk', [
            'recipients' => [],
            'subject' => 'x',
            'body' => 'y',
        ])->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION');
        $this->assertSame(0, EmailLog::count());
    }

    public function test_student_cannot_send_bulk_email(): void
    {
        $this->actingAsUser();
        $this->postJson('/api/v1/admin/email/bulk', [
            'recipients' => ['a@arucad.edu.tr'],
            'subject' => 'x',
            'body' => 'y',
        ])->assertStatus(403);
        $this->assertSame(0, EmailLog::count());
    }

    public function test_duplicate_recipients_are_sent_twice_matching_existing_semantics(): void
    {
        Mail::fake();
        $this->actingAsRole();
        $this->postJson('/api/v1/admin/email/bulk', [
            'recipients' => ['same@arucad.edu.tr', 'same@arucad.edu.tr'],
            'subject' => 'Dup',
            'body' => 'x',
        ])->assertOk();

        Mail::assertSent(BulkAnnouncementMail::class, 2);
        $this->assertSame(2, EmailLog::where('to_email', 'same@arucad.edu.tr')->count());
    }
}
