<?php

namespace App\Services\Ai\Evaluation;

use App\Models\AiEvaluationResult;
use Illuminate\Support\Collection;

/**
 * Factual aggregates over one run's results. No composite "quality score":
 * pass rates per assertion category, hit@k over retrieval-labelled cases,
 * prompt inclusion kept separate from rank, and latency percentiles.
 */
final class EvaluationMetrics
{
    public const DEPTHS = [1, 3, 5, 10];

    /**
     * @param  Collection<int, AiEvaluationResult>  $results
     * @return array<string, mixed>
     */
    public function compute(Collection $results): array
    {
        $evaluated = $results->where('status', '!=', AiEvaluationResult::STATUS_SKIPPED);

        $categories = [];
        foreach ($evaluated as $result) {
            foreach ((array) ($result->snapshot['categories'] ?? []) as $category => $ok) {
                $categories[$category]['total'] = ($categories[$category]['total'] ?? 0) + 1;
                $categories[$category]['passed'] = ($categories[$category]['passed'] ?? 0) + ($ok ? 1 : 0);
            }
        }
        $rates = [];
        foreach ($categories as $category => $c) {
            $rates[$category] = ['passed' => $c['passed'], 'total' => $c['total'], 'rate' => round($c['passed'] / $c['total'], 4)];
        }

        // Retrieval: rank of the labelled page, and — separately — whether it
        // reached the model. Rank 3 is not the same as "the model saw it".
        $labels = $evaluated->map(fn ($r) => $r->snapshot['retrieval_label'] ?? null)->filter()->values();
        $hits = [];
        foreach (self::DEPTHS as $k) {
            $hit = $labels->filter(fn ($l) => $l['rank'] !== null && $l['rank'] <= $k)->count();
            $hits['hit@'.$k] = ['hits' => $hit, 'total' => $labels->count(), 'rate' => $labels->isEmpty() ? null : round($hit / $labels->count(), 4)];
        }

        $stages = $evaluated->where('status', AiEvaluationResult::STATUS_FAILED)
            ->groupBy('failure_stage')->map->count()->sortDesc()->all();

        return [
            'cases' => $results->count(),
            'pass_rate' => $evaluated->isEmpty() ? null : round($evaluated->where('status', AiEvaluationResult::STATUS_PASSED)->count() / $evaluated->count(), 4),
            'categories' => $rates,
            'retrieval' => $hits + [
                'prompt_included' => $labels->filter(fn ($l) => in_array($l['prompt_fate'], ['kept', 'truncated'], true))->count(),
                'prompt_dropped' => $labels->filter(fn ($l) => $l['prompt_fate'] === 'dropped')->count(),
                'found_lexical_only' => $labels->filter(fn ($l) => $l['matched_by'] === 'lexical_only')->count(),
                'irrelevant_cited' => $evaluated->filter(fn ($r) => collect((array) $r->failed_assertions)->contains('type', 'citations.not_cited'))->count(),
            ],
            'failure_stages' => $stages,
            'phase3a' => $this->phase3a($evaluated),
            'phase3b' => $this->phase3b($evaluated),
            'phase3c' => $this->phase3c($evaluated),
            // Why failed cases failed: a data gap is not an AI regression.
            'failure_classes' => $evaluated->where('status', AiEvaluationResult::STATUS_FAILED)
                ->map(fn ($r) => $r->snapshot['failure_class'] ?? 'UNCLASSIFIED')->countBy()->sortDesc()->all(),
            'request_outcomes' => $evaluated->map(fn ($r) => $r->snapshot['evidence']['outcome'] ?? null)->filter()->countBy()->all(),
            'latency' => [
                'retrieval' => $this->percentiles($evaluated->where('mode', 'retrieval')->pluck('duration_ms')),
                'full' => $this->percentiles($evaluated->where('mode', 'full')->pluck('duration_ms')),
            ],
        ];
    }

