<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\FeedPost;
use App\Models\MediaItem;
use App\Models\ModerationCase;
use App\Models\ModerationEvent;
use App\Models\ModerationReport;
use App\Models\User;
use App\Models\UserViolation;
use App\Services\Moderation\Workflow\AccountEnforcementPolicy;
use App\Services\Moderation\Workflow\ModerationAudit;
use App\Services\Moderation\Workflow\ModerationNotifier;
use App\Services\Moderation\Workflow\ReportReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The human review queue.
 *
 * Every route here sits behind `permission:moderation.moderate`, checked
 * server-side. Hiding a button in Flutter is not authorization — the
 * endpoint is what a modified client or a curl one-liner reaches, so the
 * endpoint is what has to refuse.
 *
 * Content decisions and account decisions are separate calls on purpose.
 * Removing a post must not imply banning its author: they are different
 * judgements, made on different evidence, and collapsing them into one
 * button is how a moderator bans someone by accident.
 */
class ModerationCaseController extends Controller
{
    use ApiResponds;

    /** Content outcomes. None of these touch an account. */
    private const CONTENT_DECISIONS = ['approve', 'remove', 'hold', 'escalate'];

    /** Account outcomes, applied only with an explicit severity. */
    private const ACCOUNT_ACTIONS = ['none', 'warn', 'restrict', 'suspend'];

    /**
     * Most urgent first, then oldest — so a quiet, serious case cannot be
     * buried under a pile of fresh spam reports.
     */
    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', 'open');

