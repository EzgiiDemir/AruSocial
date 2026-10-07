<?php

namespace App\Services;

use App\Mail\ApplicationStatusMail;
use App\Models\ApplicationStatusEvent;
use App\Models\CareerOpportunity;
use App\Models\Club;
use App\Models\ClubMember;
use App\Models\Event;
use App\Models\EventJoin;
use App\Models\EventParticipationType;
use App\Models\Notification as InboxNotification;
use App\Models\ParticipationApplication;
use App\Models\ServiceItem;
use App\Models\Sport;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

// The one, generic engine behind every "Katıl/Başvur" action app-wide.
// Two-stage lifecycle (see ParticipationApplication's status constants):
//
//   detail_form_pending  — Preview answers are in; a secure link to the
//                          category's Detail form was just emailed.
//   detail_form_submitted -> under_review — the responsible staff member
//                          now has both stages' answers to decide on.
//   revision_required    — sent back to the student with a note; the
//                          SAME detail_form_token stays valid so they can
//                          resubmit without losing their previous answers.
//   approved | rejected  — terminal. Only `approved` creates real
//                          participation (see applyApprovalSideEffects).
class ParticipationApplicationService
{
    public static function resolveResponsibleStaffId(string $targetType, string $targetId, ?string $requested = null): ?string
    {
        // Catalog assignment is the source of truth. A client-supplied
        // staff id is ignored so a student cannot route (or flood) an
        // application to an arbitrary inbox.
        unset($requested);

        $fromCatalog = match ($targetType) {
            'club' => Club::find($targetId)?->responsible_staff_id,
            'sport' => Sport::find($targetId)?->responsible_staff_id ?? null,
            'service', 'help' => self::serviceItem($targetId)?->responsible_staff_id ?? null,
            'career' => StaffProfile::where('id', 'staff-career')->where('active', true)->value('id')
                ?? StaffProfile::where('department', 'Career')->where('active', true)->value('id'),
            'event' => Event::find($targetId)?->responsible_staff_id
                ?? StaffProfile::where('id', 'staff-clubs')->where('active', true)->value('id'),
            default => null,
        };
        if ($fromCatalog) {
            return $fromCatalog;
        }

        // Fall back to an active department head if the student listed a department.
        return null;
    }

    public static function targetExists(string $type, string $id): bool
    {
        return match ($type) {
            'club' => Club::where('id', $id)->exists(),
            'sport' => Sport::where('id', $id)->exists(),
            'service', 'help' => self::serviceItem($id) !== null,
            'career' => CareerOpportunity::where('id', $id)->exists(),
            'community' => Club::where('id', $id)->exists(),
            'event' => Event::where('id', $id)->exists(),
            default => false,
        };
    }

    public static function targetLabel(ParticipationApplication $app): string
    {
        $name = match ($app->target_type) {
            'club', 'community' => Club::find($app->target_id)?->name,
            'sport' => Sport::find($app->target_id)?->name,
            'service', 'help' => self::serviceItem($app->target_id)?->title,
            'career' => CareerOpportunity::find($app->target_id)?->title,
            'event' => Event::find($app->target_id)?->title,
            default => null,
        };

        return $name ?: "{$app->target_type}:{$app->target_id}";
    }

    public static function canonicalTargetId(string $type, string $id): string
    {
        if (in_array($type, ['service', 'help'], true)) {
            return self::serviceItem($id)?->id ?? $id;
        }

        return $id;
    }

    /** @return list<string> */
    public static function targetIdAliases(string $type, string $id): array
    {
        $canonical = self::canonicalTargetId($type, $id);
        if (! in_array($type, ['service', 'help'], true)) {
            return array_values(array_unique([$id, $canonical]));
        }
        $key = str_starts_with($canonical, 'service-') ? substr($canonical, strlen('service-')) : $canonical;

        return array_values(array_unique([$id, $canonical, $key, 'service-'.$key]));
    }

    /** Catalog ids are unprefixed (`pdr`); older REST rows used `service-pdr`. */
    private static function serviceItem(string $id): ?ServiceItem
    {
        $key = str_starts_with($id, 'service-') ? substr($id, strlen('service-')) : $id;

        return ServiceItem::find($id)
            ?? ServiceItem::find($key)
            ?? ServiceItem::find('service-'.$key);
    }

