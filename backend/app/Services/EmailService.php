<?php

namespace App\Services;

use App\Models\EmailLog;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

// Every send attempt goes through Laravel's real Mail facade and gets a
// real row in email_logs — status/error reflect what the mailer actually
// returned. With no real SMTP configured (docs/EXTERNAL_ACCOUNTS.md §5),
// MAIL_MAILER=log means every "sent" mail genuinely goes through
// Laravel's mail pipeline and lands in storage/logs/laravel.log instead
// of a real inbox — real code path, honestly limited output until real
// credentials exist.
class EmailService
{
    public static function send(string $toEmail, string $subject, string $template, Mailable $mailable): EmailLog
    {
        $status = 'sent';
        $error = null;
        try {
            Mail::to($toEmail)->send($mailable);
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
        }

        return EmailLog::create([
            'id' => 'email-'.Str::uuid(),
            'to_email' => $toEmail,
            'subject' => $subject,
            'template' => $template,
            'status' => $status,
            'error' => $error,
            'attempts' => 1,
            'sent_at' => now(),
        ]);
    }

    public static function retry(string $emailLogId, Mailable $mailable): ?EmailLog
    {
        $log = EmailLog::find($emailLogId);
        if (! $log) return null;

        $status = 'sent';
        $error = null;
        try {
            Mail::to($log->to_email)->send($mailable);
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
        }
        $log->update(['status' => $status, 'error' => $error, 'attempts' => $log->attempts + 1, 'sent_at' => now()]);

        return $log;
    }
}
