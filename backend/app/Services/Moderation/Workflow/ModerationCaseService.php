<?php

namespace App\Services\Moderation\Workflow;

use App\Models\ModerationCase;
use Illuminate\Support\Str;

/**
 * Opens and reuses moderation cases.
 *
 * A case is how content reaches a human. Until now only user reports made
 * one, which left two holes:
 *
 *   - Content the classifier held for REVIEW had a status of "review" and
 *     nothing else. It was not in any queue, so no moderator would ever
 *     see it, and it stayed private forever. That is worse than the
 *     original "everything goes to review" complaint, because at least
 *     that queue existed.
 *   - Blocked content had no case, and an appeal is filed against a case.
 *     So the automatic decisions most worth contesting were the ones that
 *     could not be contested at all.
 *
 * One case per piece of content: a report, an automatic verdict and an
 * appeal about the same photo are one item of work, not three.
 */
final class ModerationCaseService
{
    /**
     * The open case for this content, or a new one.
     *
     * Resolved rather than created blindly, so a machine verdict landing
     * on content someone already reported joins that case instead of
     * splitting the evidence across two.
     */
    public function openOrReuse(
        string $contentType,
        string $contentId,
        ?int $ownerId,
        string $source,
        int $priority = 60,
    ): ModerationCase {
        $existing = ModerationCase::query()
            ->where('content_type', $contentType)
            ->where('content_id', $contentId)
            ->where('status', '!=', ModerationCase::STATUS_RESOLVED)
            ->first();

        if ($existing !== null) {
            // Never lower urgency on reuse — whichever signal was most
            // urgent decides when a human looks.
            if ($priority < (int) $existing->priority) {
                $existing->update(['priority' => $priority]);
            }

            return $existing;
        }

        return ModerationCase::create([
            'id' => (string) Str::uuid(),
            'content_type' => $contentType,
            'content_id' => $contentId,
            'user_id' => $ownerId,
            'source' => $source,
            'priority' => $priority,
            'status' => ModerationCase::STATUS_OPEN,
            'report_count' => 0,
        ]);
    }

    /**
     * Record what the machine decided about a piece of content.
     *
     * `recommendation` is stored separately from `decision` on purpose:
     * one is what the model suggested, the other is what a human settled
     * on. Keeping both is what makes an override rate measurable, and an
     * override rate is the only honest evidence that a threshold is wrong.
     */
    public function openForAutomaticVerdict(
        string $contentType,
        string $contentId,
        ?int $ownerId,
        string $verdict,
        ?string $moderationEventId = null,
    ): ModerationCase {
        $case = $this->openOrReuse(
            contentType: $contentType,
            contentId: $contentId,
            ownerId: $ownerId,
            source: ModerationCase::SOURCE_AUTOMATIC,
            // A block is more urgent than a hold: someone is already
            // unable to post, and if the model was wrong that is time
            // they do not get back.
            priority: $verdict === 'block' ? 25 : 40,
        );

        $case->update([
            'recommendation' => $verdict,
            // Seeds the decision so the content is appealable immediately.
            // An appeal is filed against a case decision, so leaving this
            // null makes exactly the decisions worth contesting the ones
            // that cannot be.
            'decision' => $case->decision ?? ($verdict === 'block' ? 'remove' : 'hold'),
            'moderation_event_id' => $moderationEventId ?? $case->moderation_event_id,
        ]);

        ModerationAudit::record(
            actorType: ModerationAudit::ACTOR_SYSTEM,
            actorId: null,
            action: 'automatic_verdict',
            targetType: $contentType,
            targetId: $contentId,
            caseId: $case->id,
            reason: $verdict,
            newState: (string) $case->status,
        );

        return $case;
    }
}