    /**
     * Structured, student-visible audit trail — see docs/API_CONTRACT.md
     * §Applications. Separate from AuditLogger's flat admin-facing line.
     */
    public static function logStatusEvent(
        ParticipationApplication $app,
        ?string $fromStatus,
        string $toStatus,
        ?string $note,
        ?User $actor,
        ?string $actorLabel = null,
    ): void {
        ApplicationStatusEvent::create([
            'id' => 'evt-'.Str::uuid(),
            'application_id' => $app->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
            'actor_user_id' => $actor?->id,
            'actor_label' => $actorLabel ?? $actor?->name,
            'created_at' => now(),
        ]);
    }

    /**
     * Stage 1 complete: the Preview form was just submitted. Generates a
     * secure, single-use token for the Detail form and emails the real
     * working link — the app never invents a fake "your application was
     * received" without a real next step behind it.
     */
    public static function submitPreview(ParticipationApplication $app, User $student): void
    {
        $label = self::targetLabel($app);
        self::logStatusEvent($app, null, $app->status, 'Ön başvuru gönderildi.', $student, $student->name);

        InboxNotification::create([
            'id' => 'notif-'.Str::uuid(),
            'user_id' => $student->id,
            'actor_user_id' => $student->id,
            'kind' => 'application_preview_submitted',
            'title' => 'Ön başvurunuz alındı',
            'body' => "\"{$label}\" için ön başvurunuz alındı. Detaylı başvuru formu e-posta adresinize gönderildi.",
            'created_at' => now(),
        ]);

        $formUrl = self::detailFormUrl($app);
        self::email(
            $student->email,
            $student->name,
            $label,
            'Ön başvuru alındı',
            'Detaylı başvuru formunuz hazır',
            "Başvuru No: {$app->id}\n\nÖn başvurunuz alındı. Başvurunuzun devam edebilmesi için aşağıdaki linkten detaylı formu doldurmanız gerekiyor. Bu link yalnızca size özeldir, kimseyle paylaşmayın.",
            $formUrl,
            $app->id,
        );

        $staff = $app->responsible_staff_id ? StaffProfile::find($app->responsible_staff_id) : null;
        if ($staff?->email) {
            self::email($staff->email, $staff->name, $label, 'Yeni ön başvuru', 'Yeni ön başvuru alındı', "{$student->name} \"{$label}\" için ön başvuru gönderdi — detaylı formu tamamladığında panelinize düşecek.", null, $app->id);
        }
    }

    /** The real, working link behind every application email — see docs/API_CONTRACT.md's Applications section. Token-gated, no login required (a student may open this from any device). */
    public static function detailFormUrl(ParticipationApplication $app): string
    {
        return rtrim((string) Config::get('app.url'), '/').'/forms/application/'.$app->detail_form_token;
    }

    /** Stage 2: detail answers are in; the application is now reviewable in the unit/admin panel. */
    public static function submitDetailForm(ParticipationApplication $app, array $answers, User $student): void
    {
        $from = $app->status;
        $app->update([
            'detail_payload' => $answers,
            'detail_form_submitted_at' => now(),
            'status' => ParticipationApplication::STATUS_UNDER_REVIEW,
        ]);
        self::logStatusEvent($app, $from, $app->status, 'Detaylı form gönderildi.', $student, $student->name);
        self::notifyDetailFormSubmitted($app->fresh(['responsibleStaff']), $student);
    }

    /** Stage 2 complete: notifies the responsible staff that a real, reviewable application is waiting — this is the moment it actually lands in their panel. */
    public static function notifyDetailFormSubmitted(ParticipationApplication $app, User $student): void
    {
        $label = self::targetLabel($app);
        InboxNotification::create([
            'id' => 'notif-'.Str::uuid(),
            'user_id' => $student->id,
            'actor_user_id' => $student->id,
            'kind' => 'application_under_review',
            'title' => 'Başvurunuz inceleniyor',
            'body' => "\"{$label}\" için detaylı formunuz alındı ve inceleme sürecine alındı.",
            'created_at' => now(),
        ]);

        $staff = $app->responsible_staff_id ? StaffProfile::find($app->responsible_staff_id) : null;
        if ($staff?->user_id) {
            $owner = User::find($staff->user_id);
            if ($owner) {
                InboxNotification::notify($owner, $student, 'application_received', 'Detaylı başvuru tamamlandı', "{$student->name} → {$label}");
            }
        }
        if ($staff?->email) {
            self::email($staff->email, $staff->name, $label, 'İncelemeye hazır', 'Detaylı başvuru tamamlandı', "{$student->name} \"{$label}\" için detaylı formu tamamladı. Panelinizden inceleyebilirsiniz.", null, $app->id);
        }
    }

