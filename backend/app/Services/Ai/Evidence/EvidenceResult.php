<?php

namespace App\Services\Ai\Evidence;

use App\Services\Ai\Planning\TaskExecutionState;

/** Phase 3B's outcome for one planned question: evidence, coverage, re-derived task states and the request outcome. */
final readonly class EvidenceResult
{
    /** Why a requirement went unsatisfied — data gaps are kept apart from AI or system faults. */
    public const CLASS_DATA_UNAVAILABLE = 'DATA_UNAVAILABLE';

    public const CLASS_CONTEXT_MISSING = 'CONTEXT_MISSING';

    public const CLASS_RESOLUTION = 'RESOLUTION';

    public const CLASS_CONFLICT = 'CONFLICT';

    public const CLASS_NO_ELIGIBLE = 'NO_ELIGIBLE_EVIDENCE';

    public const CLASS_SYSTEM_ERROR = 'SYSTEM_ERROR';

    /**
     * @param  array<string, list<Evidence>>  $evidence  requirement id → raw evidence
     * @param  list<AssessedEvidence>  $assessed
     * @param  array<string, ConsolidatedRequirement>  $requirements  keyed by requirement id
     * @param  list<TaskExecutionState>  $states  task states re-derived from evidence coverage
     * @param  list<string>  $classes  why the outcome is not COMPLETE (empty when it is)
     */
    public function __construct(
        public array $evidence,
        public array $assessed,
        public array $requirements,
        public array $states,
        public RequestOutcome $outcome,
        public array $classes = [],
        public ?array $academicYear = null,
        public array $timings = [],
        /** the KnowledgeBase ranking evidence collection used: {query, limit, hits} */
        public ?array $knowledge = null,
    ) {}

    /**
     * The knowledge hits already ranked for exactly this query and depth —
     * null for any other query, so reuse can never change what is retrieved.
     *
     * @return list<array<string, mixed>>|null
     */
    public function knowledgeHitsFor(string $query, int $limit): ?array
    {
        return $this->knowledge !== null && $this->knowledge['query'] === $query && $this->knowledge['limit'] === $limit
            ? $this->knowledge['hits'] : null;
    }

    /** @return list<Evidence> */
    public function all(): array
    {
        return array_merge(...array_values($this->evidence) ?: [[]]);
    }

    /** @return list<ConsolidatedRequirement> */
    public function forTask(string $taskId): array
    {
        return array_values(array_filter($this->requirements, fn (ConsolidatedRequirement $r) => $r->taskId === $taskId));
    }

    public function find(string $evidenceId): ?Evidence
    {
        foreach ($this->all() as $evidence) {
            if ($evidence->id === $evidenceId) {
                return $evidence;
            }
        }

        return null;
    }
}
