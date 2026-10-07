<?php

namespace App\Services\Ai\Evaluation;

use App\Services\Ai\AnswerGrounding;
use App\Support\TextFold;

/**
 * Checks an evaluation case's structured assertions against one AskTrace
 * report (AskDiagnostics::run()), and names the earliest stage that failed.
 *
 * Nothing here re-runs or re-implements the pipeline: every fact comes from
 * a stage the request itself recorded — follow_up, routing, entities,
 * tool.*, knowledge.candidates, citations, model, grounding — or from the
 * response payload. observe() is also what a result stores as its snapshot,
 * so the stored evidence and the checked evidence are the same thing.
 *
 * Assertions are {type, ...params}. A case uses only the ones it needs;
 * none compares a whole answer, because model wording is nondeterministic.
 */
final class AssertionEvaluator
{
    /** Pipeline order. A failed case is attributed to the earliest failing stage. */
    public const STAGES = [
        'follow_up_resolution', 'task_planning', 'evidence_routing', 'query_planning', 'entity_resolution',
        'task_execution', 'provider_evidence', 'temporal_evaluation', 'evidence_policy', 'evidence_conflicts',
        'requirement_coverage', 'candidate_facts', 'fact_validation', 'supported_facts', 'answer_plan', 'operational_data',
        'live_database', 'knowledge_retrieval', 'source_ranking', 'evidence_budget', 'prompt_budget',
        'generation', 'grounding', 'claim_verification', 'structured_response', 'expected_assertion',
    ];

    /**
     * Assertion type => [stage, category, params]. Category groups pass
     * rates in run metrics; params drive the admin form.
     */
    public const TYPES = [
        'follow_up.rewritten' => ['follow_up_resolution', 'follow_up', ['expect']],
        // Phase 3A: task planning and evidence routing. By task TYPE, never
        // by generated id, so a renumbered plan does not break a test.
        'plan.mode' => ['task_planning', 'planning_mode', ['value']],
        'plan.task_types_include' => ['task_planning', 'task_planning', ['values']],
        'plan.task_types_exclude' => ['task_planning', 'task_planning', ['values']],
        'plan.task_count' => ['task_planning', 'task_planning', ['n']],
        'plan.depends_on' => ['task_planning', 'dependencies', ['value', 'target']],
        'plan.requirements_include' => ['evidence_routing', 'requirements', ['values']],
        'plan.provider_for' => ['evidence_routing', 'provider_routing', ['value', 'target']],
        'plan.resolution_status' => ['entity_resolution', 'plan_entities', ['value']],
        'plan.final_entity' => ['task_execution', 'plan_entities', ['value', 'entity_type', 'entity_id']],
        'plan.task_state' => ['task_execution', 'task_execution', ['value', 'target']],
        // Phase 3B: evidence, by FACT TYPE (value), never by evidence id.
        'evidence.available' => ['provider_evidence', 'evidence', ['value']],
        'evidence.unavailable' => ['provider_evidence', 'evidence', ['value', 'target']],
        'evidence.source_type' => ['provider_evidence', 'evidence', ['value', 'target']],
        'evidence.source' => ['provider_evidence', 'evidence', ['value', 'match']],
        'evidence.temporal' => ['temporal_evaluation', 'temporal', ['value', 'target']],
        'evidence.excluded' => ['evidence_policy', 'evidence_policy', ['value', 'target']],
        'evidence.conflict' => ['evidence_conflicts', 'conflict', ['value', 'target']],
        'evidence.winner_class' => ['evidence_conflicts', 'conflict', ['value', 'target']],
        'evidence.requirement' => ['requirement_coverage', 'coverage', ['value', 'target']],
        'evidence.outcome' => ['requirement_coverage', 'coverage', ['value']],
        'evidence.reaches_prompt' => ['evidence_budget', 'evidence_budget', ['value']],
        // Phase 3C: candidate and supported facts, by FACT TYPE; the answer
        // plan by TASK TYPE; claims and citations of the generated answer.
        'fact.candidate' => ['candidate_facts', 'fact_extraction', ['value']],
        'fact.supported' => ['supported_facts', 'supported_fact', ['value', 'target']],
        'fact.none' => ['supported_facts', 'supported_fact', ['value']],
        'fact.status' => ['fact_validation', 'fact_status', ['value', 'target']],
        'fact.source' => ['supported_facts', 'supported_fact', ['value', 'match']],
        'answer_plan.task' => ['answer_plan', 'answer_plan', ['value', 'target']],
        'answer_plan.outcome' => ['answer_plan', 'answer_plan', ['value']],
        'claim.uses_fact' => ['claim_verification', 'claims', ['value']],
        'claim.no_unsupported' => ['claim_verification', 'claims', []],
        'claim.unsupported_caught' => ['claim_verification', 'claims', ['expect']],
        'claim.regenerated' => ['claim_verification', 'claims', ['expect']],
        'citation.from_fact' => ['claim_verification', 'citations', ['match']],
        'citation.absent' => ['claim_verification', 'citations', ['match']],
        // Phase 3C.1: which generation path served, and task coverage of the FINAL answer.
        'rollout.path' => ['claim_verification', 'rollout', ['value']],
        'claim.full_coverage' => ['claim_verification', 'claims', []],
        'follow_up.contains' => ['follow_up_resolution', 'follow_up', ['value']],
        'routing.domains_include' => ['query_planning', 'routing', ['values']],
        'routing.domains_exclude' => ['query_planning', 'routing', ['values']],
        'routing.tools_include' => ['query_planning', 'routing', ['values']],
        'routing.fallback' => ['query_planning', 'routing', ['expect']],
        'entity.resolves' => ['entity_resolution', 'entity', ['entity_type', 'entity_id']],
        'entity.not_resolves' => ['entity_resolution', 'entity', ['entity_type', 'entity_id']],
        'entity.ambiguous' => ['entity_resolution', 'entity', ['expect']],
        'operational.answered' => ['operational_data', 'operational', ['expect']],
        'operational.place_ids_include' => ['operational_data', 'operational', ['values']],
        'operational.route' => ['operational_data', 'operational', ['expect']],
        'live.tool_rows' => ['live_database', 'operational', ['value']],
        'retrieval.source_in_top' => ['knowledge_retrieval', 'retrieval', ['match', 'n']],
        'retrieval.source_absent' => ['source_ranking', 'retrieval', ['match', 'n']],
        'prompt.source_included' => ['prompt_budget', 'prompt', ['match']],
        'prompt.source_dropped' => ['prompt_budget', 'prompt', ['match']],
        'citations.not_cited' => ['source_ranking', 'retrieval', ['match']],
        'grounding.passed' => ['grounding', 'grounding', ['expect']],
        'grounding.regenerated' => ['grounding', 'grounding', ['expect']],
        'response.contains' => ['generation', 'response', ['value']],
        // The answer must not contradict a trusted structured fact (knowledge_facts).
        'response.no_fact_contradiction' => ['generation', 'response', []],
        'response.not_contains' => ['generation', 'response', ['value']],
        'response.ai_mode' => ['structured_response', 'response', ['values']],
        'response.served_by' => ['structured_response', 'response', ['values']],
        'response.warning' => ['structured_response', 'response', ['value']],
        'response.has' => ['structured_response', 'response', ['value']],
    ];