    public static function notifyDecision(ParticipationApplication $app, User $actor, string $statusLabel, string $note = ''): void
    {
        $student = User::find($app->user_id);
        if (! $student) {
            return;
        }
        $label = self::targetLabel($app);
        [$kind, $title, $defaultNote] = match ($app->status) {
            ParticipationApplication::STATUS_APPROVED => ['application_approved', 'Başvurunuz onaylandı', 'Başvurunuz onaylanmıştır — artık gerçek anlamda katılımcısınız.'],
            ParticipationApplication::STATUS_REVISION_REQUIRED => ['application_revision_requested', 'Başvurunuzda revizyon isteniyor', 'Başvurunuzu güncelleyip tekrar gönderebilirsiniz.'],
            default => ['application_rejected', 'Başvurunuz reddedildi', 'Başvurunuz reddedilmiştir.'],
        };
        $bodyNote = $note !== '' ? $note : $defaultNote;
        InboxNotification::notify($student, $actor, $kind, $title, $note !== '' ? "{$label}: {$note}" : $label);

        $formUrl = $app->status === ParticipationApplication::STATUS_REVISION_REQUIRED
            ? self::detailFormUrl($app)
            : '';
        self::email($student->email, $student->name, $label, $statusLabel, $title, $bodyNote, $formUrl, $app->id);
    }

    /** Only ever called on real APPROVED — this is what "gerçek katılım" means for target types with a dedicated membership table. Types with no such table (sport/service/career/help) treat the application's own `approved` status as the participation record — already how getMyApplications()-driven "Katıldın" UI reads it. */
    public static function applyApprovalSideEffects(ParticipationApplication $app): void
    {
        if (in_array($app->target_type, ['club', 'community'], true)) {
            ClubMember::firstOrCreate(
                ['user_id' => $app->user_id, 'club_id' => $app->target_id],
                ['created_at' => now()],
            );
        }
        if ($app->target_type === 'event') {
            $event = Event::find($app->target_id);
            if (! $event) {
                return;
            }
            $ptype = $app->form_payload['participationTypeId'] ?? null;
            if (! is_string($ptype) || $ptype === '') {
                $ptype = null;
            } elseif (! EventParticipationType::where('id', $ptype)->where('event_id', $app->target_id)->exists()) {
                $ptype = null;
            }
            $existing = EventJoin::where('event_id', $app->target_id)
                ->where('user_id', $app->user_id)
                ->first();
            if ($existing) {
                if ($ptype && ! $existing->participation_type_id) {
                    $existing->update(['participation_type_id' => $ptype]);
                }
                EventAttendance::approve($existing, $event);
            } else {
                $join = EventJoin::create([
                    'id' => 'join-'.Str::uuid(),
                    'event_id' => $app->target_id,
                    'user_id' => $app->user_id,
                    'participation_type_id' => $ptype,
                    'joined_at' => now(),
                ]);
                EventAttendance::approve($join, $event);
            }
        }
    }

    private static function email(
        ?string $to,
        string $name,
        string $label,
        string $status,
        string $subject,
        string $note,
        ?string $formUrl = null,
        ?string $applicationId = null,
    ): void {
        if (! $to) {
            return;
        }
        EmailService::send(
            $to,
            $subject,
            'application-status',
            new ApplicationStatusMail(
                subjectLine: $subject,
                recipientName: $name,
                targetLabel: $label,
                statusLabel: $status,
                note: $note,
                appUrl: $formUrl ?: rtrim((string) Config::get('app.url'), '/'),
            ),
            $applicationId,
        );
    }
}
