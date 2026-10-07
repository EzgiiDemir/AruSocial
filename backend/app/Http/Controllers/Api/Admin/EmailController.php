<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaginatedListRequest;
use App\Http\Requests\SendBulkEmailRequest;
use App\Mail\BulkAnnouncementMail;
use App\Models\EmailLog;
use App\Services\AuditLogger;
use App\Services\EmailService;
use Illuminate\Http\JsonResponse;

class EmailController extends Controller
{
    use ApiResponds;

    public function logs(PaginatedListRequest $request): JsonResponse
    {
        $query = EmailLog::query()->orderByDesc('sent_at')->orderByDesc('id');

        return $this->okPage($query, $request, fn ($l) => [
            'id' => $l->id,
            'toEmail' => $l->to_email,
            'subject' => $l->subject,
            'template' => $l->template,
            'status' => $l->status,
            'error' => $l->error,
            'attempts' => $l->attempts,
            'sentAt' => $l->sent_at?->toIso8601String(),
        ]);
    }

    public function retry(string $id): JsonResponse
    {
        $log = EmailLog::find($id);
        if (! $log) {
            return $this->fail(404, 'EMAIL_NOT_FOUND', 'Email log entry not found.');
        }

        $mail = new BulkAnnouncementMail($log->subject, "(retry) {$log->subject}");
        $updated = EmailService::retry($id, $mail);

        return $this->ok(['status' => $updated?->status]);
    }

    // Toplu e-posta — kulüp/etkinlik duyurusu, ayrı ayrı ya da tüm listeye
    // (docs/EKSIKLER.md §10/§6).
    public function bulk(SendBulkEmailRequest $request): JsonResponse
    {
        $recipients = (array) $request->input('recipients', []);
        $subject = $request->input('subject');
        $body = $request->input('body');

        $results = [];
        foreach ($recipients as $email) {
            $mail = new BulkAnnouncementMail($subject, $body);
            $log = EmailService::send($email, $subject, 'bulk-announcement', $mail);
            $results[] = ['email' => $email, 'status' => $log->status];
        }
        AuditLogger::logAsCurrentUser(
            'send_email',
            'bulk_announcement',
            "$subject → ".count($recipients).' alıcı'
        );

        return $this->ok(['sent' => count($results), 'results' => $results]);
    }
}
