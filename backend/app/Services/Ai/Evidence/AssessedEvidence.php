<?php

namespace App\Services\Ai\Evidence;

/** One evidence item after temporal evaluation and policy: whether it may count, and how strongly. */
final readonly class AssessedEvidence
{
    public function __construct(
        public Evidence $evidence,
        public TemporalStatus $temporal,
        public string $temporalReason,
        public bool $eligible,
        /** policy rank, lower = stronger; null when not eligible */
        public ?int $rank,
        public string $policyReason,
    ) {}

    public function toArray(): array
    {
        return [
            'evidence_id' => $this->evidence->id, 'task_id' => $this->evidence->taskId,
            'requirement_id' => $this->evidence->requirementId, 'fact_type' => $this->evidence->factType,
            'authority_class' => $this->evidence->authorityClass, 'temporal' => $this->temporal->value,
            'temporal_reason' => $this->temporalReason, 'eligible' => $this->eligible, 'rank' => $this->rank,
            'policy_reason' => $this->policyReason,
        ];
    }
}
