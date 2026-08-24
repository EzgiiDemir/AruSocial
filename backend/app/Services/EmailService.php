<?php

namespace App\Services;

use App\Models\EmailLog;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

// Every send attempt goes through Laravel's Mail facade and gets a row
// in email_logs. Local MAIL_MAILER=log writes to storage/logs; staging
// and production set MAIL_MAILER=smtp. Send is synchronous so the log
// status is known before the HTTP response (no separate mail queue).
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
            $error = self::redactSecrets($e->getMessage());
            // Additive observability only — this failure is already fully
            // handled below (email_logs row, status stays "failed", no
            // rethrow), so nothing about that behavior changes. The raw
            // (unredacted) $e is fine to pass on: config/sentry.php's
            // before_send strips the SMTP password/username before the
            // event leaves the process.
            \Sentry\captureException($e);
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
        if (! $log) {
            return null;
        }

        $status = 'sent';
        $error = null;
        try {
            Mail::to($log->to_email)->send($mailable);
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = self::redactSecrets($e->getMessage());
            \Sentry\captureException($e);
        }
        $log->update(['status' => $status, 'error' => $error, 'attempts' => $log->attempts + 1, 'sent_at' => now()]);

        return $log;
    }

    public static function redactSecrets(string $message): string
    {
        $secrets = array_filter([
            (string) config('mail.mailers.smtp.password'),
            (string) config('mail.mailers.smtp.username'),
        ], fn (string $value) => $value !== '' && strtolower($value) !== 'null');

        foreach ($secrets as $secret) {
            $message = str_replace($secret, '[redacted]', $message);
        }

        return $message;
    }
}
