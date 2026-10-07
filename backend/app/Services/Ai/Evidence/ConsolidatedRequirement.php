<?php

namespace App\Services\Ai\Evidence;

/**
 * Everything known about one requirement after policy and conflict
 * resolution. Not a supported fact: it still carries every raw evidence id,
 * the losers and the unresolved sides, so a later claim layer can decide.
 */
final readonly class ConsolidatedRequirement
{
    public const SATISFIED = 'satisfied';

    public const UNAVAILABLE = 'unavailable';

    public const ERRORED = 'errored';

    public const CONFLICTING = 'conflicting';

    public const NOT_APPLICABLE = 'not_applicable';

    /**
     * @param  list<string>  $values  distinct standing values (comparable evidence only)
     * @param  array<string, list<string>>  $passagesByDocument  document → passage evidence ids
     * @param  list<array{evidence_id: string, reason: string}>  $excluded
     * @param  list<string>  $reasons  why nothing (or only part) is available
     */
    public function __construct(
        public string $requirementId,
        public string $taskId,
        public string $factType,
        public bool $required,
        public string $coverage,
        public ConflictResolution $conflict,
        public array $values = [],
        public array $passagesByDocument = [],
        public array $excluded = [],
        public array $reasons = [],
        public bool $dataGap = false,
    ) {}

    public function satisfied(): bool
    {
        return $this->coverage === self::SATISFIED;
    }

    public function toArray(): array
    {
        $ids = fn (array $items) => array_map(fn (AssessedEvidence $a) => $a->evidence->id, $items);

        return array_filter([
            'requirement_id' => $this->requirementId, 'task_id' => $this->taskId, 'fact_type' => $this->factType,
            'required' => $this->required, 'coverage' => $this->coverage, 'conflict' => $this->conflict->status->value,
            'rule' => $this->conflict->rule, 'values' => $this->values, 'documents' => $this->passagesByDocument,
            'winners' => $ids($this->conflict->winners), 'losers' => $ids($this->conflict->losers),
            'contested' => $ids($this->conflict->contested),
            'excluded' => $this->excluded, 'reasons' => $this->reasons, 'data_gap' => $this->dataGap ?: null,
        ], fn ($v) => $v !== null && $v !== []);
    }
}
