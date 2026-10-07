<?php

namespace App\Console\Commands;

use App\Models\AiEvaluationCase;
use App\Models\AiEvaluationResult;
use App\Models\AiEvaluationRun;
use App\Services\Ai\Evaluation\EvaluationComparator;
use App\Services\Ai\Evaluation\EvaluationRunner;
use Illuminate\Console\Command;

/**
 * Runs the persisted AICAD evaluation suite (admin → AICAD Tests).
 *
 *   php artisan ask:evaluate --retrieval-only          fast; no model, no web — the CI profile
 *   php artisan ask:evaluate --full --as=you@arucad.edu.tr
 *   php artisan ask:evaluate --tag=regression --tag=tr
 *   php artisan ask:evaluate --id=12 --id=15
 *   php artisan ask:evaluate --compare=41,42
 *
 * Exit code 1 when any case fails, except cases tagged `model_sensitive`
 * (reported, not fatal) unless --strict. Not to be confused with the older
 * file-based `ask:eval`.
 */
class RunAskEvaluation extends Command
{
    protected $signature = 'ask:evaluate
        {--retrieval-only : Only retrieval cases (no model or web calls)}
        {--full : Only full-answer cases (calls the configured model)}
        {--tag=* : Only cases carrying one of these tags}
        {--id=* : Only these case ids (inactive ones included)}
        {--as= : E-mail of the user full-answer cases run as}
        {--strict : model_sensitive failures also fail the exit code}
        {--compare= : Compare two run ids, BASELINE,CURRENT, instead of running}
        {--json : Print the run (or comparison) as JSON}';

    protected $description = 'Run the AICAD evaluation suite and report failures by pipeline stage';