    /**
     * Phase 3A, from per-assertion outcomes. Only stages that exist are
     * measured: planning, dependencies, requirements, routing, entity and
     * blocking — not "evidenced" or "answered" tasks, which come later.
     *
     * @return array<string, array{passed: int, total: int, rate: ?float}>
     */
    private function phase3a(Collection $results): array
    {
        $outcomes = $results->flatMap(fn ($r) => (array) ($r->snapshot['plan_outcomes'] ?? []));
        $rate = function (Collection $subset): array {
            $passed = $subset->where('ok', true)->count();

            return ['passed' => $passed, 'total' => $subset->count(), 'rate' => $subset->isEmpty() ? null : round($passed / $subset->count(), 4)];
        };
        $coverage = function (string $type) use ($outcomes): array {
            $subset = $outcomes->where('type', $type);
            $requested = (int) $subset->sum('requested');
            $covered = (int) $subset->sum('covered');

            return ['passed' => $covered, 'total' => $requested, 'rate' => $requested === 0 ? null : round($covered / $requested, 4)];
        };

        return [
            'fast_path_preservation' => $rate($outcomes->where('type', 'plan.mode')->where('value', 'fast_path')),
            'planning_mode_accuracy' => $rate($outcomes->where('type', 'plan.mode')),
            'task_coverage' => $coverage('plan.task_types_include'),
            'task_planning_accuracy' => $rate($outcomes->whereIn('type', ['plan.task_types_include', 'plan.task_types_exclude', 'plan.task_count'])),
            'dependency_correctness' => $rate($outcomes->where('type', 'plan.depends_on')),
            'requirement_coverage' => $coverage('plan.requirements_include'),
            'provider_routing_accuracy' => $rate($outcomes->where('type', 'plan.provider_for')),
            'entity_resolution' => $rate($outcomes->whereIn('type', ['plan.final_entity', 'plan.resolution_status'])),
            'task_blocking_correctness' => $rate($outcomes->where('type', 'plan.task_state')->whereIn('target', ['BLOCKED', 'FAILED'])),
        ];
    }

    /**
     * Phase 3B, kept apart from Phase 3A. Assertion-based rates measure what
     * cases expected; observed rates are read from every planned result's
     * evidence snapshot:
     *
     *  evidence_recall            evidence.available / source / source_type held
     *  evidence_precision         evidence that must NOT count did not
     *                             (evidence.unavailable, evidence.excluded)
     *  requirement_satisfaction   evidence.requirement held
     *  temporal_accuracy          evidence.temporal held
     *  conflict_accuracy          evidence.conflict / winner_class held
     *  task_evidence_coverage     observed: satisfied ÷ required requirements
     *  prompt_task_coverage       observed: tasks whose evidence reached the prompt
     *  irrelevant_evidence_rate   observed: passages policy found irrelevant ÷ passages
     *
     * @return array<string, array{passed: int, total: int, rate: ?float}>
     */
    private function phase3b(Collection $results): array
    {
        $outcomes = $results->flatMap(fn ($r) => (array) ($r->snapshot['evidence_outcomes'] ?? []));
        $rate = function (int $passed, int $total): array {
            return ['passed' => $passed, 'total' => $total, 'rate' => $total === 0 ? null : round($passed / $total, 4)];
        };
        $assertions = fn (array $types) => $rate($outcomes->whereIn('type', $types)->where('ok', true)->count(), $outcomes->whereIn('type', $types)->count());
        $snapshots = $results->map(fn ($r) => $r->snapshot['evidence'] ?? null)->filter();

        $requirements = $snapshots->flatMap(fn ($e) => (array) ($e['requirements'] ?? []));
        $tasks = $snapshots->flatMap(fn ($e) => array_map(fn ($t) => ['task' => $t, 'e' => $e], (array) ($e['tasks'] ?? [])));
        // A task reached the prompt when its evidence (or, for a blocked
        // task with nothing applicable, its outcome line) survived the
        // evidence budget AND the prompt's context budget.
        $reached = $tasks->filter(function ($t) {
            $blocked = $t['task']['required'] > 0 && $t['task']['not_applicable'] === $t['task']['required'];

            return collect((array) ($t['e']['budget'] ?? []))->contains(fn ($b) => ($b['task_id'] ?? null) === $t['task']['task_id']
                && ($blocked ? ($b['kind'] ?? null) === 'task' : ($b['evidence_id'] ?? null) !== null)
                && AssertionEvaluator::unitReached($b, $t['e']));
        });
        $passages = $snapshots->flatMap(fn ($e) => array_filter((array) ($e['items'] ?? []), fn ($i) => in_array($i['source_type'], ['official_web', 'official_pdf'], true)));
        $irrelevant = $passages->filter(fn ($i) => $i['eligible'] === false && str_contains((string) $i['policy_reason'], 'passage does not'));

        return [
            'evidence_recall' => $assertions(['evidence.available', 'evidence.source', 'evidence.source_type']),
            'evidence_precision' => $assertions(['evidence.unavailable', 'evidence.excluded']),
            'requirement_satisfaction' => $assertions(['evidence.requirement']),
            'temporal_accuracy' => $assertions(['evidence.temporal']),
            'conflict_accuracy' => $assertions(['evidence.conflict', 'evidence.winner_class']),
            'outcome_accuracy' => $assertions(['evidence.outcome']),
            'task_evidence_coverage' => $rate($requirements->where('coverage', 'satisfied')->count(), $requirements->where('coverage', '!=', 'not_applicable')->count()),
            'prompt_task_coverage' => $rate($reached->count(), $tasks->count()),
            'irrelevant_evidence_rate' => $rate($irrelevant->count(), $passages->count()),
        ];
    }