    /**
     * The facts assertions are checked against, from the report's stages.
     * Already redacted by AskTrace; trimmed to what explains a result.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function observe(array $report): array
    {
        $stage = function (string $name) use ($report): ?array {
            foreach (array_reverse($report['stages'] ?? []) as $s) {
                if ($s['stage'] === $name) {
                    return $s['data'];
                }
            }

            return null;
        };
        $result = (array) ($report['result'] ?? []);
        $full = ($report['mode'] ?? '') === 'full';

        $tools = [];
        foreach ($report['stages'] ?? [] as $s) {
            if (str_starts_with($s['stage'], 'tool.')) {
                $tools[substr($s['stage'], 5)] = count($s['data']['rows'] ?? []);
            }
        }

        $routing = $stage('routing');
        $operational = $stage('operational');
        $grounding = $stage('grounding');
        $model = $stage('model');
        $aiMode = $result['aiMode'] ?? null;
        $servedBy = $full
            ? match ($aiMode) {
                'operational' => 'operational',
                'direct' => 'direct',
                null => null,
                default => 'model',
            }
        : ($result['would_serve'] ?? null);

        return [
            'follow_up' => $stage('follow_up'),
            'routing' => $routing === null ? null : [
                'normalized' => $routing['normalized'] ?? null,
                'domains' => array_keys((array) ($routing['domains'] ?? [])),
                'tools' => $routing['tools'] ?? [],
                'fallback' => $routing['fallback'] ?? null,
            ],
            'entities' => $stage('entities')['resolved'] ?? null,
            'operational' => [
                'answered' => $operational['answered'] ?? null,
                'place_ids' => array_values(array_map(fn ($p) => (string) ($p['id'] ?? ''), (array) ($result['places'] ?? []))),
                'route' => ! empty($result['route']),
                'events' => count((array) ($result['events'] ?? [])),
                'warnings' => array_values(array_map(fn ($w) => (string) ($w['code'] ?? ''), (array) ($result['warnings'] ?? []))),
            ],
            'tools' => $tools,
            'knowledge' => array_map(fn (array $c) => [
                'rank' => $c['rank'], 'selected' => $c['selected'], 'title' => $c['title'], 'url' => $c['url'],
                'score' => $c['score'], 'parts' => $c['parts'],
                // Long documents: was this page reached by an embedded passage
                // or by keywords alone?
                'matched_by' => isset($c['parts']['similarity']) ? 'semantic' : 'lexical_only',
            ], (array) ($stage('knowledge.candidates')['candidates'] ?? [])),
            'citations' => $stage('citations')['candidates'] ?? null,
            'sources' => array_values(array_map(fn ($s) => ['title' => $s['title'] ?? '', 'url' => $s['url'] ?? ''], (array) ($result['sources'] ?? []))),
            'model' => $model === null ? null : array_intersect_key($model, array_flip(['provider', 'model', 'status', 'cached', 'used_fallback', 'knowledge_only', 'has_text', 'duration_ms'])),
            'grounding' => $grounding,
            // One entry per model call; two means grounding forced a retry.
            'model_attempts' => array_values(array_map(fn ($a) => $a['data'], array_filter(
                (array) ($report['stages'] ?? []), fn ($a) => $a['stage'] === 'model.attempt',
            ))),
            'concepts' => $stage('knowledge.search')['concepts'] ?? null,
            'planning' => $this->planning($stage),
            'evidence' => $this->evidence($stage),
            'facts' => $this->facts($stage),
            'fallback' => $stage('fallback'),
            'served_by' => $servedBy,
            'ai_mode' => $aiMode,
            'answer' => mb_substr((string) ($result['answer'] ?? $result['operational_answer'] ?? $result['direct_answer'] ?? ''), 0, 1200),
            'latency' => array_filter([
                'planner_ms' => $routing['timings']['keywords_ms'] ?? null,
                'entities_ms' => $routing['timings']['entities_ms'] ?? null,
                'tools_ms' => isset($stage('database')['tool_ms']) ? array_sum($stage('database')['tool_ms']) : null,
                'semantic_ms' => $stage('knowledge.semantic')['duration_ms'] ?? null,
                'knowledge_ms' => $stage('knowledge.search')['total_ms'] ?? null,
                'prompt_build_ms' => $stage('prompt.budget')['build_ms'] ?? null,
                'model_ms' => $model['duration_ms'] ?? null,
                'grounding_ms' => $grounding['duration_ms'] ?? null,
            ], fn ($v) => $v !== null),
        ];
    }

    /**
     * Phase 3A facts, from the planning stages AskTrace recorded.
     *
     * @return array<string, mixed>|null
     */
    private function planning(callable $stage): ?array
    {
        $mode = $stage('planning_mode');
        if ($mode === null) {
            return null;
        }
        $tasks = $stage('task_plan')['tasks'] ?? [];
        $typeOf = array_column($tasks, 'type', 'id');

        return [
            'mode' => $mode['mode'] ?? null,
            'reason' => $mode['reason'] ?? null,
            'handler' => $mode['handler'] ?? null,
            'timings' => $mode['timings'] ?? null,
            'tasks' => $tasks,
            'requirements' => array_map(fn ($r) => ['task_id' => $r['task_id'], 'task_type' => $typeOf[$r['task_id']] ?? null, 'fact_type' => $r['fact_type']],
                $stage('evidence_requirements')['requirements'] ?? []),
            'routes' => array_map(fn ($r) => ['task_id' => $r['task_id'], 'fact_type' => $r['fact_type'], 'provider' => $r['provider']],
                $stage('provider_routes')['routes'] ?? []),
            'states' => array_map(fn ($s) => ['task_id' => $s['task_id'], 'type' => $s['type'], 'state' => $s['state'], 'reason' => $s['reason']],
                $stage('task_execution')['states'] ?? []),
            'final_entities' => array_map(fn ($f) => $f + ['task_type' => $typeOf[$f['task_id']] ?? null], $stage('final_entities')['entities'] ?? []),
            'resolutions' => array_map(fn ($r) => ['mention' => $r['mention']['surface'], 'status' => $r['status'],
                'candidates' => array_map(fn ($c) => $c['entity_type'].':'.$c['entity_id'], $r['candidates'])],
                $stage('preliminary_entities')['resolutions'] ?? []),
        ];
    }

