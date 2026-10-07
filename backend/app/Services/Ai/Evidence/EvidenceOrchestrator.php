<?php

namespace App\Services\Ai\Evidence;

use App\Services\Ai\AskTrace;
use App\Services\Ai\Planning\PlanningResult;
use App\Services\Ai\Planning\TaskExecutionState;
use App\Services\Ai\Planning\TaskState;
use Carbon\CarbonInterface;

/**
 * Phase 3B, after Phase 3A's executor: provider evidence → temporal
 * evaluation → policy → conflicts → consolidation → requirement coverage →
 * task states and request outcome. Deterministic; no model is consulted.
 *
 * Task states are re-derived from coverage: a task that "ran" but whose
 * required evidence is unavailable is FAILED, not COMPLETED — and a task
 * depending on it is BLOCKED. Phase 3A's own states stay in its trace stage.
 *
 * Disabled by AICAD_EVIDENCE_ORCHESTRATION_ENABLED=false: nothing runs and
 * Phase 3A's behaviour is unchanged.
 */
final class EvidenceOrchestrator
{
    /** Unavailability reasons that are a consequence of another requirement, not a cause. */
    private const DERIVED_REASONS = ['prerequisite_unavailable', 'task_blocked'];

    public function __construct(
        private readonly EvidenceCollector $collector,
        private readonly EvidencePolicy $policy,
        private readonly ConflictResolver $conflicts,
        private readonly EvidenceConsolidator $consolidator,
        private readonly TemporalEvaluator $temporal,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('ai.evidence.enabled', true);
    }

    /** @param array{lat?: mixed, lng?: mixed}|null $location */
    public function run(PlanningResult $planning, string $query, ?array $location, CarbonInterface $now): ?EvidenceResult
    {
        if (! $planning->planned() || $planning->plan === null || ! self::enabled()) {
            return null;
        }

        $timings = [];
        $clock = function (string $stage, callable $work) use (&$timings) {
            $started = microtime(true);
            $result = $work();
            $timings[$stage] = round((microtime(true) - $started) * 1000, 2);

            return $result;
        };

        $evidence = $clock('collection', fn () => $this->collector->collect($planning, $query, $location, $now));
        $assessed = $clock('policy', fn () => array_map(fn (Evidence $e) => $this->policy->assess($e, $now),
            array_merge(...array_values($evidence) ?: [[]])));

        $consolidated = $clock('consolidation', function () use ($planning, $evidence, $assessed): array {
            $out = [];
            foreach ($planning->requirements as $requirement) {
                $mine = array_values(array_filter($assessed, fn (AssessedEvidence $a) => $a->evidence->requirementId === $requirement->id));
                $eligible = array_values(array_filter($mine, fn (AssessedEvidence $a) => $a->eligible));
                $conflict = $this->conflicts->resolve($requirement->id, $requirement->taskId, $requirement->factType, $eligible);
                $out[$requirement->id] = $this->consolidator->consolidate($requirement, $evidence[$requirement->id] ?? [], $mine, $conflict);
            }

            return $out;
        });

        $states = $this->deriveStates($planning, $consolidated);
        [$outcome, $classes] = $this->outcome($states, $consolidated);
        $academicYear = $this->temporal->academicYear($now);
        $timings['total'] = round(array_sum($timings), 2);

        $result = new EvidenceResult($evidence, $assessed, $consolidated, $states, $outcome, $classes, $academicYear, $timings,
            $this->collector->knowledgeHits());
        $this->trace($result);

        return $result;
    }

    /**
     * @param  array<string, ConsolidatedRequirement>  $consolidated
     * @return list<TaskExecutionState>
     */
    private function deriveStates(PlanningResult $planning, array $consolidated): array
    {
        $original = [];
        foreach ($planning->states as $state) {
            $original[$state->taskId] = $state;
        }

        $derived = [];
        foreach ($planning->plan->topologicalOrder() as $wave) {
            foreach ($wave as $taskId) {
                $state = $original[$taskId] ?? null;
                if ($state === null) {
                    continue;
                }
                $task = $planning->plan->task($taskId);
                $brokenDependency = collect($task->dependsOn)->first(fn ($d) => ($derived[$d]->state ?? null) !== TaskState::COMPLETED);
                if ($state->state === TaskState::COMPLETED && $brokenDependency !== null) {
                    $derived[$taskId] = new TaskExecutionState($taskId, $state->taskType, TaskState::BLOCKED,
                        "dependency {$brokenDependency} ({$derived[$brokenDependency]->taskType}) has no evidence", [], $state->wave);

                    continue;
                }
                if ($state->state !== TaskState::COMPLETED) {
                    $derived[$taskId] = $state;

                    continue;
                }
                $unsatisfied = array_filter($consolidated, fn (ConsolidatedRequirement $r) => $r->taskId === $taskId && $r->required && ! $r->satisfied());
                $derived[$taskId] = $unsatisfied === []
                    ? $state
                    : new TaskExecutionState($taskId, $state->taskType, TaskState::FAILED,
                        'evidence: '.implode('; ', array_map(fn (ConsolidatedRequirement $r) => $r->factType.' '.$r->coverage
                            .($r->reasons !== [] ? ' ('.implode(', ', $r->reasons).')' : ''), $unsatisfied)),
                        $state->output, $state->wave);
            }
        }

        return array_values($derived);
    }