    public function handle(EvaluationRunner $runner, EvaluationComparator $comparator): int
    {
        if ($this->option('compare')) {
            return $this->compare($comparator);
        }
        if ($this->option('retrieval-only') && $this->option('full')) {
            $this->error('Choose --retrieval-only or --full, not both.');

            return self::INVALID;
        }

        $mode = $this->option('retrieval-only') ? 'retrieval' : ($this->option('full') ? 'full' : 'all');
        $scope = array_filter([
            'case_ids' => array_map('intval', (array) $this->option('id')),
            'tags' => (array) $this->option('tag'),
            'label' => 'cli',
        ]);
        $run = $runner->createRun($mode, $scope, $this->option('as') ?: null);

        $count = $runner->casesFor($run)->count();
        if ($count === 0) {
            $this->warn('No matching evaluation cases. Seed them with: php artisan db:seed --class=AiEvaluationCaseSeeder');
            $run->delete();

            return self::FAILURE;
        }
        $this->info("Run #{$run->id}: {$count} case(s), mode {$mode}");

        $run = $runner->execute($run, function (AiEvaluationResult $r): void {
            $mark = match ($r->status) {
                'passed' => '<info>PASS</info>',
                'skipped' => '<comment>SKIP</comment>',
                default => '<error>FAIL</error>',
            };
            $this->line(sprintf('%s %-6s %5sms  %s%s', $mark, $r->mode, $r->duration_ms ?? '-', $r->case_name,
                $r->failure_stage ? "  [{$r->failure_stage}]" : ''));
            foreach ((array) $r->failed_assertions as $f) {
                $this->line('       '.$f['expected'].' → '.json_encode($f['actual'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            if ($r->status === 'skipped') {
                $this->line('       '.($r->snapshot['skip_reason'] ?? ''));
            }
        });

        if ($this->option('json')) {
            $this->line((string) json_encode($run->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $this->summary($run);

        if ($run->status === AiEvaluationRun::STATUS_FAILED) {
            $this->error('Run aborted: '.$run->error);

            return self::FAILURE;
        }

        $fatal = $run->results()->where('status', AiEvaluationResult::STATUS_FAILED)->get()
            ->filter(fn (AiEvaluationResult $r) => $this->option('strict')
                || ! (AiEvaluationCase::query()->find($r->case_id)?->isModelSensitive() ?? false))
            ->count();

        return $fatal === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function summary(AiEvaluationRun $run): void
    {
        $m = (array) $run->metrics;
        $this->newLine();
        $this->line(sprintf('Run #%d — passed %d, failed %d, skipped %d, %.1fs (commit %s, model %s, retrieval %s)',
            $run->id, $run->passed, $run->failed, $run->skipped, ($run->duration_ms ?? 0) / 1000,
            substr((string) $run->git_commit, 0, 8) ?: '—', $run->local_model ?: '—', $run->retrieval_fingerprint));
        if (! empty($m['failure_stages'])) {
            $this->line('Failures by stage: '.collect($m['failure_stages'])->map(fn ($n, $s) => "{$s} {$n}")->implode(', '));
        }
        $hits = collect($m['retrieval'] ?? [])->filter(fn ($v, $k) => str_starts_with($k, 'hit@') && $v['total'] > 0);
        if ($hits->isNotEmpty()) {
            $this->line('Retrieval: '.$hits->map(fn ($v, $k) => sprintf('%s %d/%d', $k, $v['hits'], $v['total']))->implode(', ')
                .sprintf(' | in prompt %d, dropped %d, irrelevant cited %d',
                    $m['retrieval']['prompt_included'], $m['retrieval']['prompt_dropped'], $m['retrieval']['irrelevant_cited']));
        }
        if (! empty($m['failure_classes'])) {
            $this->line('Failure classes: '.collect($m['failure_classes'])->map(fn ($n, $c) => "{$c} {$n}")->implode(', '));
        }
        if (! empty($m['request_outcomes'])) {
            $this->line('Request outcomes: '.collect($m['request_outcomes'])->map(fn ($n, $o) => "{$o} {$n}")->implode(', '));
        }
        $phase3b = collect($m['phase3b'] ?? [])->filter(fn ($v) => $v['total'] > 0);
        if ($phase3b->isNotEmpty()) {
            $this->line('Phase 3B: '.$phase3b->map(fn ($v, $k) => sprintf('%s %d/%d', str_replace('_', ' ', $k), $v['passed'], $v['total']))->implode(', '));
        }
        $phase3c = collect($m['phase3c'] ?? [])->filter(fn ($v) => $v['total'] > 0);
        if ($phase3c->isNotEmpty()) {
            $this->line('Phase 3C: '.$phase3c->map(fn ($v, $k) => $k === 'model_calls_per_answer'
                ? sprintf('model calls per answer %s (n=%d)', $v['rate'], $v['total'])
                : sprintf('%s %d/%d', str_replace('_', ' ', $k), $v['passed'], $v['total']))->implode(', '));
        }
        foreach (['retrieval', 'full'] as $mode) {
            if (($m['latency'][$mode]['n'] ?? 0) > 0) {
                $this->line(sprintf('Latency %s: median %dms, p95 %dms (n=%d)', $mode, $m['latency'][$mode]['median'], $m['latency'][$mode]['p95'], $m['latency'][$mode]['n']));
            }
        }
    }

    private function compare(EvaluationComparator $comparator): int
    {
        [$a, $b] = array_pad(array_map('intval', explode(',', (string) $this->option('compare'))), 2, 0);
        $baseline = AiEvaluationRun::query()->find($a);
        $current = AiEvaluationRun::query()->find($b);
        if ($baseline === null || $current === null) {
            $this->error('Both runs must exist: --compare=BASELINE_ID,CURRENT_ID');

            return self::INVALID;
        }
        $diff = $comparator->compare($baseline, $current);
        if ($this->option('json')) {
            $this->line((string) json_encode($diff, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Run #{$a} → #{$b}");
        foreach (['newly_failing' => 'error', 'newly_passing' => 'info', 'unchanged_failing' => 'comment'] as $key => $style) {
            $this->line("<{$style}>".str_replace('_', ' ', $key).': '.count($diff[$key])."</{$style}>");
            foreach ($diff[$key] as $row) {
                $this->line("  #{$row['case_id']} {$row['name']}  ".($row['stage_before'] ?? 'pass').' → '.($row['stage_after'] ?? 'pass'));
            }
        }
        $this->line('unchanged passing: '.$diff['unchanged_passing']);
        $this->table(['metric', 'before', 'after'], array_map(fn ($r) => [$r['metric'], $r['before'] ?? '—', $r['after'] ?? '—'], $diff['metrics']));

        return count($diff['newly_failing']) === 0 ? self::SUCCESS : self::FAILURE;
    }
}