    /**
     * Phase 3C, kept apart from 3A and 3B. Assertion-based rates measure what
     * cases expected; observed rates come from every result's fact snapshot
     * (generation rates only from answers generated from facts):
     *
     *  fact_extraction_recall          fact.candidate / fact.supported (any value) held
     *  fact_extraction_precision       fact.supported with an expected value held
     *  supported_fact_precision        facts that must NOT be supported were not
     *                                  (fact.none, fact.status other than SUPPORTED)
     *  requirement_factual_satisfaction observed: SUPPORTED ÷ applicable requirements
     *  unsupported_fact_rejection_rate observed: candidates and passages rejected ÷ examined
     *  claim_support_rate              observed: first-draft claims supported ÷ all first-draft claims
     *  unsupported_claim_rate          observed: unsupported claims left in final answers ÷ final claims
     *  citation_precision              observed: citations backing a used fact ÷ citations
     *  task_answer_coverage            observed: tasks with facts covered by the FINAL answer
     *  task_coverage_after_removal     observed: the same, after removal but before restatement
     *  restated_facts / speculative_claims_removed / technical_fallbacks   counts (rate null)
     *  regeneration_rate               observed: answers regenerated by claim verification
     *  model_calls_per_answer          observed: mean model attempts per fact-generated answer (count, not rate)
     *
     * @return array<string, array{passed: int|float, total: int, rate: ?float}>
     */
    private function phase3c(Collection $results): array
    {
        $outcomes = $results->flatMap(fn ($r) => (array) ($r->snapshot['fact_outcomes'] ?? []));
        $rate = fn (int $passed, int $total): array => ['passed' => $passed, 'total' => $total, 'rate' => $total === 0 ? null : round($passed / $total, 4)];
        $assertions = function (callable $filter) use ($outcomes, $rate): array {
            $subset = $outcomes->filter($filter);

            return $rate($subset->where('ok', true)->count(), $subset->count());
        };
        $facts = $results->map(fn ($r) => ['f' => $r->snapshot['facts'] ?? null, 'attempts' => count((array) ($r->snapshot['model_attempts'] ?? []))])
            ->filter(fn ($x) => $x['f'] !== null);
        $requirements = $facts->flatMap(fn ($x) => (array) $x['f']['requirements']);
        $generated = $facts->filter(fn ($x) => ($x['f']['verification'] ?? null) !== null);
        $verifications = $generated->map(fn ($x) => $x['f']['verification']);
        $firstClaims = (int) $verifications->sum(fn ($v) => (int) ($v['first_draft_claims'] ?? 0));
        $firstUnsupported = (int) $verifications->sum(fn ($v) => count((array) ($v['first_draft_unsupported'] ?? [])));
        $finalClaims = (int) $verifications->sum(fn ($v) => (int) ($v['final_claims'] ?? 0));
        $finalUnsupported = (int) $verifications->sum(fn ($v) => (int) ($v['final_unsupported'] ?? 0));
        $cited = $generated->flatMap(fn ($x) => (array) ($x['f']['usage']['cited'] ?? []));
        // Coverage of the FINAL answer, recomputed after unsupported sentences
        // were removed (and after deterministic restatement).
        $coverage = fn (string $key) => [
            (int) $verifications->sum(fn ($v) => (int) ($v[$key]['covered'] ?? 0)),
            (int) $verifications->sum(fn ($v) => (int) ($v[$key]['tasks'] ?? 0)),
        ];
        [$finalCovered, $finalTasks] = $coverage('coverage_final');
        [$removalCovered, $removalTasks] = $coverage('coverage_after_removal');
        $rejected = $requirements->sum(fn ($r) => count($r['rejected']));
        $accepted = $requirements->sum(fn ($r) => count($r['fact_ids']));

        return [
            'fact_extraction_recall' => $assertions(fn ($o) => $o['type'] === 'fact.candidate' || ($o['type'] === 'fact.supported' && ($o['target'] ?? '') === '')),
            'fact_extraction_precision' => $assertions(fn ($o) => $o['type'] === 'fact.supported' && ($o['target'] ?? '') !== ''),
            'supported_fact_precision' => $assertions(fn ($o) => $o['type'] === 'fact.none' || ($o['type'] === 'fact.status' && $o['target'] !== 'SUPPORTED')),
            'requirement_factual_satisfaction' => $rate($requirements->where('status', 'SUPPORTED')->count(), $requirements->where('status', '!=', 'NOT_APPLICABLE')->count()),
            'unsupported_fact_rejection_rate' => $rate((int) $rejected, (int) ($rejected + $accepted)),
            'claim_support_rate' => $rate($firstClaims, $firstClaims + $firstUnsupported),
            'unsupported_claim_rate' => $rate($finalUnsupported, $finalClaims + $finalUnsupported),
            'citation_precision' => $rate($cited->filter(fn ($s) => ($s['fact_ids'] ?? []) !== [])->count(), $cited->count()),
            'task_answer_coverage' => $rate($finalCovered, $finalTasks),
            'task_coverage_after_removal' => $rate($removalCovered, $removalTasks),
            'restated_facts' => ['passed' => (int) $verifications->sum(fn ($v) => count((array) ($v['supplemented_fact_ids'] ?? []))), 'total' => $verifications->count(), 'rate' => null],
            'speculative_claims_removed' => ['passed' => (int) $verifications->sum(fn ($v) => (int) ($v['speculative_removed'] ?? 0)), 'total' => $verifications->count(), 'rate' => null],
            'technical_fallbacks' => ['passed' => $facts->filter(fn ($x) => ($x['f']['path']['fallback_reason'] ?? null) !== null)->count(), 'total' => $facts->count(), 'rate' => null],
            'regeneration_rate' => $rate($verifications->filter(fn ($v) => $v['regenerated'] ?? false)->count(), $verifications->count()),
            'model_calls_per_answer' => ['passed' => (int) $generated->sum('attempts'), 'total' => $generated->count(),
                'rate' => $generated->isEmpty() ? null : round($generated->sum('attempts') / $generated->count(), 2)],
        ];
    }

    /** @return array{median: ?int, p95: ?int, n: int} */
    private function percentiles(Collection $values): array
    {
        $sorted = $values->filter(fn ($v) => $v !== null)->map(fn ($v) => (int) $v)->sort()->values();
        if ($sorted->isEmpty()) {
            return ['median' => null, 'p95' => null, 'n' => 0];
        }
        $at = fn (float $p) => $sorted[(int) min($sorted->count() - 1, (int) ceil($p * $sorted->count()) - 1)];

        return ['median' => $at(0.5), 'p95' => $at(0.95), 'n' => $sorted->count()];
    }
}
