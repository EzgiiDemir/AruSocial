<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\FeedPost;
use App\Models\MediaItem;
use App\Models\ModerationAppeal;
use App\Models\ModerationCase;
use App\Models\User;
use App\Models\UserViolation;
use App\Services\Moderation\Workflow\ModerationAudit;
use App\Services\Moderation\Workflow\ModerationNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A moderator deciding an appeal.
 *
 * Overturning restores the content *and* reverses what the original
 * decision cost the author. An appeal that clears your name but leaves
 * the strike on your record is not an appeal, and a reversal rate is
 * only meaningful if reversals actually undo something.
 */
class AppealReviewController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', ModerationAppeal::STATUS_OPEN);

        $appeals = ModerationAppeal::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        return $this->ok($appeals->map(fn (ModerationAppeal $a) => [
            'id' => $a->id,
            'caseId' => $a->moderation_case_id,
            'userId' => $a->user_id,
            'originalDecision' => $a->original_decision,
            'reason' => $a->reason,
            'status' => $a->status,
            'submittedAt' => $a->created_at?->toIso8601String(),
        ])->all());
    }

    public function decide(Request $request, string $id): JsonResponse
    {
        $appeal = ModerationAppeal::find($id);
        if ($appeal === null) {
            return $this->fail(404, 'APPEAL_NOT_FOUND', 'Appeal not found.');
        }
        if (in_array($appeal->status, [ModerationAppeal::STATUS_UPHELD, ModerationAppeal::STATUS_OVERTURNED], true)) {
            return $this->fail(409, 'APPEAL_ALREADY_DECIDED', 'This appeal has already been decided.');
        }

        $moderator = $this->currentUser();
        $outcome = (string) $request->input('outcome', '');
        $note = trim((string) $request->input('note', ''));

        if (! in_array($outcome, ['uphold', 'overturn'], true)) {
            return $this->fail(400, 'VALIDATION', 'outcome must be uphold or overturn.');
        }
        // The student is told the result, so there has to be something to
        // tell them. "Appeal rejected" with no reason is what makes people
        // give up on a process rather than trust it.
        if ($note === '') {
            return $this->fail(400, 'VALIDATION', 'A note is required so the student is told why.');
        }

        $previous = (string) $appeal->status;
        $case = ModerationCase::find($appeal->moderation_case_id);

        if ($outcome === 'overturn' && $case !== null) {
            $this->restoreContent($case);

            // Reverse the account cost too. Deleting the violation rather
            // than flagging it means it stops counting toward the ladder
            // immediately, which is the whole point of winning an appeal.
            UserViolation::where('moderation_case_id', $case->id)->delete();

            $case->update([
                'status' => ModerationCase::STATUS_RESOLVED,
                'decision' => 'approve',
                'resolution_note' => $note,
                'resolved_at' => now(),
            ]);
        } elseif ($case !== null) {
            $case->update([
                'status' => ModerationCase::STATUS_RESOLVED,
                'resolution_note' => $note,
                'resolved_at' => now(),
            ]);
        }

        $appeal->update([
            'status' => $outcome === 'overturn'
                ? ModerationAppeal::STATUS_OVERTURNED
                : ModerationAppeal::STATUS_UPHELD,
            'reviewed_by' => $moderator->id,
            'review_note' => $note,
            'reviewed_at' => now(),
        ]);

        // The student is told the outcome and the reason. An appeals
        // process whose result you have to go looking for is one people
        // stop using.
        $appellant = User::find($appeal->user_id);
        if ($appellant !== null) {
            app(ModerationNotifier::class)
                ->appealDecided($appellant, $outcome === 'overturn', $note);
        }

        ModerationAudit::record(
            actorType: ModerationAudit::ACTOR_MODERATOR,
            actorId: (int) $moderator->id,
            action: 'appeal_decided',
            targetType: 'appeal',
            targetId: $appeal->id,
            caseId: $appeal->moderation_case_id,
            reason: $note,
            previousState: $previous,
            newState: (string) $appeal->fresh()->status,
        );

        return $this->ok([
            'appealId' => $appeal->id,
            'status' => $appeal->fresh()->status,
        ]);
    }

    private function restoreContent(ModerationCase $case): void
    {
        // Without `includingUnmoderated()` this finds nothing: the global
        // scope hides exactly the removed rows an appeal exists to bring
        // back, so the update would match zero rows and report success.
        match ((string) $case->content_type) {
            'image', 'video', 'media' => MediaItem::where('id', $case->content_id)
                ->update(['moderation_status' => 'approved']),
            'post' => FeedPost::includingUnmoderated()->where('id', $case->content_id)
                ->update(['moderation_status' => 'approved']),
            default => null,
        };
    }
}