    /**
     * Phase 3B facts, from the evidence stages AskTrace recorded. Joined per
     * evidence id across stages, with the task type for each requirement.
     *
     * @return array<string, mixed>|null
     */
    private function evidence(callable $stage): ?array
    {
        $provider = $stage('provider_evidence');
        if ($provider === null) {
            return null;
        }
        $temporal = array_column((array) ($stage('temporal_evaluation')['items'] ?? []), null, 'evidence_id');
        $policy = array_column((array) ($stage('evidence_policy')['items'] ?? []), null, 'evidence_id');
        $typeOf = array_column((array) ($stage('task_plan')['tasks'] ?? []), 'type', 'id');
        $coverage = (array) ($stage('requirement_coverage') ?? []);
        $tasksBlock = collect((array) ($stage('prompt.budget')['blocks'] ?? []))->firstWhere('id', 'tool:tasks');

        return [
            'outcome' => $coverage['outcome'] ?? null,
            'classes' => (array) ($coverage['classes'] ?? []),
            'items' => array_map(fn (array $e) => [
                'evidence_id' => $e['evidence_id'], 'task_id' => $e['task_id'], 'requirement_id' => $e['requirement_id'],
                'fact_type' => $e['fact_type'], 'status' => $e['status'], 'reason' => $e['reason'] ?? null,
                'source_type' => $e['source']['type'] ?? null, 'title' => $e['source']['title'] ?? null, 'url' => $e['source']['url'] ?? null,
                'page' => $e['metadata']['page'] ?? null, 'authority_class' => $e['authority_class'] ?? null,
                'temporal' => $temporal[$e['evidence_id']]['temporal'] ?? null,
                'eligible' => $policy[$e['evidence_id']]['eligible'] ?? null,
                'policy_reason' => $policy[$e['evidence_id']]['policy_reason'] ?? null,
            ], (array) ($provider['evidence'] ?? [])),
            'requirements' => array_map(fn (array $r) => [
                'requirement_id' => $r['requirement_id'], 'task_id' => $r['task_id'], 'task_type' => $typeOf[$r['task_id']] ?? null,
                'fact_type' => $r['fact_type'], 'coverage' => $r['coverage'], 'conflict' => $r['conflict'] ?? 'NO_CONFLICT',
                'rule' => $r['rule'] ?? null, 'winners' => (array) ($r['winners'] ?? []), 'reasons' => (array) ($r['reasons'] ?? []),
                'data_gap' => (bool) ($r['data_gap'] ?? false),
            ], (array) ($stage('evidence_consolidation')['requirements'] ?? [])),
            'budget' => array_map(fn (array $b) => array_intersect_key($b, array_flip(['task_id', 'requirement_id', 'evidence_id', 'kind', 'fate', 'end'])),
                (array) ($stage('evidence_budget')['items'] ?? [])),
            'tasks_block_fate' => $tasksBlock['fate'] ?? null,
            'tasks_block_kept_chars' => $tasksBlock['kept_chars'] ?? null,
            'tasks' => (array) ($coverage['tasks'] ?? []),
            'timings' => $coverage['timings'] ?? null,
        ];
    }

