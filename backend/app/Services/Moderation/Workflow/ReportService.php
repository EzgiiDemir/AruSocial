<?php

namespace App\Services\Moderation\Workflow;

use App\Models\ModerationCase;
use App\Models\ModerationReport;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The one place a user report is created.
 *
 * Every surface used to write its own `ModerationReport` row, which is
 * how the table ended up with no reporter, no reason code and no
 * deduplication: each controller solved a slightly different problem and
 * none of them owned the shape.
 *
 * Report count is a signal, never proof. Nothing here removes content or
 * penalises an account — reports raise priority and open a case for a
 * human. "N reports means removal" is a rule that hands moderation to
 * whoever can organise the most clicks.
 */
final class ReportService
{
    /** Outcome of a report attempt. */
    public const CREATED = 'created';

    public const DUPLICATE = 'duplicate';

    /**
     * @return array{status: string, case: ModerationCase, report: ?ModerationReport}
     */
    public function report(
        User $reporter,
        string $targetType,
        string $targetId,
        ReportReason $reason,
        string $targetLabel = '',
        ?string $description = null,
        ?int $contentOwnerId = null,
    ): array {
        $case = $this->openCaseFor($targetType, $targetId, $contentOwnerId);

        // Same person, same target, same reason. Reporting the same post
        // again for a *different* reason is legitimate; clicking the same
        // button ten times is not a louder signal, and counting it as one
        // would let a single account manufacture a brigade.
        $existing = ModerationReport::query()
            ->where('reporter_user_id', $reporter->id)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->where('reason_code', $reason->value)
            ->first();

        if ($existing !== null) {
            return ['status' => self::DUPLICATE, 'case' => $case, 'report' => $existing];
        }

        $report = ModerationReport::create([
            'id' => 'report-'.Str::uuid(),
            // Legacy columns kept populated so the existing admin screens
            // and their queries keep working during the transition.
            'kind' => $targetType,
            'target_id' => $targetId,
            'target_label' => $targetLabel !== '' ? mb_substr($targetLabel, 0, 120) : $targetId,
            'reason' => $description ?? $reason->value,
            'reported_at' => now(),

            'reporter_user_id' => $reporter->id,
            'target_type' => $targetType,
            'reason_code' => $reason->value,
            'description' => $description,
            'status' => 'open',
            'moderation_case_id' => $case->id,
        ]);

        $this->refreshCasePriority($case, $reason);

        ModerationAudit::record(
            actorType: ModerationAudit::ACTOR_USER,
            actorId: (int) $reporter->id,
            action: 'report_created',
            targetType: $targetType,
            targetId: $targetId,
            caseId: $case->id,
            reason: $reason->value,
            newState: 'open',
        );

        return ['status' => self::CREATED, 'case' => $case, 'report' => $report];
    }

    /** How many distinct people reported this — not how many rows exist. */
    public function distinctReporterCount(string $targetType, string $targetId): int
    {
        return ModerationReport::query()
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->whereNotNull('reporter_user_id')
            ->distinct()
            ->count('reporter_user_id');
    }

    /**
     * Shared with the automatic path, so a report about content the
     * classifier already flagged joins that case rather than opening a
     * second one beside it.
     */
    private function openCaseFor(string $targetType, string $targetId, ?int $ownerId): ModerationCase
    {
        return app(ModerationCaseService::class)->openOrReuse(
            contentType: $targetType,
            contentId: $targetId,
            ownerId: $ownerId,
            source: ModerationCase::SOURCE_USER_REPORT,
        );
    }

    /**
     * Priority is the most urgent claim made, then nudged by how many
     * separate people made one.
     *
     * Independent reporters are weak evidence individually and better
     * evidence together — but the effect is capped, because "lots of
     * reports" must move something up a queue, not decide its outcome.
     */
    private function refreshCasePriority(ModerationCase $case, ReportReason $reason): void
    {
        $reporters = $this->distinctReporterCount(
            (string) $case->content_type,
            (string) $case->content_id,
        );

        $priority = min((int) $case->priority, $reason->priority());
        if ($reporters >= 3) {
            $priority = max(0, $priority - 10);
        }

        $case->update([
            'report_count' => $reporters,
            'priority' => $priority,
        ]);
    }
}
