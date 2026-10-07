<?php

namespace App\Services\Ai\Evidence;

/** How the eligible evidence for one requirement was reconciled. Losers are kept, never deleted. */
final readonly class ConflictResolution
{
    /**
     * @param  list<AssessedEvidence>  $winners  the value(s) that stand, plus agreeing and passage support
     * @param  list<AssessedEvidence>  $losers  disagreeing evidence a rule set aside
     * @param  list<AssessedEvidence>  $contested  disagreeing evidence no rule could separate
     */
    public function __construct(
        public string $requirementId,
        public string $taskId,
        public string $factType,
        public ConflictStatus $status,
        public array $winners = [],
        public array $losers = [],
        public array $contested = [],
        public ?string $rule = null,
    ) {}

    public function toArray(): array
    {
        $ids = fn (array $items) => array_map(fn (AssessedEvidence $a) => $a->evidence->id, $items);

        return array_filter([
            'requirement_id' => $this->requirementId, 'task_id' => $this->taskId, 'fact_type' => $this->factType,
            'status' => $this->status->value, 'rule' => $this->rule,
            'winners' => $ids($this->winners), 'losers' => $ids($this->losers), 'contested' => $ids($this->contested),
        ], fn ($v) => $v !== null && $v !== []);
    }
}
