<?php

namespace App\Services\Ai\Evaluation;

/**
 * Proposes assertions for "Save as Evaluation Test" from a Playground report.
 *
 * A proposal, not a snapshot: the admin edits it before saving. It is
 * deliberately loose where runtime detail is incidental — the page ranked
 * first becomes "in the top 3", never a score; event ids are never proposed
 * (events come and go); only canonical place/entity ids are.
 */
final class AssertionSuggester
{
    public function __construct(private readonly AssertionEvaluator $evaluator) {}

    /**
     * @param  array<string, mixed>  $report  AskDiagnostics::run()
     * @return list<array<string, mixed>>
     */
    public function suggest(array $report): array
    {
        $facts = $this->evaluator->observe($report);
        $out = [];

        if ($facts['follow_up']['rewritten'] ?? false) {
            $out[] = ['type' => 'follow_up.rewritten', 'expect' => true];
        }

        if ($facts['routing'] !== null) {
            if ($facts['routing']['fallback']) {
                $out[] = ['type' => 'routing.fallback', 'expect' => true];
            } elseif ($facts['routing']['domains'] !== []) {
                // The strongest domains only; incidental extra ones are not a contract.
                $out[] = ['type' => 'routing.domains_include', 'values' => array_slice($facts['routing']['domains'], 0, 2)];
            }
        }

        $entities = (array) ($facts['entities'] ?? []);
        if (collect($entities)->contains(fn ($e) => $e['ambiguous'] ?? false)) {
            $out[] = ['type' => 'entity.ambiguous', 'expect' => true];
        } elseif (($top = $entities[0] ?? null) !== null && $top['score'] >= 0.9) {
            $out[] = ['type' => 'entity.resolves', 'entity_type' => $top['type'], 'entity_id' => $top['id']];
        }

        if ($facts['served_by'] === 'operational') {
            $out[] = ['type' => 'response.served_by', 'values' => ['operational']];
            if ($facts['operational']['place_ids'] !== []) {
                $out[] = ['type' => 'operational.place_ids_include', 'values' => $facts['operational']['place_ids']];
            }
            if ($facts['operational']['route']) {
                $out[] = ['type' => 'operational.route', 'expect' => true];
            }
        } elseif (($first = $facts['knowledge'][0] ?? null) !== null && ! empty($first['url'])) {
            $match = (string) parse_url((string) $first['url'], PHP_URL_PATH);
            $match = trim($match, '/') !== '' ? $match : (string) $first['url'];
            $out[] = ['type' => 'retrieval.source_in_top', 'match' => $match, 'n' => 3];
            $out[] = ['type' => 'prompt.source_included', 'match' => $match];
        }

        // Phase 3A: the planning decision, and for a planned question its
        // task types, evidence types and dependencies — by type, never by id.
        if (($planning = $facts['planning'] ?? null) !== null && $planning['mode'] !== 'disabled') {
            $out[] = ['type' => 'plan.mode', 'value' => $planning['mode']];
            if ($planning['mode'] === 'planned') {
                $types = array_values(array_unique(array_column($planning['tasks'], 'type')));
                $out[] = ['type' => 'plan.task_types_include', 'values' => $types];
                $out[] = ['type' => 'plan.requirements_include',
                    'values' => array_values(array_unique(array_column($planning['requirements'], 'fact_type')))];
                $typeOf = array_column($planning['tasks'], 'type', 'id');
                foreach ($planning['tasks'] as $task) {
                    foreach ($task['depends_on'] as $dependency) {
                        $out[] = ['type' => 'plan.depends_on', 'value' => $task['type'], 'target' => $typeOf[$dependency] ?? $dependency];
                    }
                }
            }
        }

        // Phase 3B: the request outcome and each fact type's coverage — what
        // the data could and could not support, never evidence ids.
        if (($evidence = $facts['evidence'] ?? null) !== null && $evidence['outcome'] !== null) {
            $out[] = ['type' => 'evidence.outcome', 'value' => $evidence['outcome']];
            foreach (collect($evidence['requirements'])->unique('fact_type') as $requirement) {
                $out[] = ['type' => 'evidence.requirement', 'value' => $requirement['fact_type'], 'target' => $requirement['coverage']];
            }
        }

        // Phase 3C: each task's answer-plan status and the fact types that
        // were (or, as importantly, were not) supported.
        if (($factFacts = $facts['facts'] ?? null) !== null && ($factFacts['plan'] ?? null) !== null) {
            foreach ($factFacts['plan']['tasks'] as $task) {
                $out[] = ['type' => 'answer_plan.task', 'value' => $task['task_type'], 'target' => $task['status']];
            }
            foreach (collect($factFacts['requirements'])->unique('fact_type') as $requirement) {
                $out[] = $requirement['status'] === 'SUPPORTED'
                    ? ['type' => 'fact.supported', 'value' => $requirement['fact_type']]
                    : ['type' => 'fact.status', 'value' => $requirement['fact_type'], 'target' => $requirement['status']];
            }
        }

        if ($facts['grounding'] !== null) {
            $out[] = ['type' => 'grounding.passed', 'expect' => true];
        }
        if (($report['mode'] ?? '') === 'full' && $facts['ai_mode'] !== null) {
            $out[] = ['type' => 'response.ai_mode', 'values' => [$facts['ai_mode']]];
        }

        return $out;
    }
}
