<?php

namespace App\Services\Ai\Evidence;

use App\Services\Ai\Planning\EvidenceRequirement;

/**
 * Folds one requirement's evidence into a ConsolidatedRequirement: agreeing
 * values collapse into one, passages are grouped by the document they came
 * from (several chunks of one page are one source, for diversity), and
 * excluded or unavailable items keep their reasons. Nothing is discarded
 * and nothing is promoted to a "supported fact".
 */
final class EvidenceConsolidator
{
    /**
     * @param  list<Evidence>  $evidence  everything collected for the requirement
     * @param  list<AssessedEvidence>  $assessed  the same items after policy
     */
    public function consolidate(EvidenceRequirement $requirement, array $evidence, array $assessed, ConflictResolution $conflict): ConsolidatedRequirement
    {
        $values = $passages = [];
        foreach ($conflict->winners as $winner) {
            $normalized = $winner->evidence->normalizedValue();
            if ($normalized !== null) {
                $values[$normalized] = is_scalar($winner->evidence->value) ? (string) $winner->evidence->value : $normalized;
            } else {
                $document = explode('#', (string) $winner->evidence->sourceId)[0];
                $passages[$document][] = $winner->evidence->id;
            }
        }

        $excluded = [];
        foreach ($assessed as $item) {
            if (! $item->eligible && $item->evidence->value !== null) {
                $excluded[] = ['evidence_id' => $item->evidence->id, 'reason' => $item->policyReason];
            }
        }
        $missing = array_values(array_filter($evidence, fn (Evidence $e) => $e->value === null));
        $reasons = array_values(array_unique(array_map(fn (Evidence $e) => (string) $e->reason, $missing)));

        $coverage = match (true) {
            $evidence !== [] && collect($evidence)->every(fn (Evidence $e) => $e->status === EvidenceStatus::NOT_APPLICABLE) => ConsolidatedRequirement::NOT_APPLICABLE,
            $conflict->status === ConflictStatus::UNRESOLVED_CONFLICT => ConsolidatedRequirement::CONFLICTING,
            $conflict->winners !== [] => ConsolidatedRequirement::SATISFIED,
            collect($evidence)->contains(fn (Evidence $e) => $e->status === EvidenceStatus::ERROR) => ConsolidatedRequirement::ERRORED,
            default => ConsolidatedRequirement::UNAVAILABLE,
        };
        if ($coverage === ConsolidatedRequirement::UNAVAILABLE && $excluded !== []) {
            // Evidence existed but none of it may count (expired, irrelevant…).
            $reasons[] = 'no_eligible_evidence';
        }
        $dataGap = $coverage === ConsolidatedRequirement::UNAVAILABLE && $missing !== [] && $excluded === []
            && collect($missing)->every(fn (Evidence $e) => $e->isDataGap());

        return new ConsolidatedRequirement($requirement->id, $requirement->taskId, $requirement->factType, $requirement->required,
            $coverage, $conflict, array_values($values), $passages, $excluded, $reasons, $dataGap);
    }
}
