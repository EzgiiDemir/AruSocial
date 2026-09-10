<?php

namespace App\Services\Moderation\Workflow;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Tells a student what happened to their content.
 *
 * Every moderation decision was silent outside the immediate API
 * response. A post removed an hour later, an appeal decided the next
 * morning, a warning recorded against an account — none of it reached the
 * person it was about. Enforcement nobody is told about is
 * indistinguishable from the app being broken, and it is the fastest way
 * to make someone assume they were treated arbitrarily.
 *
 * Two rules the copy here follows:
 *
 *  - Never expose a model score or an internal category. "Removed for
 *    sexual content" is something a person can understand and contest;
 *    "nsfw = 0.9341" invites an argument about the number instead of the
 *    behaviour, and tells anyone probing the system exactly where the
 *    threshold sits.
 *  - Say what happens next. A notice with no next step is just bad news.
 */
final class ModerationNotifier
{
    /** Content was taken down or refused. */
    public function contentRemoved(User $author, string $contentType, ?string $caseId): void
    {
        $this->send(
            $author,
            'moderation_removed',
            'İçeriğin kaldırıldı',
            $this->label($contentType).' topluluk kurallarına aykırı olduğu için kaldırıldı. '
                .'Kararın yanlış olduğunu düşünüyorsan itiraz edebilirsin.',
            ['caseId' => $caseId, 'appealable' => $caseId !== null],
        );
    }

    /** Held while a person looks at it. */
    public function contentUnderReview(User $author, string $contentType, ?string $caseId): void
    {
        $this->send(
            $author,
            'moderation_review',
            'İçeriğin inceleniyor',
            $this->label($contentType).' bir ekip üyesi tarafından inceleniyor. '
                .'Sonuçlandığında sana haber vereceğiz.',
            ['caseId' => $caseId],
        );
    }

    /**
     * The check itself failed.
     *
     * Worth its own message: this is our fault, not theirs, and being
     * told "under review" for an outage makes people think they did
     * something wrong.
     */
    public function moderationUnavailable(User $author, string $contentType): void
    {
        $this->send(
            $author,
            'moderation_error',
            'Kontrol tamamlanamadı',
            $this->label($contentType).' kontrol edilemedi — bu senin hatan değil. '
                .'Kontrol tekrar denenecek, içeriğin güvende.',
        );
    }

    public function accountWarned(User $user, string $action): void
    {
        [$title, $body] = match ($action) {
            'warning' => ['Uyarı aldın',
                'Paylaştığın bir içerik topluluk kurallarına aykırıydı. '
                .'Tekrarlanması durumunda hesabına kısıtlama gelebilir.'],
            'posting_restriction' => ['Paylaşımın geçici olarak kısıtlandı',
                'Bir süre yeni içerik paylaşamayacaksın. Kısıtlama otomatik olarak kalkacak.'],
            'temporary_suspension' => ['Hesabın geçici olarak askıya alındı',
                'Bu süre boyunca içerik paylaşamazsın. Süre dolduğunda hesabın kendiliğinden açılır.'],
            default => ['Hesabınla ilgili bir karar alındı',
                'Ayrıntılar için bildirimlerini kontrol et.'],
        };

        $this->send($user, 'moderation_penalty', $title, $body, ['action' => $action]);
    }

    public function appealDecided(User $user, bool $overturned, ?string $note): void
    {
        $this->send(
            $user,
            $overturned ? 'appeal_accepted' : 'appeal_rejected',
            $overturned ? 'İtirazın kabul edildi' : 'İtirazın reddedildi',
            $overturned
                ? 'İçeriğin geri yüklendi ve bu ihlal kaydından silindi.'
                    .($note === null ? '' : ' Not: '.$note)
                : 'İnceleme sonucunda ilk karar korundu.'
                    .($note === null ? '' : ' Not: '.$note),
            ['overturned' => $overturned],
        );
    }

    private function label(string $contentType): string
    {
        return match ($contentType) {
            'post' => 'Gönderin',
            'comment' => 'Yorumun',
            'story' => 'Hikayen',
            'image', 'media' => 'Görselin',
            'video' => 'Videon',
            default => 'İçeriğin',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(
        User $recipient,
        string $kind,
        string $title,
        string $body,
        array $data = [],
    ): void {
        try {
            // Written directly rather than through Notification::notify(),
            // which requires an actor and skips self-notification. A
            // moderation decision has no acting *user* — and even when a
            // moderator made it, naming them invites the student to take
            // it up with that individual rather than through appeals.
            Notification::create([
                'id' => 'notif-'.Str::uuid(),
                'user_id' => $recipient->id,
                'actor_user_id' => null,
                'kind' => $kind,
                'title' => $title,
                'body' => $body,
                'data' => $data === [] ? null : $data,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // A failed notice must not undo the decision it describes,
            // but silence here means someone was penalised and never told.
            Log::error('moderation.notify_failed', [
                'kind' => $kind,
                'user' => $recipient->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