    /**
     * Task-level outcome (internal; the Flutter payload is unchanged):
     * COMPLETE every task completed · PARTIAL some did · UNAVAILABLE none
     * did and nothing broke · FAILED none did because something broke.
     *
     * @param  list<TaskExecutionState>  $states
     * @param  array<string, ConsolidatedRequirement>  $consolidated
     * @return array{0: RequestOutcome, 1: list<string>}
     */
    private function outcome(array $states, array $consolidated): array
    {
        $classes = [];
        foreach ($consolidated as $requirement) {
            if ($requirement->satisfied() || $requirement->coverage === ConsolidatedRequirement::NOT_APPLICABLE) {
                continue;
            }
            $causes = array_diff($requirement->reasons, self::DERIVED_REASONS);
            if ($causes === [] && $requirement->reasons !== []) {
                continue;
            }
            $classes[] = match (true) {
                $requirement->coverage === ConsolidatedRequirement::CONFLICTING => EvidenceResult::CLASS_CONFLICT,
                $requirement->coverage === ConsolidatedRequirement::ERRORED => EvidenceResult::CLASS_SYSTEM_ERROR,
                $requirement->dataGap, array_intersect($causes, ['no_provider', 'routing_not_configured']) !== [] => EvidenceResult::CLASS_DATA_UNAVAILABLE,
                in_array('context_missing', $causes, true) => EvidenceResult::CLASS_CONTEXT_MISSING,
                array_intersect($causes, ['subject_unresolved', 'subject_ambiguous']) !== [] => EvidenceResult::CLASS_RESOLUTION,
                default => EvidenceResult::CLASS_NO_ELIGIBLE,
            };
        }
        $classes = array_values(array_unique($classes));

        $completed = count(array_filter($states, fn (TaskExecutionState $s) => $s->state === TaskState::COMPLETED));
        $outcome = match (true) {
            $completed === count($states) => RequestOutcome::COMPLETE,
            $completed > 0 => RequestOutcome::PARTIAL,
            in_array(EvidenceResult::CLASS_SYSTEM_ERROR, $classes, true) => RequestOutcome::FAILED,
            default => RequestOutcome::UNAVAILABLE,
        };

        return [$outcome, $outcome === RequestOutcome::COMPLETE ? [] : $classes];
    }

    private function trace(EvidenceResult $result): void
    {
        $trace = app(AskTrace::class);
        $all = $result->all();
        $trace->record('provider_evidence', [
            'counts' => array_count_values(array_map(fn (Evidence $e) => $e->status->value, $all)),
            'evidence' => array_map(fn (Evidence $e) => $e->toArray(), $all),
        ]);
        $trace->record('temporal_evaluation', array_filter([
            'items' => array_map(fn (AssessedEvidence $a) => [
                'evidence_id' => $a->evidence->id, 'task_id' => $a->evidence->taskId, 'requirement_id' => $a->evidence->requirementId,
                'temporal' => $a->temporal->value, 'reason' => $a->temporalReason,
            ], array_values(array_filter($result->assessed, fn (AssessedEvidence $a) => $a->evidence->value !== null))),
            'academic_year' => $result->academicYear,
        ], fn ($v) => $v !== null));
        $trace->record('evidence_policy', ['items' => array_map(fn (AssessedEvidence $a) => $a->toArray(),
            array_values(array_filter($result->assessed, fn (AssessedEvidence $a) => $a->evidence->value !== null)))]);
        $trace->record('evidence_conflicts', ['resolutions' => array_values(array_map(fn (ConsolidatedRequirement $r) => $r->conflict->toArray(),
            array_filter($result->requirements, fn (ConsolidatedRequirement $r) => $r->conflict->status !== ConflictStatus::NO_CONFLICT)))]);
        $trace->record('evidence_consolidation', ['requirements' => array_values(array_map(fn (ConsolidatedRequirement $r) => $r->toArray(), $result->requirements))]);

        $tasks = [];
        foreach ($result->states as $state) {
            $requirements = $result->forTask($state->taskId);
            $count = fn (string $coverage) => count(array_filter($requirements, fn (ConsolidatedRequirement $r) => $r->coverage === $coverage));
            $tasks[] = ['task_id' => $state->taskId, 'type' => $state->taskType, 'state' => $state->state->value, 'reason' => $state->reason,
                'required' => count(array_filter($requirements, fn (ConsolidatedRequirement $r) => $r->required)),
                'satisfied' => $count(ConsolidatedRequirement::SATISFIED), 'unavailable' => $count(ConsolidatedRequirement::UNAVAILABLE),
                'errored' => $count(ConsolidatedRequirement::ERRORED), 'conflicting' => $count(ConsolidatedRequirement::CONFLICTING),
                'not_applicable' => $count(ConsolidatedRequirement::NOT_APPLICABLE)];
        }
        $trace->record('requirement_coverage', [
            'outcome' => $result->outcome->value,
            'classes' => $result->classes,
            'tasks' => $tasks,
            'requirements' => array_values(array_map(fn (ConsolidatedRequirement $r) => array_filter([
                'requirement_id' => $r->requirementId, 'task_id' => $r->taskId, 'fact_type' => $r->factType, 'required' => $r->required,
                'coverage' => $r->coverage, 'reasons' => $r->reasons, 'data_gap' => $r->dataGap ?: null,
            ], fn ($v) => $v !== null && $v !== []), $result->requirements)),
            'timings' => $result->timings,
        ]);
    }
}
