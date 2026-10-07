<?php

namespace App\Services\Ai\Evaluation;

use App\Models\AiEvaluationResult;
use App\Models\AiEvaluationRun;

/**
 * "What became better or worse after this change?" — case by case and in
 * the aggregates, between a baseline run and a later one.
 */
final class EvaluationComparator
{
    /**
     * @return array{
     *     newly_passing: list<array<string, mixed>>, newly_failing: list<array<string, mixed>>,
     *     unchanged_passing: int, unchanged_failing: list<array<string, mixed>>,
     *     only_in_baseline: int, only_in_current: int, metrics: list<array<string, mixed>>,
     *     fingerprints: array<string, array{0: ?string, 1: ?string}>
     * }
     */
    public function compare(AiEvaluationRun $baseline, AiEvaluationRun $current): array
    {
        $before = $baseline->results()->whereNotNull('case_id')->get()->keyBy('case_id');
        $after = $current->results()->whereNotNull('case_id')->get()->keyBy('case_id');

        $out = ['newly_passing' => [], 'newly_failing' => [], 'unchanged_passing' => 0, 'unchanged_failing' => [],
            'only_in_baseline' => $before->diffKeys($after)->count(), 'only_in_current' => $after->diffKeys($before)->count()];

        foreach ($after as $caseId => $now) {
            $was = $before->get($caseId);
            if ($was === null || $now->status === AiEvaluationResult::STATUS_SKIPPED || $was->status === AiEvaluationResult::STATUS_SKIPPED) {
                continue;
            }
            $row = ['case_id' => $caseId, 'name' => $now->case_name, 'question' => $now->question,
                'stage_before' => $was->failure_stage, 'stage_after' => $now->failure_stage,
                'result_id' => $now->id];
            $passedBefore = $was->status === AiEvaluationResult::STATUS_PASSED;
            $passedNow = $now->status === AiEvaluationResult::STATUS_PASSED;
            match (true) {
                ! $passedBefore && $passedNow => $out['newly_passing'][] = $row,
                $passedBefore && ! $passedNow => $out['newly_failing'][] = $row,
                $passedBefore && $passedNow => $out['unchanged_passing']++,
                default => $out['unchanged_failing'][] = $row,
            };
        }

        $out['metrics'] = $this->metricRows((array) $baseline->metrics, (array) $current->metrics);
        $out['fingerprints'] = [];
        foreach (['git_commit', 'local_model', 'embedding_model', 'retrieval_fingerprint', 'prompt_fingerprint'] as $field) {
            $out['fingerprints'][$field] = [$baseline->{$field}, $current->{$field}];
        }

        return $out;
    }

    /**
     * `kind` says how to read a number: JSON turns 1.0 into 1, so a rate
     * cannot be told from a count by its type once stored. `rate_lower` is
     * a rate that improves as it falls.
     *
     * @return list<array{metric: string, kind: string, before: mixed, after: mixed}>
     */
    private function metricRows(array $a, array $b): array
    {
        $rows = [['metric' => 'pass rate', 'kind' => 'rate', 'before' => $a['pass_rate'] ?? null, 'after' => $b['pass_rate'] ?? null]];
        $categories = array_unique(array_merge(array_keys($a['categories'] ?? []), array_keys($b['categories'] ?? [])));
        sort($categories);
        foreach ($categories as $category) {
            $rows[] = ['metric' => $category.' pass rate', 'kind' => 'rate', 'before' => $a['categories'][$category]['rate'] ?? null, 'after' => $b['categories'][$category]['rate'] ?? null];
        }
        foreach (EvaluationMetrics::DEPTHS as $k) {
            $rows[] = ['metric' => 'hit@'.$k, 'kind' => 'rate', 'before' => $a['retrieval']['hit@'.$k]['rate'] ?? null, 'after' => $b['retrieval']['hit@'.$k]['rate'] ?? null];
        }
        foreach (['prompt_included', 'prompt_dropped', 'found_lexical_only', 'irrelevant_cited'] as $key) {
            $rows[] = ['metric' => str_replace('_', ' ', $key), 'kind' => 'count', 'before' => $a['retrieval'][$key] ?? null, 'after' => $b['retrieval'][$key] ?? null];
        }
        foreach (array_unique(array_merge(array_keys($a['phase3a'] ?? []), array_keys($b['phase3a'] ?? []))) as $key) {
            $rows[] = ['metric' => str_replace('_', ' ', $key), 'kind' => 'rate',
                'before' => $a['phase3a'][$key]['rate'] ?? null, 'after' => $b['phase3a'][$key]['rate'] ?? null];
        }
        foreach (array_unique(array_merge(array_keys($a['phase3b'] ?? []), array_keys($b['phase3b'] ?? []))) as $key) {
            // An irrelevant-evidence rate improves as it falls.
            $rows[] = ['metric' => '3b '.str_replace('_', ' ', $key), 'kind' => $key === 'irrelevant_evidence_rate' ? 'rate_lower' : 'rate',
                'before' => $a['phase3b'][$key]['rate'] ?? null, 'after' => $b['phase3b'][$key]['rate'] ?? null];
        }
        foreach (array_unique(array_merge(array_keys($a['phase3c'] ?? []), array_keys($b['phase3c'] ?? []))) as $key) {
            $rows[] = ['metric' => '3c '.str_replace('_', ' ', $key),
                'kind' => match ($key) {
                    'unsupported_claim_rate', 'regeneration_rate' => 'rate_lower',
                    'model_calls_per_answer', 'restated_facts', 'speculative_claims_removed', 'technical_fallbacks' => 'count',
                    default => 'rate',
                },
                // Pure counts carry no rate; compare the count itself.
                'before' => $a['phase3c'][$key]['rate'] ?? $a['phase3c'][$key]['passed'] ?? null,
                'after' => $b['phase3c'][$key]['rate'] ?? $b['phase3c'][$key]['passed'] ?? null];
        }
        foreach (['retrieval', 'full'] as $mode) {
            foreach (['median', 'p95'] as $p) {
                $rows[] = ['metric' => "{$mode} latency {$p} (ms)", 'kind' => 'ms', 'before' => $a['latency'][$mode][$p] ?? null, 'after' => $b['latency'][$mode][$p] ?? null];
            }
        }

        return $rows;
    }
}
