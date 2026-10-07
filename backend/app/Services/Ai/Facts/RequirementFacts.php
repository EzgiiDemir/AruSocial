<?php

namespace App\Services\Ai\Facts;

/** The factual outcome of one requirement: its status, facts, and every rejected candidate with the reason. */
final readonly class RequirementFacts
{
    /**
     * @param  list<CandidateFact>  $candidates
     * @param  list<SupportedFact>  $facts
     * @param  list<array{candidate_id: string, reason: string}>  $rejected
     * @param  list<string>  $conflictValues  the disagreeing values, when CONFLICTING
     * @param  list<string>  $reasons  why the status is not SUPPORTED
     */
    public function __construct(
        public string $requirementId,
        public string $taskId,
        public string $factType,
        public bool $required,
        public FactStatus $status,
        public array $candidates = [],
        public array $facts = [],
        public array $rejected = [],
        public array $conflictValues = [],
        public array $reasons = [],
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'requirement_id' => $this->requirementId, 'task_id' => $this->taskId, 'fact_type' => $this->factType,
            'required' => $this->required, 'status' => $this->status->value,
            'fact_ids' => array_map(fn (SupportedFact $f) => $f->id, $this->facts),
            'candidate_ids' => array_map(fn (CandidateFact $c) => $c->id, $this->candidates),
            'rejected' => $this->rejected, 'conflict_values' => $this->conflictValues, 'reasons' => $this->reasons,
        ], fn ($v) => $v !== null && $v !== []);
    }
}
