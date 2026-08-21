<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Mail\BulkAnnouncementMail;
use App\Models\EmailLog;
use App\Services\AuditLogger;
use App\Services\EmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailController extends Controller
{
    use ApiResponds;

    public function logs(): JsonResponse
    {
        $rows = EmailLog::orderByDesc('sent_at')->limit(200)->get();

        return $this->ok($rows->map(fn ($l) => [
            'id' => $l->id,
            'toEmail' => $l->to_email,
            'subject' => $l->subject,
            'template' => $l->template,
            'status' => $l->status,
            'error' => $l->error,
            'attempts' => $l->attempts,
            'sentAt' => $l->sent_at?->toIso8601String(),
        ]));
    }

    public function retry(string $id): JsonResponse
    {
        $log = EmailLog::find($id);
        if (! $log) return $this->fail(404, 'EMAIL_NOT_FOUND', 'Email log entry not found.');

        $mail = new BulkAnnouncementMail($log->subject, "(retry) {$log->subject}");
        $updated = EmailService::retry($id, $mail);

        return $this->ok(['status' => $updated?->status]);
    }

    // Toplu e-posta — kulüp/etkinlik duyurusu, ayrı ayrı ya da tüm listeye
    // (docs/EKSIKLER.md §10/§6).
    public function bulk(Request $request): JsonResponse
    {
        $recipients = (array) $request->input('recipients', []);
        $subject = $request->input('subject');
        $body = $request->input('body');
        if (empty($recipients) || ! $subject || ! $body) {
            return $this->fail(400, 'VALIDATION', 'recipients, subject and body are required.');
        }

        $results = [];
        foreach ($recipients as $email) {
            $mail = new BulkAnnouncementMail($subject, $body);
            $log = EmailService::send($email, $subject, 'bulk-announcement', $mail);
            $results[] = ['email' => $email, 'status' => $log->status];
        }
        AuditLogger::log(
            $this->currentUser()->name,
            'send_email',
            'bulk_announcement',
            "$subject → ".count($recipients).' alıcı'
        );

        return $this->ok(['sent' => count($results), 'results' => $results]);
    }
}