    /**
     * Phase 3C facts, from the fact stages AskTrace recorded. Values of
     * redacted facts stay redacted.
     *
     * @return array<string, mixed>|null
     */
    private function facts(callable $stage): ?array
    {
        $status = $stage('requirement_fact_status');
        if ($status === null || ! isset($status['requirements'])) {
            return null;
        }
        $typeOf = array_column((array) ($stage('task_plan')['tasks'] ?? []), 'type', 'id');
        $usage = $stage('generation_fact_usage');
        $verification = $stage('claim_verification');

        return [
            'mode' => $stage('generation_input') !== null ? 'supported_facts' : 'evidence',
            'candidates' => array_map(fn (array $c) => array_intersect_key($c, array_flip(['candidate_id', 'task_id', 'requirement_id', 'fact_type', 'method', 'evidence_ids'])),
                (array) ($stage('candidate_facts')['candidates'] ?? [])),
            'supported' => array_map(fn (array $f) => [
                'fact_id' => $f['fact_id'], 'task_id' => $f['task_id'], 'requirement_id' => $f['requirement_id'], 'candidate_id' => $f['candidate_id'] ?? null,
                'fact_type' => $f['fact_type'], 'value' => $f['value'], 'normalized_value' => $f['normalized_value'] ?? null,
                'evidence_ids' => $f['evidence_ids'] ?? [],
                'sources' => array_map(fn ($p) => ['title' => $p['title'] ?? null, 'url' => $p['url'] ?? null, 'page' => $p['page'] ?? null], (array) ($f['provenance'] ?? [])),
            ], (array) ($stage('supported_facts')['facts'] ?? [])),
            'requirements' => array_map(fn (array $r) => [
                'requirement_id' => $r['requirement_id'], 'task_id' => $r['task_id'], 'task_type' => $typeOf[$r['task_id']] ?? null,
                'fact_type' => $r['fact_type'], 'status' => $r['status'], 'rejected' => (array) ($r['rejected'] ?? []),
                'candidate_ids' => (array) ($r['candidate_ids'] ?? []), 'fact_ids' => (array) ($r['fact_ids'] ?? []),
            ], (array) $status['requirements']),
            'plan' => $stage('answer_plan'),
            'timings' => $status['timings'] ?? null,
            'usage' => $usage === null ? null : [
                'used_fact_ids' => (array) ($usage['used_fact_ids'] ?? []), 'claims' => (array) ($usage['claims'] ?? []),
                'cited' => (array) ($usage['cited'] ?? []), 'not_cited' => (array) ($usage['not_cited'] ?? []),
            ],
            'verification' => $verification,
            'path' => $stage('generation_path'),
        ];
    }

    /**
     * Whether one evidence-budget unit survived into the model's context:
     * the task block was kept whole, or was cut after the unit's end.
     *
     * @param  array{fate: string, end?: int}  $unit
     * @param  array{tasks_block_fate: ?string, tasks_block_kept_chars: ?int}  $evidence
     */
    public static function unitReached(array $unit, array $evidence): bool
    {
        return $unit['fate'] !== 'dropped' && match ($evidence['tasks_block_fate'] ?? null) {
            'kept' => true,
            'truncated' => isset($unit['end']) && $unit['end'] <= (int) ($evidence['tasks_block_kept_chars'] ?? 0),
            default => false,
        };
    }

    /**
     * Why a failed case failed, in terms an operator can act on:
     *  DATA_UNAVAILABLE  every failed assertion is about a fact the campus
     *                    data does not hold (a recorded data gap) — fix data
     *  AI_GENERATION     the evidence was there; the model or grounding missed
     *  PIPELINE          planning, retrieval, policy or budgeting went wrong
     *
     * @param  list<array{type: string, value: mixed, stage: string}>  $failed
     */
    public function classify(array $failed, array $facts): ?string
    {
        if ($failed === []) {
            return null;
        }
        $gaps = collect($facts['evidence']['requirements'] ?? [])->where('data_gap', true);
        $gapFacts = $gaps->pluck('fact_type')->all();
        $gapTasks = $gaps->pluck('task_type')->filter()->all();
        $explained = fn (array $f) => match (true) {
            $f['type'] === 'evidence.outcome' => $gapFacts !== [],
            str_starts_with((string) $f['type'], 'evidence.') => in_array($f['value'], $gapFacts, true),
            $f['type'] === 'plan.task_state' => in_array($f['value'], $gapTasks, true),
            default => false,
        };
        if ($gapFacts !== [] && collect($failed)->every($explained)) {
            return 'DATA_UNAVAILABLE';
        }

        return collect($failed)->every(fn (array $f) => in_array($f['stage'], ['generation', 'grounding'], true))
            ? 'AI_GENERATION' : 'PIPELINE';
    }

