<?php

namespace App\Http\Controllers\Api\Trainer;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\ParticipationApplication;
use App\Models\StaffProfile;
use App\Services\AchievementEvaluator;
use App\Services\AuditLogger;
use App\Services\ParticipationApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// The same two-stage approve/reject/revise pipeline Admin's Applications
// tab uses (see Api\ParticipationApplicationController), scoped to this
// trainer's own department: every query and write below is filtered to
// `responsible_staff_id === $staff->id`, where $staff is resolved
// server-side by the `department-head` middleware — never from client
// input, so a trainer can't decide another department's application by
// guessing an id. An application only becomes decidable once its Detail
// form is actually in (`detail_form_submitted`/`under_review`) — a
// Preview-only application still waiting on the student isn't.
class ApplicationController extends Controller
{
    use ApiResponds;

    private function staffOf(Request $request): StaffProfile
    {
        return $request->attributes->get('departmentHeadStaff');
    }

    public function index(Request $request): JsonResponse
    {
        $staff = $this->staffOf($request);
        $query = ParticipationApplication::with(['responsibleStaff', 'user', 'emailLogs'])
            ->where('responsible_staff_id', $staff->id)
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

        return $this->ok($query->limit(200)->get()->map->toApiArray());
    }

    private function findOwnPending(Request $request, string $id): ParticipationApplication|JsonResponse
    {
        $staff = $this->staffOf($request);
        $app = ParticipationApplication::where('id', $id)->where('responsible_staff_id', $staff->id)->first();
        if (! $app) {
            return $this->fail(404, 'APPLICATION_NOT_FOUND', 'Application not found.');
        }
        if (! in_array($app->status, [
            ParticipationApplication::STATUS_DETAIL_FORM_SUBMITTED,
            ParticipationApplication::STATUS_UNDER_REVIEW,
        ], true)) {
            return $this->fail(409, 'INVALID_STATE', 'Application is not ready for a decision yet.');
        }

        return $app;
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $app = $this->findOwnPending($request, $id);
        if ($app instanceof JsonResponse) {
            return $app;
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
        $app = $this->findOwnPending($request, $id);
        if ($app instanceof JsonResponse) {
            return $app;
        }

        $note = trim((string) $request->input('reviewNote', ''));
        if ($note === '') {
            return $this->fail(422, 'REVIEW_NOTE_REQUIRED', 'Reddetme için öğrenciye iletilecek sebep zorunludur.');
        }
        $actor = $this->currentUser();
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

    public function revise(Request $request, string $id): JsonResponse
    {
        $app = $this->findOwnPending($request, $id);
        if ($app instanceof JsonResponse) {
            return $app;
        }

        $note = trim((string) $request->input('reviewNote', ''));
        if ($note === '') {
            return $this->fail(422, 'REVIEW_NOTE_REQUIRED', 'Revizyon talebinde öğrenciye iletilecek not zorunludur.');
        }
        $actor = $this->currentUser();
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
