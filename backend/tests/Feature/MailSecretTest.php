<?php

namespace Tests\Feature;

use App\Mail\BulkAnnouncementMail;
use App\Models\AdminAuditLog;
use App\Models\EmailLog;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailSecretTest extends TestCase
{
    use RefreshDatabase;

    public function test_smtp_password_is_not_stored_in_email_logs_or_api_or_audit(): void
    {
        config(['mail.mailers.smtp.password' => 'super-secret-smtp-pass']);
        config(['mail.mailers.smtp.username' => 'smtp-user']);

        $log = EmailService::send(
            'leak@arucad.edu.tr',
            'Konu',
            'bulk-announcement',
            new class extends Mailable
            {
                public function build(): self
                {
                    throw new \RuntimeException('AUTH failed for smtp-user password=super-secret-smtp-pass');
                }
            },
        );

        $this->assertSame('failed', $log->status);
        $this->assertStringNotContainsString('super-secret-smtp-pass', (string) $log->error);
        $this->assertStringNotContainsString('smtp-user', (string) $log->error);
        $this->assertStringContainsString('[redacted]', (string) $log->error);

        Mail::fake();
        $this->actingAsRole();
        $bulk = $this->postJson('/api/v1/admin/email/bulk', [
            'recipients' => ['ok@arucad.edu.tr'],
            'subject' => 'Gizli değil',
            'body' => 'gövde',
        ])->assertOk();

        $encoded = json_encode($bulk->json());
        $this->assertStringNotContainsString('super-secret-smtp-pass', (string) $encoded);

        $listed = $this->getJson('/api/v1/admin/email-logs')->assertOk()->json();
        $this->assertStringNotContainsString('super-secret-smtp-pass', (string) json_encode($listed));

        foreach (AdminAuditLog::all() as $row) {
            $this->assertStringNotContainsString('super-secret-smtp-pass', $row->target_label);
            $this->assertStringNotContainsString('super-secret-smtp-pass', $row->actor_name);
        }

        $this->assertFalse(
            EmailLog::query()->where('error', 'like', '%super-secret-smtp-pass%')->exists()
        );
    }
}