    /**
     * @param  list<array<string, mixed>>  $assertions
     * @param  array<string, mixed>  $facts  from observe()
     * @return array{failed: list<array<string, mixed>>, failure_stage: ?string, categories: array<string, bool>}
     */
    public function evaluate(array $assertions, array $facts): array
    {
        $failed = [];
        $categories = [];
        $outcomes = [];
        $evidenceOutcomes = [];
        $factOutcomes = [];
        $classifiable = [];
        foreach ($assertions as $assertion) {
            $type = (string) ($assertion['type'] ?? '');
            [$stage, $category] = self::TYPES[$type] ?? ['expected_assertion', 'other'];
            [$ok, $actual, $stage] = isset(self::TYPES[$type])
                ? $this->check($type, $assertion, $facts, $stage)
                : [false, 'unknown assertion type', 'expected_assertion'];
            $categories[$category] = ($categories[$category] ?? true) && $ok;
            if (! $ok) {
                $failed[] = ['type' => $type, 'expected' => $this->describe($assertion), 'actual' => $actual, 'stage' => $stage];
                $classifiable[] = ['type' => $type, 'value' => $assertion['value'] ?? null, 'stage' => $stage];
            }
            if (str_starts_with($type, 'evidence.')) {
                $evidenceOutcomes[] = ['type' => $type, 'ok' => $ok, 'value' => $assertion['value'] ?? null, 'target' => $assertion['target'] ?? null];
            }
            if (preg_match('/^(fact|answer_plan|claim|citation)\./', $type)) {
                $factOutcomes[] = ['type' => $type, 'ok' => $ok, 'value' => $assertion['value'] ?? null, 'target' => $assertion['target'] ?? null];
            }
            // Per-assertion outcome for the Phase 3A metrics (coverage counts
            // what was requested and what the plan actually contained).
            if (str_starts_with($type, 'plan.')) {
                $outcome = ['type' => $type, 'ok' => $ok, 'value' => $assertion['value'] ?? null, 'target' => $assertion['target'] ?? null];
                if (in_array($type, ['plan.task_types_include', 'plan.requirements_include'], true)) {
                    $have = $type === 'plan.task_types_include'
                        ? array_column($facts['planning']['tasks'] ?? [], 'type')
                        : array_column($facts['planning']['requirements'] ?? [], 'fact_type');
                    $wanted = array_values(array_filter(array_map('strval', (array) ($assertion['values'] ?? []))));
                    $outcome += ['requested' => count($wanted), 'covered' => count(array_intersect($wanted, $have))];
                }
                $outcomes[] = $outcome;
            }
        }

        return [
            'failed' => $failed,
            'failure_stage' => $this->earliest(array_column($failed, 'stage')),
            'categories' => $categories,
            'outcomes' => $outcomes,
            'evidence_outcomes' => $evidenceOutcomes,
            'fact_outcomes' => $factOutcomes,
            'failure_class' => $this->classify($classifiable, $facts),
        ];
    }

    /** Rank (1-based, within the recorded top candidates) of the first page matching `$match`, or null. */
    public function rankOf(array $facts, string $match): ?int
    {
        foreach ($facts['knowledge'] ?? [] as $candidate) {
            if ($this->matches($candidate, $match)) {
                return (int) $candidate['rank'];
            }
        }

        return null;
    }

    /** kept | truncated | dropped | null (never a retrieved source). */
    public function promptFate(array $facts, string $match): ?string
    {
        foreach ((array) ($facts['citations'] ?? []) as $candidate) {
            if ($this->matches($candidate, $match)) {
                return $candidate['fate'];
            }
        }

        return null;
    }