        $cases = ModerationCase::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderBy('priority')
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        return $this->ok($cases->map(fn (ModerationCase $c) => $this->summary($c))->all());
    }

    /**
     * Everything a moderator needs to decide, in one response: what was
     * said, who complained and why, what the machine thought, and what
     * this account has done before. A decision made without history is
     * how a first mistake and a tenth get the same answer.
     */
    public function show(string $id): JsonResponse
    {
        $case = ModerationCase::find($id);
        if ($case === null) {
            return $this->fail(404, 'CASE_NOT_FOUND', 'Moderation case not found.');
        }

        $reports = ModerationReport::where('moderation_case_id', $case->id)
            ->orderByDesc('reported_at')
            ->get();

        $evidence = ModerationEvent::where('content_id', $case->content_id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return $this->ok([
            ...$this->summary($case),
            'preview' => $this->preview($case),
            'reports' => $reports->map(fn (ModerationReport $r) => [
                'id' => $r->id,
                'reasonCode' => $r->reason_code,
                'description' => $r->description,
                'reportedAt' => $r->reported_at?->toIso8601String(),
            ])->all(),
            'signals' => $evidence->map(fn (ModerationEvent $e) => [
                'action' => $e->action,
                'categories' => $e->categories ?? [],
                'scores' => $e->category_scores ?? [],
                'model' => $e->moderation_model,
                'modelVersion' => $e->model_version,
                'policyVersion' => $e->policy_version,
                'createdAt' => $e->created_at?->toIso8601String(),
            ])->all(),
            'authorHistory' => $this->authorHistory($case),
        ]);
    }

    /**
     * Record a moderator's decision.
     *
     * `contentDecision` is required. `accountAction` is optional and
     * defaults to none — so the easy path, the one a tired moderator
     * takes at the end of a shift, removes the post and leaves the person
     * alone.
     */
    public function decide(Request $request, string $id): JsonResponse
    {
        $case = ModerationCase::find($id);
        if ($case === null) {
            return $this->fail(404, 'CASE_NOT_FOUND', 'Moderation case not found.');
        }
        if ($case->status === ModerationCase::STATUS_RESOLVED) {
            return $this->fail(409, 'CASE_ALREADY_RESOLVED', 'This case has already been decided.');
        }

        $moderator = $this->currentUser();
        $contentDecision = (string) $request->input('contentDecision', '');
        $accountAction = (string) $request->input('accountAction', 'none');
        $note = trim((string) $request->input('note', ''));

        if (! in_array($contentDecision, self::CONTENT_DECISIONS, true)) {
            return $this->fail(400, 'VALIDATION',
                'contentDecision must be one of: '.implode(', ', self::CONTENT_DECISIONS));
        }
        if (! in_array($accountAction, self::ACCOUNT_ACTIONS, true)) {
            return $this->fail(400, 'VALIDATION',
                'accountAction must be one of: '.implode(', ', self::ACCOUNT_ACTIONS));
        }
        // Punishing someone requires saying why, in writing, attached to
        // their record. An unexplained penalty cannot be appealed against
        // or defended later.
        if ($accountAction !== 'none' && $note === '') {
            return $this->fail(400, 'VALIDATION',
                'A note is required when an account action is taken.');
        }

        $previousState = (string) $case->status;
        $this->applyContentDecision($case, $contentDecision);

        $enforcement = null;
        if ($accountAction !== 'none' && $case->user_id !== null) {
            $author = User::find($case->user_id);
            if ($author !== null) {
                $reason = ReportReason::tryFrom(
                    (string) ModerationReport::where('moderation_case_id', $case->id)->value('reason_code')
                ) ?? ReportReason::Other;

                $enforcement = app(AccountEnforcementPolicy::class)->recordConfirmedViolation(
                    user: $author,
                    category: $reason->value,
                    severity: $reason->severityIfConfirmed(),
                    // Keyed on the case, so a double-clicked button or a
                    // retried request cannot punish the same act twice.
                    idempotencyKey: 'case:'.$case->id,
                    caseId: $case->id,
                    decidedBy: (int) $moderator->id,
                );
            }
        }

        $case->update([
            'status' => $contentDecision === 'escalate'
                ? ModerationCase::STATUS_REVIEWING
                : ModerationCase::STATUS_RESOLVED,
            'decision' => $contentDecision,
            'assigned_moderator_id' => $moderator->id,
            'resolution_note' => $note === '' ? null : $note,
            'resolved_at' => $contentDecision === 'escalate' ? null : now(),
        ]);

        ModerationReport::where('moderation_case_id', $case->id)
            ->update(['status' => 'resolved']);

        // Tell the author. A decision they are never told about is
        // indistinguishable from the app being broken, and is the fastest
        // way to make someone assume they were treated arbitrarily.
        if ($case->user_id !== null) {
            $author = User::find($case->user_id);
            if ($author !== null) {
                $notifier = app(ModerationNotifier::class);
                match ($contentDecision) {
                    'remove' => $notifier->contentRemoved(
                        $author, (string) $case->content_type, $case->id),
                    'hold' => $notifier->contentUnderReview(
                        $author, (string) $case->content_type, $case->id),
                    default => null,
                };
                // The account penalty is a separate message from the
                // content one: they are separate decisions, and merging
                // them is how "your post was removed" gets read as "you
                // are banned".
                if ($enforcement !== null && ($enforcement['action'] ?? 'none') !== 'none') {
                    $notifier->accountWarned($author, (string) $enforcement['action']);
                }
            }
        }

        ModerationAudit::record(
            actorType: ModerationAudit::ACTOR_MODERATOR,
            actorId: (int) $moderator->id,
            action: 'case_decided',
            targetType: (string) $case->content_type,
            targetId: (string) $case->content_id,
            caseId: $case->id,
            reason: $note === '' ? $contentDecision : $note,
            previousState: $previousState,
            newState: (string) $case->fresh()->status,
            context: [
                'contentDecision' => $contentDecision,
                'accountAction' => $accountAction,
                'enforcement' => $enforcement['action'] ?? 'none',
            ],
        );

        return $this->ok([
            'caseId' => $case->id,
            'contentDecision' => $contentDecision,
            'accountAction' => $enforcement['action'] ?? 'none',
            'status' => $case->fresh()->status,
        ]);
    }

    private function applyContentDecision(ModerationCase $case, string $decision): void
    {
        $status = match ($decision) {
            'approve' => 'approved',
            'remove' => 'removed',
            'hold' => 'review',
            default => null,
        };
        if ($status === null) {
            return;
        }

        // Only content types this method actually knows how to change.
        // Silently doing nothing for an unknown type would report success
        // while leaving the content exactly where it was.
        //
        // `includingUnmoderated()` is required, not optional: FeedPost has
        // a global scope that hides anything not approved, so without it a
        // moderator could remove a post but never touch it again — the
        // query would no longer match the row it had just changed.
        match ((string) $case->content_type) {
            'image', 'video', 'media' => MediaItem::where('id', $case->content_id)
                ->update(['moderation_status' => $status]),
            'post' => FeedPost::includingUnmoderated()->where('id', $case->content_id)
                ->update(['moderation_status' => $status]),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function summary(ModerationCase $case): array
    {
        return [
            'id' => $case->id,
            'contentType' => $case->content_type,
            'contentId' => $case->content_id,
            'authorId' => $case->user_id,
            'source' => $case->source,
            'priority' => (int) $case->priority,
            'status' => $case->status,
            'decision' => $case->decision,
            'recommendation' => $case->recommendation,
            'reportCount' => (int) $case->report_count,
            'createdAt' => $case->created_at?->toIso8601String(),
        ];
    }

    private function preview(ModerationCase $case): ?string
    {
        return match ((string) $case->content_type) {
            'post' => mb_substr((string) FeedPost::where('id', $case->content_id)->value('text'), 0, 300),
            'image', 'video', 'media' => (string) MediaItem::where('id', $case->content_id)->value('file_name'),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function authorHistory(ModerationCase $case): array
    {
        if ($case->user_id === null) {
            return ['confirmedViolations' => 0, 'activePoints' => 0];
        }
        $author = User::find($case->user_id);
        if ($author === null) {
            return ['confirmedViolations' => 0, 'activePoints' => 0];
        }

        return [
            'confirmedViolations' => UserViolation::where('user_id', $author->id)->counting()->count(),
            'activePoints' => app(AccountEnforcementPolicy::class)->activePoints($author),
            'currentlyRestrictedUntil' => $author->banned_until?->toIso8601String(),
        ];
    }
}
