<?php

namespace App\Services\Ai\Facts;

/** Phase 3C's outcome for one planned question: per-requirement facts, the facts themselves and the answer plan. */
final readonly class FactResult
{
    /**
     * @param  array<string, RequirementFacts>  $requirements  keyed by requirement id
     * @param  array<string, string>  $taskTypes  task id → task type
     */
    public function __construct(
        public array $requirements,
        public AnswerPlan $plan,
        public array $taskTypes,
        public array $timings = [],
    ) {}

    /** @return list<SupportedFact> */
    public function facts(): array
    {
        return array_merge(...array_map(fn (RequirementFacts $r) => $r->facts, array_values($this->requirements)) ?: [[]]);
    }

    /** @return list<CandidateFact> */
    public function candidates(): array
    {
        return array_merge(...array_map(fn (RequirementFacts $r) => $r->candidates, array_values($this->requirements)) ?: [[]]);
    }

    /** The SupportedFactSet of one task. @return list<SupportedFact> */
    public function forTask(string $taskId): array
    {
        return array_values(array_filter($this->facts(), fn (SupportedFact $f) => $f->taskId === $taskId));
    }

    public function fact(string $id): ?SupportedFact
    {
        foreach ($this->facts() as $fact) {
            if ($fact->id === $id) {
                return $fact;
            }
        }

        return null;
    }
}