    /**
     * @return array{0: bool, 1: mixed, 2: string} ok, what was observed, the stage to blame
     */
    private function check(string $type, array $a, array $facts, string $stage): array
    {
        $values = array_values(array_filter(array_map('strval', (array) ($a['values'] ?? []))));
        $expect = (bool) ($a['expect'] ?? true);
        $value = (string) ($a['value'] ?? '');
        $match = (string) ($a['match'] ?? '');
        $facts += ['routing' => null, 'entities' => null, 'served_by' => null, 'grounding' => null,
            'knowledge' => [], 'citations' => [], 'sources' => [], 'tools' => [], 'answer' => '', 'ai_mode' => null,
            'follow_up' => null, 'operational' => ['place_ids' => [], 'route' => false, 'events' => 0, 'warnings' => []]];
        $routing = $facts['routing'];
        $entities = $facts['entities'];
        $notReached = fn (string $what) => [false, "stage not reached: {$what} (answered by ".($facts['served_by'] ?? 'unknown').')', $stage];

        switch ($type) {
            case 'follow_up.rewritten':
                $rewritten = (bool) ($facts['follow_up']['rewritten'] ?? false);

                return [$rewritten === $expect, $facts['follow_up']['retrieval_query'] ?? null, $stage];
            case 'follow_up.contains':
                $q = (string) ($facts['follow_up']['retrieval_query'] ?? '');

                return [$this->containsFolded($q, $value), $q, $stage];
            case 'routing.domains_include':
            case 'routing.domains_exclude':
            case 'routing.tools_include':
                if ($routing === null) {
                    return $notReached('routing');
                }
                $have = $type === 'routing.tools_include' ? $routing['tools'] : $routing['domains'];
                $ok = $type === 'routing.domains_exclude'
                    ? array_intersect($values, $have) === []
                    : array_diff($values, $have) === [];

                return [$ok, $have, $stage];
            case 'routing.fallback':
                if ($routing === null) {
                    return $notReached('routing');
                }

                return [(bool) $routing['fallback'] === $expect, $routing['fallback'], $stage];
            case 'entity.resolves':
            case 'entity.not_resolves':
                if ($entities === null) {
                    return $notReached('entity resolution');
                }
                $found = collect($entities)->contains(fn ($e) => $e['id'] === ($a['entity_id'] ?? null)
                    && (empty($a['entity_type']) || $e['type'] === $a['entity_type']));
                $actual = array_map(fn ($e) => $e['type'].':'.$e['id'].' ('.$e['method'].')', $entities);

                return [$type === 'entity.resolves' ? $found : ! $found, $actual, $stage];
            case 'entity.ambiguous':
                if ($entities === null) {
                    return $notReached('entity resolution');
                }
                $ambiguous = collect($entities)->contains(fn ($e) => (bool) ($e['ambiguous'] ?? false));

                return [$ambiguous === $expect, $ambiguous, $stage];
            case 'operational.answered':
                $answered = $facts['served_by'] === 'operational';

                return [$answered === $expect, $facts['served_by'], $stage];
            case 'operational.place_ids_include':
                $ids = $facts['operational']['place_ids'];

                return [array_diff($values, $ids) === [], $ids, $stage];
            case 'operational.route':
                return [$facts['operational']['route'] === $expect, $facts['operational']['route'], $stage];
            case 'live.tool_rows':
                $rows = $facts['tools'][$value] ?? null;

                return [($rows ?? 0) > 0, $rows === null ? 'tool did not run' : "{$rows} rows", $rows === null ? 'query_planning' : $stage];
            case 'retrieval.source_in_top':
                $n = max(1, (int) ($a['n'] ?? 3));
                $rank = $this->rankOf($facts, $match);
                // Absent from every recorded candidate: retrieval never found
                // it. Found but too low: ranking.
                $blame = $rank === null ? 'knowledge_retrieval' : 'source_ranking';

                return [$rank !== null && $rank <= $n, $rank === null ? 'not in the top '.count($facts['knowledge'] ?? []) : "rank {$rank}", $blame];
            case 'retrieval.source_absent':
                $n = max(1, (int) ($a['n'] ?? 10));
                $rank = $this->rankOf($facts, $match);

                return [$rank === null || $rank > $n, $rank === null ? 'absent' : "rank {$rank}", $stage];
            case 'prompt.source_included':
            case 'prompt.source_dropped':
                $fate = $this->promptFate($facts, $match);
                if ($fate === null) {
                    // Never retrieved, so the prompt is not where it failed.
                    return [false, 'not a retrieved source', $this->rankOf($facts, $match) === null ? 'knowledge_retrieval' : 'source_ranking'];
                }
                $included = $fate !== 'dropped';

                return [$type === 'prompt.source_included' ? $included : ! $included, $fate, $stage];
            case 'citations.not_cited':
                $cited = collect($facts['sources'])->contains(fn ($s) => $this->matches($s, $match));

                return [! $cited, $cited ? 'cited' : 'not cited', $stage];
            case 'grounding.passed':
            case 'grounding.regenerated':
                $g = $facts['grounding'];
                if ($g === null) {
                    return $notReached('grounding');
                }
                $observed = $type === 'grounding.passed'
                    ? (bool) ($g['passed_first_draft'] ?? false) || (bool) ($g['regeneration_accepted'] ?? false)
                    : (bool) ($g['regenerated'] ?? false);

                return [$observed === $expect, $g, $stage];
            case 'response.contains':
            case 'response.not_contains':
                $answer = (string) $facts['answer'];
                $hit = false;
                foreach (explode('|', $value) as $alternative) {
                    $hit = $hit || $this->containsFolded($answer, $alternative);
                }
                $ok = $type === 'response.contains' ? $hit : ! $hit;

                // A withheld answer (grounding rejected it twice) is a
                // grounding outcome, not a wording one.
                $g = $facts['grounding'];
                $blame = $g !== null && ! ($g['passed_first_draft'] ?? true) && ! ($g['regeneration_accepted'] ?? false)
                    ? 'grounding' : $stage;

                return [$ok, mb_substr($answer, 0, 240), $blame];
            case 'plan.mode':
                return [($facts['planning']['mode'] ?? 'not run') === $value, $facts['planning']['mode'] ?? 'not run', $stage];
            case 'plan.task_types_include':
            case 'plan.task_types_exclude':
            case 'plan.task_count':
                $types = array_column($facts['planning']['tasks'] ?? [], 'type');
                $ok = match ($type) {
                    'plan.task_types_include' => array_diff($values, $types) === [],
                    'plan.task_types_exclude' => array_intersect($values, $types) === [],
                    default => count($types) === (int) ($a['n'] ?? -1),
                };

                return [$ok, $types, $stage];
            case 'plan.depends_on':
                $tasks = $facts['planning']['tasks'] ?? [];
                $typeOf = array_column($tasks, 'type', 'id');
                $ok = collect($tasks)->contains(fn ($t) => $t['type'] === $value
                    && collect($t['depends_on'])->contains(fn ($d) => ($typeOf[$d] ?? null) === ($a['target'] ?? null)));

                return [$ok, array_map(fn ($t) => $t['type'].' ← '.implode(',', array_map(fn ($d) => $typeOf[$d] ?? $d, $t['depends_on'])), $tasks), $stage];
            case 'plan.requirements_include':
                $have = array_values(array_unique(array_column($facts['planning']['requirements'] ?? [], 'fact_type')));

                return [array_diff($values, $have) === [], $have, $stage];
            case 'plan.provider_for':
                $route = collect($facts['planning']['routes'] ?? [])->firstWhere('fact_type', $value);

                return [($route['provider'] ?? null) === ($a['target'] ?? null), $route['provider'] ?? 'not routed', $stage];
            case 'plan.resolution_status':
                $statuses = array_column($facts['planning']['resolutions'] ?? [], 'status', 'mention');

                return [in_array($value, $statuses, true), $statuses, $stage];
            case 'plan.final_entity':
                $want = ($a['entity_type'] ?? '').':'.($a['entity_id'] ?? '');
                $finals = collect($facts['planning']['final_entities'] ?? [])->where('task_type', $value);

                return [$finals->contains(fn ($f) => $f['final'] === $want), $finals->pluck('final')->all(), $stage];
            case 'plan.task_state':
                $states = collect($facts['planning']['states'] ?? [])->where('type', $value);

                return [$states->contains(fn ($s) => $s['state'] === ($a['target'] ?? null)),
                    $states->map(fn ($s) => $s['state'].': '.$s['reason'])->values()->all(), $stage];
            case 'evidence.available':
            case 'evidence.unavailable':
            case 'evidence.source_type':
            case 'evidence.source':
            case 'evidence.temporal':
            case 'evidence.excluded':
                if (($facts['evidence'] ?? null) === null) {
                    return [false, 'evidence orchestration did not run', $stage];
                }
                $items = collect($facts['evidence']['items'])->where('fact_type', $value);
                $target = (string) ($a['target'] ?? '');
                $ok = match ($type) {
                    'evidence.available' => $items->contains(fn ($e) => $e['status'] === 'AVAILABLE'),
                    'evidence.unavailable' => $items->isNotEmpty() && ! $items->contains(fn ($e) => $e['status'] === 'AVAILABLE')
                        && ($target === '' || $items->contains(fn ($e) => $e['reason'] === $target)),
                    'evidence.source_type' => $items->contains(fn ($e) => $e['status'] === 'AVAILABLE' && $e['source_type'] === $target),
                    'evidence.source' => $items->contains(fn ($e) => $e['status'] === 'AVAILABLE' && $this->matches($e, $match)),
                    'evidence.temporal' => $items->contains(fn ($e) => $e['temporal'] === $target),
                    default => $items->contains(fn ($e) => $e['eligible'] === false
                        && ($target === '' || $this->containsFolded((string) $e['policy_reason'], $target))),
                };

                return [$ok, $items->map(fn ($e) => trim($e['status'].($e['reason'] ? ' ('.$e['reason'].')' : '').' '.($e['source_type'] ?? '')
                    .' '.($e['temporal'] ?? '').($e['eligible'] === false ? ' excluded: '.$e['policy_reason'] : '')))->values()->all(), $stage];
            case 'evidence.conflict':
            case 'evidence.winner_class':
            case 'evidence.requirement':
                if (($facts['evidence'] ?? null) === null) {
                    return [false, 'evidence orchestration did not run', $stage];
                }
                $requirements = collect($facts['evidence']['requirements'])->where('fact_type', $value);
                $items = collect($facts['evidence']['items'])->keyBy('evidence_id');
                $target = (string) ($a['target'] ?? '');
                $ok = match ($type) {
                    'evidence.conflict' => $requirements->contains(fn ($r) => $r['conflict'] === $target),
                    'evidence.winner_class' => $requirements->contains(fn ($r) => $r['winners'] !== []
                        && ($items[$r['winners'][0]]['authority_class'] ?? null) === $target),
                    default => $requirements->contains(fn ($r) => $r['coverage'] === $target),
                };

                return [$ok, $requirements->map(fn ($r) => $r['coverage'].' '.$r['conflict'].($r['rule'] ? ' ('.$r['rule'].')' : '')
                    .($r['winners'] !== [] ? ' winner '.($items[$r['winners'][0]]['authority_class'] ?? '?') : ''))->values()->all(), $stage];
            case 'evidence.outcome':
                $outcome = $facts['evidence']['outcome'] ?? 'not run';

                return [$outcome === $value, $outcome.(($facts['evidence']['classes'] ?? []) !== [] ? ' '.implode(',', $facts['evidence']['classes']) : ''), $stage];
            case 'evidence.reaches_prompt':
                if (($facts['evidence'] ?? null) === null) {
                    return [false, 'evidence orchestration did not run', $stage];
                }
                $ids = collect($facts['evidence']['items'])->where('fact_type', $value)->pluck('evidence_id')->all();
                $units = collect($facts['evidence']['budget'])->filter(fn ($b) => in_array($b['evidence_id'] ?? null, $ids, true) && $b['fate'] !== 'dropped');
                $blockFate = $facts['evidence']['tasks_block_fate'];
                if ($units->isEmpty() || $blockFate === null) {
                    return [false, $units->isEmpty() ? 'not in the evidence block' : 'task block not built', $stage];
                }
                if (! $units->contains(fn ($b) => self::unitReached($b, $facts['evidence']))) {
                    // Kept by the evidence budget, then cut with the block by the prompt budget.
                    return [false, 'in the evidence block, cut by the prompt budget (task block '.$blockFate.')', 'prompt_budget'];
                }

                return [true, 'reached the prompt (task block '.$blockFate.')', $stage];
            case 'fact.candidate':
            case 'fact.supported':
            case 'fact.none':
            case 'fact.status':
            case 'fact.source':
                if (($facts['facts'] ?? null) === null) {
                    return [false, 'supported facts were not built', $stage];
                }
                $target = (string) ($a['target'] ?? '');
                $supported = collect($facts['facts']['supported'])->where('fact_type', $value);
                $describe = fn () => $supported->map(fn ($f) => $f['fact_id'].' = '.(is_scalar($f['value']) ? $f['value'] : json_encode($f['value'], JSON_UNESCAPED_UNICODE)))->values()->all();
                switch ($type) {
                    case 'fact.candidate':
                        $have = collect($facts['facts']['candidates'])->where('fact_type', $value);

                        return [$have->isNotEmpty(), $have->pluck('candidate_id')->all() ?: 'no candidate', $stage];
                    case 'fact.supported':
                        $ok = $supported->contains(fn ($f) => $target === '' || ($f['normalized_value'] ?? null) === $target
                            || $this->containsFolded(is_scalar($f['value']) ? (string) $f['value'] : (string) json_encode($f['value'], JSON_UNESCAPED_UNICODE), $target));

                        return [$ok, $describe() ?: 'no supported fact', $stage];
                    case 'fact.none':
                        return [$supported->isEmpty(), $describe() ?: 'none', $stage];
                    case 'fact.status':
                        $statuses = collect($facts['facts']['requirements'])->where('fact_type', $value)->pluck('status');

                        return [$statuses->contains($target), $statuses->all() ?: 'no requirement', $stage];
                    default:
                        $ok = $supported->contains(fn ($f) => collect($f['sources'])->contains(fn ($s) => $this->matches($s, $match)));

                        return [$ok, $supported->flatMap(fn ($f) => array_column($f['sources'], 'url'))->filter()->values()->all() ?: 'no source', $stage];
                }
            case 'answer_plan.task':
            case 'answer_plan.outcome':
                $plan = $facts['facts']['plan'] ?? null;
                if ($plan === null) {
                    return [false, 'no answer plan', $stage];
                }
                if ($type === 'answer_plan.outcome') {
                    return [($plan['outcome'] ?? null) === $value, $plan['outcome'] ?? null, $stage];
                }
                $tasks = collect($plan['tasks'])->where('task_type', $value);

                return [$tasks->contains(fn ($t) => $t['status'] === ($a['target'] ?? null)), $tasks->pluck('status')->all() ?: 'no such task', $stage];
            case 'rollout.path':
                $path = $facts['facts']['path']['generation_path'] ?? 'not recorded';

                return [$path === $value, $path, $stage];
            case 'claim.uses_fact':
            case 'claim.full_coverage':
            case 'claim.no_unsupported':
            case 'claim.unsupported_caught':
            case 'claim.regenerated':
            case 'citation.from_fact':
            case 'citation.absent':
                $usage = $facts['facts']['usage'] ?? null;
                $verification = $facts['facts']['verification'] ?? null;
                if ($usage === null || $verification === null) {
                    return $notReached('claim verification (not generated from facts)');
                }
                switch ($type) {
                    case 'claim.uses_fact':
                        $types = collect($facts['facts']['supported'])->whereIn('fact_id', $usage['used_fact_ids'])->pluck('fact_type')->unique()->values()->all();

                        return [in_array($value, $types, true), $types ?: 'no fact used', $stage];
                    case 'claim.full_coverage':
                        $final = (array) ($verification['coverage_final'] ?? []);

                        return [($final['tasks'] ?? -1) === ($final['covered'] ?? -2), ($final['covered'] ?? '?').'/'.($final['tasks'] ?? '?').' tasks with facts covered', $stage];
                    case 'claim.no_unsupported':
                        return [($verification['final_unsupported'] ?? 1) === 0 && ! ($verification['answer_withheld'] ?? false),
                            ($verification['final_unsupported'] ?? '?').' unsupported, withheld: '.json_encode($verification['answer_withheld'] ?? null), $stage];
                    case 'claim.unsupported_caught':
                        $caught = ($verification['first_draft_unsupported'] ?? []) !== [] && ($verification['final_unsupported'] ?? 1) === 0;

                        return [$caught === $expect, array_column((array) ($verification['first_draft_unsupported'] ?? []), 'detail'), $stage];
                    case 'claim.regenerated':
                        return [(bool) ($verification['regenerated'] ?? false) === $expect, $verification['regenerated'] ?? null, $stage];
                    case 'citation.from_fact':
                        return [collect($usage['cited'])->contains(fn ($s) => $this->matches($s, $match)), array_column($usage['cited'], 'title'), $stage];
                    default:
                        return [! collect($facts['sources'])->contains(fn ($s) => $this->matches($s, $match)), array_column($facts['sources'], 'title'), $stage];
                }
            case 'response.no_fact_contradiction':
                $contradictions = app(AnswerGrounding::class)->factContradictions((string) $facts['answer']);

                return [$contradictions === [], $contradictions === [] ? 'none' : $contradictions, $stage];
            case 'response.ai_mode':
                return [in_array((string) $facts['ai_mode'], $values, true), $facts['ai_mode'], $stage];
            case 'response.served_by':
                return [in_array((string) $facts['served_by'], $values, true), $facts['served_by'], $stage];
            case 'response.warning':
                return [in_array($value, $facts['operational']['warnings'], true), $facts['operational']['warnings'], $stage];
            case 'response.has':
                $present = match ($value) {
                    'places' => $facts['operational']['place_ids'] !== [],
                    'route' => $facts['operational']['route'],
                    'events' => $facts['operational']['events'] > 0,
                    'sources' => $facts['sources'] !== [],
                    default => false,
                };

                return [$present, $present ? 'present' : 'absent', $stage];
        }

        return [false, 'unhandled', 'expected_assertion'];
    }

    /** A page or source matches when its folded title or decoded URL contains any `|`-separated alternative. */
    private function matches(array $item, string $match): bool
    {
        $haystack = TextFold::fold((string) ($item['title'] ?? '').' '.rawurldecode((string) ($item['url'] ?? '')));
        foreach (explode('|', $match) as $alternative) {
            $needle = TextFold::fold(trim($alternative));
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function containsFolded(string $haystack, string $needle): bool
    {
        $needle = TextFold::fold(trim($needle));

        return $needle !== '' && str_contains(TextFold::fold($haystack), $needle);
    }

    /** @param list<string> $stages */
    private function earliest(array $stages): ?string
    {
        if ($stages === []) {
            return null;
        }
        usort($stages, fn ($x, $y) => array_search($x, self::STAGES, true) <=> array_search($y, self::STAGES, true));

        return $stages[0];
    }

    private function describe(array $assertion): string
    {
        $params = $assertion;
        unset($params['type']);

        return $assertion['type'].' '.json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
