<?php

namespace App\Services\Ai\Planning;

use App\Services\Ai\Evidence\EvidenceResult;
use App\Services\Ai\Facts\FactResult;
use App\Services\Ai\Facts\SupportedFactsRollout;

/** Everything Phase 3A decided for one question, for the answer path and the trace. */
final readonly class PlanningResult
{
    /**
     * @param  list<Mention>  $references
     * @param  list<EntityResolution>  $resolutions
     * @param  list<EvidenceRequirement>  $requirements
     * @param  list<ProviderRoute>  $routes
     * @param  list<TaskExecutionState>  $states
     * @param  list<array<string, mixed>>  $finalEntities
     * @param  list<string>  $agentTools  existing agent tools the routed providers need in the prompt
     * @param  EvidenceResult|null  $evidence  Phase 3B (additive; null when evidence orchestration is off)
     * @param  FactResult|null  $facts  Phase 3C (additive; built whenever Phase 3B ran)
     */
    public function __construct(
        public PlanningDecision $decision,
        public array $references = [],
        public array $resolutions = [],
        public ?TaskPlan $plan = null,
        public array $requirements = [],
        public array $routes = [],
        public array $states = [],
        public array $finalEntities = [],
        public array $agentTools = [],
        public ?EvidenceResult $evidence = null,
        public ?FactResult $facts = null,
    ) {}

    /** Whether this answer is generated from the SupportedFacts contract (Phase 3C rollout mode, decided once per request). */
    public function generatesFromFacts(): bool
    {
        return $this->facts !== null && app(SupportedFactsRollout::class)->usesFacts($this);
    }

    /**
     * Task states as the answer path should see them: re-derived from
     * evidence coverage when Phase 3B ran, Phase 3A's otherwise.
     *
     * @return list<TaskExecutionState>
     */
    public function effectiveStates(): array
    {
        return $this->evidence?->states ?? $this->states;
    }

    public function planned(): bool
    {
        return $this->decision->planned();
    }

    /**
     * Whether a planned question may set aside the operational answer of
     * one of its tasks. Only a plain single-intent answer (or one that only
     * lacks the origin for a route) is replaced; a refusal, a missing
     * personal data source or an ambiguity clarification always stands.
     *
     * @param  array{answer: ?string, warnings: list<array<string, mixed>>}  $operations
     */
    public function mayReplaceOperational(array $operations): bool
    {
        if (! $this->planned() || $operations['answer'] === null) {
            return false;
        }
        foreach ((array) $operations['warnings'] as $warning) {
            if ((string) ($warning['code'] ?? '') !== 'ORIGIN_REQUIRED') {
                return false;
            }
        }

        return true;
    }

    public static function disabled(): self
    {
        return new self(new PlanningDecision(PlanningDecision::DISABLED, 'AICAD_TASK_PLANNING_ENABLED=false'));
    }
}
