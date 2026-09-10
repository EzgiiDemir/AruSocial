<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Api\Concerns\ModeratesContent;
use App\Http\Controllers\Controller;
use App\Models\ModerationAppeal;
use App\Models\ModerationCase;
use App\Models\UserViolation;
use App\Services\Moderation\Workflow\ModerationAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Contesting a moderation decision.
 *
 * The one rule that makes this real: an appeal is decided by a person.
 * Re-running the same classifier and reporting its answer is not a
 * review — it is the same output with extra steps, and it is precisely
 * what an appeals process exists to prevent. There is deliberately no
 * code path here that consults a model.
 */
class AppealController extends Controller
{
    use ApiResponds, ModeratesContent;

    /** A student appealing a decision about their own content. */
    public function store(Request $request): JsonResponse
    {
        $me = $this->currentUser();
        $caseId = (string) $request->input('caseId', '');
        $reason = trim((string) $request->input('reason', ''));

        if ($reason === '') {
            return $this->fail(400, 'VALIDATION', 'An appeal needs a reason.');
        }

        $case = ModerationCase::find($caseId);
        if ($case === null) {
            return $this->fail(404, 'CASE_NOT_FOUND', 'Moderation case not found.');
        }

        // Only the author may appeal, and the same 404 is returned for
        // "no such case" and "not yours" — otherwise the endpoint becomes
        // a way to discover which of someone else's posts were removed.
        if ((int) $case->user_id !== (int) $me->id) {
            return $this->fail(404, 'CASE_NOT_FOUND', 'Moderation case not found.');
        }

        if (! in_array((string) $case->decision, ['remove', 'hold'], true)) {
            return $this->fail(409, 'NOT_APPEALABLE',
                'Only a removal or a hold can be appealed.');
        }

        // The appeal text is user-written and read by moderators, so it
        // goes through the same gate as any other submission.
        if ($blocked = $this->moderationBlock($me, $reason, 'appeal_reason', 'appeal.store')) {
            return $blocked;
        }

        $existing = ModerationAppeal::where('moderation_case_id', $case->id)
            ->where('user_id', $me->id)
            ->first();
        if ($existing !== null) {
            // One appeal per case. A rejected appeal is not an invitation
            // to submit the same argument until a different moderator sees
            // it, and re-appealing must not reopen a settled case.
            return $this->ok([
                'appealId' => $existing->id,
                'status' => $existing->status,
                'alreadySubmitted' => true,
            ]);
        }

        $appeal = ModerationAppeal::create([
            'id' => (string) Str::uuid(),
            'moderation_case_id' => $case->id,
            'user_id' => $me->id,
            'original_decision' => (string) $case->decision,
            'reason' => $reason,
            'status' => ModerationAppeal::STATUS_OPEN,
        ]);

        // Reopening the case puts it back in front of a human, at raised
        // priority — an appeal is a claim that we got it wrong, and a
        // wrong removal is worth looking at sooner than a fresh report.
        $case->update([
            'status' => ModerationCase::STATUS_REVIEWING,
            'source' => ModerationCase::SOURCE_APPEAL,
            'priority' => max(0, (int) $case->priority - 20),
            'resolved_at' => null,
        ]);

        ModerationAudit::record(
            actorType: ModerationAudit::ACTOR_USER,
            actorId: (int) $me->id,
            action: 'appeal_submitted',
            targetType: (string) $case->content_type,
            targetId: (string) $case->content_id,
            caseId: $case->id,
            reason: $reason,
            previousState: ModerationCase::STATUS_RESOLVED,
            newState: ModerationCase::STATUS_REVIEWING,
        );

        return $this->ok([
            'appealId' => $appeal->id,
            'status' => $appeal->status,
            'alreadySubmitted' => false,
        ]);
    }

    /** The student's own appeals. */
    public function mine(): JsonResponse
    {
        $me = $this->currentUser();

        $appeals = ModerationAppeal::where('user_id', $me->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return $this->ok($appeals->map(fn (ModerationAppeal $a) => [
            'id' => $a->id,
            'caseId' => $a->moderation_case_id,
            'originalDecision' => $a->original_decision,
            'status' => $a->status,
            'reviewNote' => $a->review_note,
            'submittedAt' => $a->created_at?->toIso8601String(),
            'reviewedAt' => $a->reviewed_at?->toIso8601String(),
        ])->all());
    }
}
