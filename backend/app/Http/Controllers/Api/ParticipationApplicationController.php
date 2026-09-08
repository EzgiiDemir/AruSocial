<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Api\Concerns\ModeratesContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreParticipationApplicationRequest;
use App\Models\ClubMember;
use App\Models\ParticipationApplication;
use App\Services\AchievementEvaluator;
use App\Services\ApplicationQuestionService;
use App\Services\AuditLogger;
use App\Services\ParticipationApplicationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ParticipationApplicationController extends Controller
{
    use ApiResponds, ModeratesContent;

    public function mine(): JsonResponse
    {
        $me = $this->currentUser();
        $rows = ParticipationApplication::with(['responsibleStaff', 'user', 'emailLogs'])
            ->where('user_id', $me->id)
            ->orderByDesc('submitted_at')
            ->get();

        return $this->ok($rows->map(fn ($row) => $row->toApiArray(true)));
    }

    /** Full status history for one of the student's own applications — never another student's (404, not 403, to avoid confirming existence). */
    public function history(Request $request, string $id): JsonResponse
    {
        $me = $this->currentUser();
        $app = ParticipationApplication::where('id', $id)->where('user_id', $me->id)->first();
        if (! $app) {
            return $this->fail(404, 'APPLICATION_NOT_FOUND', 'Application not found.');
        }

        return $this->ok($app->statusEvents->map->toApiArray());
    }

    /**
     * Stage 1: Preview form submission. This is deliberately NOT
     * participation — it only ever produces `detail_form_pending` plus a
     * real, working emailed link to the category's Detail form. See
     * ParticipationApplicationService's class doc for the full lifecycle.
     */
    public function store(StoreParticipationApplicationRequest $request): JsonResponse
    {
        $me = $this->currentUser();
        $type = $request->input('targetType');
        $targetId = $request->input('targetId');

        if (! ParticipationApplicationService::targetExists($type, $targetId)) {
            return $this->fail(404, 'TARGET_NOT_FOUND', 'Bu kayıt sistemde yok — katalog henüz yüklenmemiş olabilir.');
        }
        $targetId = ParticipationApplicationService::canonicalTargetId($type, $targetId);
        $targetIds = ParticipationApplicationService::targetIdAliases($type, $targetId);

        if (in_array($type, ['club', 'community'], true)
            && ClubMember::where('user_id', $me->id)->where('club_id', $targetId)->exists()) {
            return $this->fail(409, 'ALREADY_MEMBER', 'Already a member of this club.');
        }

        $open = ParticipationApplication::where('user_id', $me->id)
            ->where('target_type', $type)
            ->whereIn('target_id', $targetIds)
            ->whereIn('status', ParticipationApplication::OPEN_STATUSES)
            ->exists();
        if ($open) {
            return $this->fail(409, 'ALREADY_APPLIED', 'You already have an application in progress for this target.');
        }
        if (ParticipationApplication::where('user_id', $me->id)->where('target_type', $type)->whereIn('target_id', $targetIds)->where('status', ParticipationApplication::STATUS_APPROVED)->exists()) {
            return $this->fail(409, 'ALREADY_APPROVED', 'You have already been approved for this target.');
        }

        // Preview is no longer a form the student fills in — Katıl/Başvur
        // applies immediately with no questions shown (product decision),
        // so there is nothing left to validate as "required but missing"
        // here. `formPayload` stays accepted (and stored) for callers that
        // still send one (e.g. an event's participation-type choice), but
        // an empty one is always valid. Required-answer enforcement still
        // applies at the Detail stage, which is a real form.
        $previewPayload = $request->input('formPayload', []);

        $staffId = ParticipationApplicationService::resolveResponsibleStaffId(
            $type,
            $targetId,
        );

        try {
            $app = ParticipationApplication::create([
                'id' => 'app-'.Str::uuid(),
                'user_id' => $me->id,
                'target_type' => $type,
                'target_id' => $targetId,
                'status' => ParticipationApplication::STATUS_DETAIL_FORM_PENDING,
                'responsible_staff_id' => $staffId,
                'form_payload' => $previewPayload,
                'detail_form_token' => Str::random(48),
                'submitted_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->fail(409, 'ALREADY_APPLIED', 'You already have an application in progress for this target.');
        }

        ParticipationApplicationService::submitPreview($app->fresh(['responsibleStaff']), $me);

        return $this->ok($app->fresh(['responsibleStaff', 'user', 'emailLogs'])->toApiArray(true), 201);
    }

    /** Stage 2 from inside the app — same outcome as the emailed token form. */
    public function submitDetail(Request $request, string $id): JsonResponse
    {
        $me = $this->currentUser();
        $app = ParticipationApplication::where('id', $id)->where('user_id', $me->id)->first();
        if (! $app) {
            return $this->fail(404, 'APPLICATION_NOT_FOUND', 'Application not found.');
        }
        if (! in_array($app->status, [
            ParticipationApplication::STATUS_DETAIL_FORM_PENDING,
            ParticipationApplication::STATUS_REVISION_REQUIRED,
        ], true)) {
            return $this->fail(409, 'INVALID_STATE', 'Detay formu bu durumda doldurulamaz.');
        }

        $payload = $request->input('formPayload', []);
        if (! is_array($payload)) {
            $payload = [];
        }
        $missing = ApplicationQuestionService::missingRequired($app->target_type, 'detail', $payload);
        if ($missing !== []) {
            return $this->fail(422, 'DETAIL_ANSWERS_INCOMPLETE', 'Zorunlu sorular eksik: '.implode(', ', $missing));
        }

        ParticipationApplicationService::submitDetailForm($app, $payload, $me);

        return $this->ok($app->fresh(['responsibleStaff', 'user', 'emailLogs'])->toApiArray(true));
    }

    /**
     * The student withdraws their own application.
     *
     * Only allowed while it is still in flight. Once a decision has been
     * made, cancelling would erase the outcome — an approval the student
     * later wants to leave is a different action (leaving the club), and a
     * rejection is a record that should not be removable by the person it
     * concerns. Both are refused here rather than silently rewritten.
     */
    public function cancel(string $id): JsonResponse
    {
        $me = $this->currentUser();
        $app = ParticipationApplication::where('id', $id)->where('user_id', $me->id)->first();
        if (! $app) {
            return $this->fail(404, 'APPLICATION_NOT_FOUND', 'Başvuru bulunamadı.');
        }

        $final = [
            ParticipationApplication::STATUS_APPROVED,
            ParticipationApplication::STATUS_REJECTED,
            ParticipationApplication::STATUS_CANCELLED,
        ];
        if (in_array($app->status, $final, true)) {
            return $this->fail(409, 'INVALID_STATE',
                'Sonuçlanmış bir başvuru iptal edilemez.');
        }

        $from = $app->status;
        $app->update(['status' => ParticipationApplication::STATUS_CANCELLED]);
        ParticipationApplicationService::logStatusEvent(
            $app, $from, $app->status, 'Öğrenci başvurusunu iptal etti.', $me,
        );

        return $this->ok($app->fresh(['responsibleStaff', 'user', 'emailLogs'])->toApiArray(true));
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $query = ParticipationApplication::with(['responsibleStaff', 'user', 'emailLogs'])
            ->orderByDesc('submitted_at');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        } else {
            $query->whereIn('status', [
                ParticipationApplication::STATUS_DETAIL_FORM_PENDING,
                ParticipationApplication::STATUS_DETAIL_FORM_SUBMITTED,
                ParticipationApplication::STATUS_UNDER_REVIEW,
                ParticipationApplication::STATUS_REVISION_REQUIRED,
            ]);
        }
        if ($type = $request->query('targetType')) {
            $query->where('target_type', $type);
        }

        return $this->ok($query->limit(200)->get()->map->toApiArray());
    }

    private function decidable(ParticipationApplication $app): bool
    {
        return in_array($app->status, [
            ParticipationApplication::STATUS_DETAIL_FORM_SUBMITTED,
            ParticipationApplication::STATUS_UNDER_REVIEW,
        ], true);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $app = ParticipationApplication::find($id);
        if (! $app) {
            return $this->fail(404, 'APPLICATION_NOT_FOUND', 'Application not found.');
        }
        if (! $this->decidable($app)) {
            return $this->fail(409, 'INVALID_STATE', 'Application is not ready for a decision yet.');
        }

        $actor = $this->currentUser();
        $from = $app->status;
        $app->update([
            'status' => ParticipationApplication::STATUS_APPROVED,
            'review_note' => $request->input('reviewNote'),
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $actor->id,
        ]);
        ParticipationApplicationService::applyApprovalSideEffects($app);
        ParticipationApplicationService::logStatusEvent($app, $from, $app->status, (string) $request->input('reviewNote', ''), $actor);
        ParticipationApplicationService::notifyDecision($app->fresh(), $actor, 'Onaylandı', (string) $request->input('reviewNote', ''));
        AchievementEvaluator::evaluate($app->user);
        AuditLogger::logAsCurrentUser('approve', 'application', ParticipationApplicationService::targetLabel($app));

        return $this->ok($app->fresh(['responsibleStaff', 'user', 'emailLogs'])->toApiArray());
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $app = ParticipationApplication::find($id);
        if (! $app) {
            return $this->fail(404, 'APPLICATION_NOT_FOUND', 'Application not found.');
        }
        if (! $this->decidable($app)) {
            return $this->fail(409, 'INVALID_STATE', 'Application is not ready for a decision yet.');
        }

        $actor = $this->currentUser();
        $note = trim((string) $request->input('reviewNote', ''));
        if ($note === '') {
            return $this->fail(422, 'REVIEW_NOTE_REQUIRED', 'Reddetme için öğrenciye iletilecek sebep zorunludur.');
        }
        $from = $app->status;
        $app->update([
            'status' => ParticipationApplication::STATUS_REJECTED,
            'review_note' => $note,
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $actor->id,
        ]);
        ParticipationApplicationService::logStatusEvent($app, $from, $app->status, $note, $actor);
        ParticipationApplicationService::notifyDecision($app->fresh(), $actor, 'Reddedildi', $note);
        AuditLogger::logAsCurrentUser('reject', 'application', ParticipationApplicationService::targetLabel($app));

        return $this->ok($app->fresh(['responsibleStaff', 'user', 'emailLogs'])->toApiArray());
    }

    // Third real outcome alongside approve/reject: the responsible person
    // isn't ready to decide yet and asks the student to change something
    // specific first. The application stays addressable (not a dead end
    // like reject) — the SAME detail_form_token stays valid, so the
    // student's previous detail answers are still there to edit rather
    // than starting over, and resubmitting sends them back to under_review.
    public function requestRevision(Request $request, string $id): JsonResponse
    {
        $app = ParticipationApplication::find($id);
        if (! $app) {
            return $this->fail(404, 'APPLICATION_NOT_FOUND', 'Application not found.');
        }
        if (! $this->decidable($app)) {
            return $this->fail(409, 'INVALID_STATE', 'Application is not ready for a decision yet.');
        }

        $actor = $this->currentUser();
        $note = trim((string) $request->input('reviewNote', ''));
        if ($note === '') {
            return $this->fail(422, 'REVIEW_NOTE_REQUIRED', 'Revizyon talebinde öğrenciye iletilecek not zorunludur.');
        }
        $from = $app->status;
        $app->update([
            'status' => ParticipationApplication::STATUS_REVISION_REQUIRED,
            'review_note' => $note,
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $actor->id,
        ]);
        ParticipationApplicationService::logStatusEvent($app, $from, $app->status, $note, $actor);
        ParticipationApplicationService::notifyDecision($app->fresh(), $actor, 'Revizyon istendi', $note);
        AuditLogger::logAsCurrentUser('revise', 'application', ParticipationApplicationService::targetLabel($app));

        return $this->ok($app->fresh(['responsibleStaff', 'user', 'emailLogs'])->toApiArray());
    }
}
