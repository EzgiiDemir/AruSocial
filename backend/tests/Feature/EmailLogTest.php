<?php

namespace Tests\Feature;

use App\Mail\BulkAnnouncementMail;
use App\Models\EmailLog;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_send_writes_sent_without_error(): void
    {
        Mail::fake();
        $log = EmailService::send(
            'ok@arucad.edu.tr',
            'Konu',
            'bulk-announcement',
            new BulkAnnouncementMail('Konu', 'Gövde'),
        );

        $this->assertSame('sent', $log->status);
        $this->assertNull($log->error);
        $this->assertSame(1, $log->attempts);
        $this->assertNotNull($log->sent_at);
    }

    public function test_failed_send_writes_failed_and_keeps_the_row(): void
    {
        $log = EmailService::send(
            'fail@arucad.edu.tr',
            'Konu',
            'bulk-announcement',
            new class extends Mailable
            {
                public function build(): self
                {
                    throw new \RuntimeException('SMTP 550 mailbox unavailable');
                }
            },
        );

        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('SMTP 550', (string) $log->error);
        $this->assertSame(1, EmailLog::count());
    }

    public function test_retry_increments_attempts_and_can_succeed(): void
    {
        $failed = EmailService::send(
            'retry@arucad.edu.tr',
            'Konu',
            'bulk-announcement',
            new class extends Mailable
            {
                public function build(): self
                {
                    throw new \RuntimeException('temporary');
                }
            },
        );
        $this->assertSame('failed', $failed->status);

        Mail::fake();
        $retried = EmailService::retry($failed->id, new BulkAnnouncementMail('Konu', 'Gövde'));

        $this->assertSame('sent', $retried->status);
        $this->assertNull($retried->error);
        $this->assertSame(2, $retried->attempts);
        Mail::assertSent(BulkAnnouncementMail::class);
    }

    public function test_email_logs_endpoint_lists_sent_and_failed(): void
    {
        Mail::fake();
        $this->actingAsRole();
        EmailService::send('a@arucad.edu.tr', 'Ok', 'bulk-announcement', new BulkAnnouncementMail('Ok', 'x'));

        $page = $this->getJson('/api/v1/admin/email-logs')->assertOk()->json('data');
        $this->assertNotEmpty($page);
        $this->assertSame('sent', $page[0]['status']);
        $this->assertArrayNotHasKey('password', $page[0]);
    }
}
