<?php

namespace App\Services\Ai\Planning;

use App\Services\Ai\AskTrace;
use App\Services\Ai\Evidence\EvidenceOrchestrator;
use App\Services\Ai\Facts\SupportedFactBuilder;
use Throwable;

/**
 * Phase 3A orchestration: mentions → preliminary entities → task plan →
 * evidence requirements → provider routes → execution states → final
 * entities. Deterministic throughout; no model is consulted, and the only
 * inputs are the user's message, earlier user turns and campus rows —
 * never retrieved web or PDF text.
 *
 * Every stage is recorded in AskTrace with its task ids. Disabled by
 * AICAD_TASK_PLANNING_ENABLED=false, in which case nothing runs and the
 * existing pipeline answers exactly as before.
 */
final class TaskOrchestrator
{
    public function __construct(
        private readonly MentionDetector $mentions,
        private readonly TaskPlanner $planner,
        private readonly RequirementBuilder $requirements,
        private readonly ProviderRouter $router,
        private readonly TaskExecutor $executor,
        private readonly EvidenceOrchestrator $evidence,
        private readonly SupportedFactBuilder $facts,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $history  earlier turns, oldest first (current excluded)
     * @param  array{lat?: mixed, lng?: mixed}|null  $location
     */
    public function run(string $query, array $history = [], ?array $location = null): PlanningResult
    {
        $trace = app(AskTrace::class);
        if (! (bool) config('ai.task_planning.enabled', true)) {
            $trace->record('planning_mode', PlanningResult::disabled()->decision->toArray());

            return PlanningResult::disabled();
        }

        $timings = [];
        $clock = function (string $stage, callable $work) use (&$timings) {
            $started = microtime(true);
            $result = $work();
            $timings[$stage] = round((microtime(true) - $started) * 1000, 2);

            return $result;
        };

        try {
            $references = $clock('mention_detection', fn () => $this->mentions->references($query));
            $resolutions = $clock('preliminary_resolution', fn () => $this->mentions->resolve($query));
            [$decision, $plan] = $clock('planning', fn () => $this->planner->plan($query, $resolutions, $references));
        } catch (Throwable $e) {
            // A planning fault must never take the answer down with it.
            $decision = new PlanningDecision(PlanningDecision::FAST_PATH, 'planning failed: '.class_basename($e), 'legacy');
            $trace->record('planning_mode', $decision->toArray() + ['timings' => $timings]);

            return new PlanningResult($decision);
        }

        $referents = [];
        foreach ($references as $reference) {
            $referent = $this->mentions->referent($reference, $resolutions, $history);
            if ($referent !== null) {
                $referents[$reference->surface] = $referent;
            }
        }

        $trace->record('mentions', [
            'original_query' => $query,
            'mentions' => array_merge(
                array_map(fn (EntityResolution $r) => $r->mention->toArray(), $resolutions),
                array_map(fn (Mention $m) => $m->toArray(), $references),
            ),
        ]);
        $trace->record('preliminary_entities', ['resolutions' => array_map(fn (EntityResolution $r) => $r->toArray(), $resolutions)]);
        if ($referents !== []) {
            // Structured, alongside (not instead of) the legacy follow-up rewrite.
            $trace->record('conversation_reference', ['original_query' => $query, 'references' => array_map(fn (array $r) => [
                'source' => $r['source'], 'mention' => $r['resolution']->mention->surface,
                'status' => $r['resolution']->status->value,
                'candidates' => array_map(fn ($c) => $c->ref().' '.$c->label, $r['resolution']->candidates),
            ], $referents)]);
        }

        if (! $decision->planned()) {
            $trace->record('planning_mode', $decision->toArray() + ['timings' => $timings]
                + ['single_task' => $plan?->tasks[0]?->type]);

            return new PlanningResult($decision, $references, $resolutions, $plan);
        }

        $requirements = $clock('requirements', fn () => $this->requirements->build($plan));
        $routes = $clock('provider_routing', fn () => $this->router->route($requirements));
        $execution = $clock('execution', fn () => $this->executor->execute($plan, $resolutions, $referents, $routes, $location, now()));
        $timings['total'] = round(array_sum($timings), 2);

        $trace->record('planning_mode', $decision->toArray() + ['timings' => $timings]);
        $trace->record('task_plan', ['tasks' => $plan->toArray()]);
        $trace->record('task_dependencies', ['waves' => $plan->topologicalOrder()]);
        $trace->record('evidence_requirements', ['requirements' => array_map(fn (EvidenceRequirement $r) => $r->toArray(), $requirements)]);
        $trace->record('provider_routes', ['routes' => array_map(fn (ProviderRoute $r) => $r->toArray(), $routes)]);
        $trace->record('task_execution', ['states' => array_map(fn (TaskExecutionState $s) => $s->toArray(), $execution['states'])]);
        $trace->record('final_entities', ['entities' => $execution['final_entities']]);

        $result = new PlanningResult($decision, $references, $resolutions, $plan, $requirements, $routes,
            $execution['states'], $execution['final_entities'], $this->router->agentTools($routes));

        // Phase 3B: evidence for every requirement, and states re-derived from it.
        try {
            $evidence = $this->evidence->run($result, $query, $location, now());
        } catch (Throwable $e) {
            // Like planning, an evidence fault never takes the answer down;
            // Phase 3A's result stands and the fault is visible.
            $trace->record('requirement_coverage', ['error' => class_basename($e)]);
            $evidence = null;
        }

        if ($evidence === null) {
            return $result;
        }

        // Phase 3C: which requirements are FACTUALLY supported, and the answer plan.
        try {
            $facts = $this->facts->build($result, $evidence, $query);
        } catch (Throwable $e) {
            $trace->record('requirement_fact_status', ['error' => class_basename($e)]);
            $facts = null;
        }

        return new PlanningResult($decision, $references, $resolutions, $plan, $requirements, $routes,
            $execution['states'], $execution['final_entities'], $result->agentTools, $evidence, $facts);
    }
}
