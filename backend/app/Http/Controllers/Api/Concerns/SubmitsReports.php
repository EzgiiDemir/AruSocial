<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\User;
use App\Services\Moderation\Workflow\ReportReason;
use App\Services\Moderation\Workflow\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One implementation of "a student reported something".
 *
 * Every surface used to hand-roll this, and they drifted: different
 * validation, different logging, some recording the reporter and some
 * not. A report from the chat screen and a report from the feed have to
 * reach the moderator queue identically, or the queue is measuring the
 * screen the report came from rather than the thing being reported.
 *
 * Requires ApiResponds and ModeratesContent.
 */
trait SubmitsReports
{
    /**
     * @param  string  $sourceFeature  Moderation audit label, e.g. "chat.reportGroup".
     */
    protected function submitReport(
        Request $request,
        User $reporter,
        string $targetType,
        string $targetId,
        string $targetLabel,
        string $sourceFeature,
        ?int $contentOwnerId = null,
    ): JsonResponse {
        $description = trim((string) $request->input('reason', ''));

        // A closed set of codes with the prose kept as an optional
        // description. `reason` stays accepted as free text so existing
        // clients keep working while they move to `reasonCode`.
        $reason = ReportReason::tryFrom((string) $request->input('reasonCode', ''))
            ?? ReportReason::Other;

        // The description is free text a moderator will read, and
        // reporting is exactly the channel someone reaches for to abuse
        // the person they are reporting. It goes through the same gate as
        // any other submission.
        if ($description !== ''
            && ($blocked = $this->moderationBlock($reporter, $description, 'report_reason', $sourceFeature))) {
            return $blocked;
        }

        app(ReportService::class)->report(
            reporter: $reporter,
            targetType: $targetType,
            targetId: $targetId,
            reason: $reason,
            targetLabel: $targetLabel,
            description: $description === '' ? null : $description,
            contentOwnerId: $contentOwnerId,
        );

        // Deliberately the same response whether this was the first report
        // or a duplicate. Telling someone "you already reported this"
        // confirms their earlier report exists and invites them to try
        // again from another account.
        return $this->ok(['reported' => true]);
    }
}
