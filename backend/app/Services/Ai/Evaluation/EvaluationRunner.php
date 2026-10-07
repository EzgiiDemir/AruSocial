<?php

namespace App\Services\Ai\Evaluation;

use App\Models\AiEntityAlias;
use App\Models\AiEvaluationCase;
use App\Models\AiEvaluationResult;
use App\Models\AiEvaluationRun;
use App\Models\User;
use App\Services\Ai\AskDiagnostics;
use App\Services\Ai\AskPromptBuilder;
use App\Services\Ai\InstitutionProfile;
use App\Services\Ai\QueryPlanner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Executes evaluation cases and persists one result per case.
 *
 * Every case goes through AskDiagnostics — the same code the Search
 * Playground and the API use — so a follow-up is resolved by the real
 * FollowUpQuery, an operational question by the real AskOperations, and a
 * full-answer case by the real AiController. Retrieval cases never call the
 * model or web research.
 *
 * One run, one result per case: nothing is retried until it passes.
 */
final class EvaluationRunner
{
    public function __construct(
        private readonly AskDiagnostics $diagnostics,
        private readonly AssertionEvaluator $evaluator,
    ) {}

    /**
     * Create a queued run for a selection.
     *
     * @param  array{case_ids?: list<int>, tags?: list<string>, label?: string}  $scope
     */
    public function createRun(string $mode, array $scope = [], ?string $initiatedBy = null): AiEvaluationRun
    {
        return AiEvaluationRun::create([
            'status' => AiEvaluationRun::STATUS_QUEUED,
            'mode' => $mode,
            'scope' => $scope,
            'initiated_by' => $initiatedBy,
        ]);
    }

    /** The active cases a run covers. */
    public function casesFor(AiEvaluationRun $run): Collection
    {
        $scope = (array) $run->scope;

        return AiEvaluationCase::query()
            ->when(! empty($scope['case_ids']), fn (Builder $q) => $q->whereIn('id', $scope['case_ids']))
            ->when(empty($scope['case_ids']), fn (Builder $q) => $q->where('active', true))
            ->when($run->mode !== 'all', fn (Builder $q) => $q->where('mode', $run->mode))
            ->orderBy('id')
            ->get()
            ->filter(fn (AiEvaluationCase $c) => empty($scope['tags'])
                || array_intersect((array) $scope['tags'], (array) $c->tags) !== [])
            ->values();
    }

    /** @param  callable(AiEvaluationResult): void|null  $progress */
    public function execute(AiEvaluationRun $run, ?callable $progress = null): AiEvaluationRun
    {
        $started = microtime(true);
        $run->forceFill(array_merge($this->environment(), [
            'status' => AiEvaluationRun::STATUS_RUNNING,
            'started_at' => now(),
        ]))->save();

        // A full-answer case tests generation; a cached answer would hide it.
        // On-demand page fetching is off too: it hits the network and writes
        // to the index mid-run, so case 40 would see a corpus case 1 did not.
        $cache = config('ai.cache.enabled');
        $onDemand = config('knowledge.on_demand_enabled');
        config(['ai.cache.enabled' => false, 'knowledge.on_demand_enabled' => false]);

        try {
            foreach ($this->casesFor($run) as $case) {
                $result = $this->runCase($run, $case);
                if ($progress !== null) {
                    $progress($result);
                }
            }
            $status = AiEvaluationRun::STATUS_FINISHED;
            $error = null;
        } catch (Throwable $e) {
            $status = AiEvaluationRun::STATUS_FAILED;
            $error = mb_substr($e->getMessage(), 0, 480);
        } finally {
            config(['ai.cache.enabled' => $cache, 'knowledge.on_demand_enabled' => $onDemand]);
        }

        $results = $run->results()->get();
        $run->forceFill([
            'status' => $status,
            'error' => $error,
            'finished_at' => now(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'passed' => $results->where('status', AiEvaluationResult::STATUS_PASSED)->count(),
            'failed' => $results->where('status', AiEvaluationResult::STATUS_FAILED)->count(),
            'skipped' => $results->where('status', AiEvaluationResult::STATUS_SKIPPED)->count(),
            'metrics' => (new EvaluationMetrics)->compute($results),
        ])->save();

        return $run;
    }

    public function runCase(AiEvaluationRun $run, AiEvaluationCase $case): AiEvaluationResult
    {
        $base = [
            'run_id' => $run->id, 'case_id' => $case->id, 'case_name' => $case->name,
            'question' => $case->question, 'mode' => $case->mode,
        ];

        if (empty($case->assertions)) {
            return AiEvaluationResult::create($base + [
                'status' => AiEvaluationResult::STATUS_SKIPPED,
                'snapshot' => ['skip_reason' => 'no assertions'],
            ]);
        }

        $user = null;
        if ($case->mode === AiEvaluationCase::MODE_FULL) {
            $user = $this->userFor($case, $run);
            if ($user === null) {
                return AiEvaluationResult::create($base + [
                    'status' => AiEvaluationResult::STATUS_SKIPPED,
                    'snapshot' => ['skip_reason' => 'full-answer case needs a user: set context_user_email, run it from the admin panel, or set AI_EVALUATION_USER'],
                ]);
            }
        }

        $started = microtime(true);
        try {
            $report = $this->diagnostics->run(
                (string) $case->question,
                $case->mode === AiEvaluationCase::MODE_FULL ? AskDiagnostics::MODE_FULL : AskDiagnostics::MODE_RETRIEVAL,
                $this->history($case),
                false,
                $user,
            );
        } catch (Throwable $e) {
            return AiEvaluationResult::create($base + [
                'status' => AiEvaluationResult::STATUS_FAILED,
                'failure_stage' => 'expected_assertion',
                'failed_assertions' => [['type' => 'run', 'expected' => 'case runs', 'actual' => mb_substr($e->getMessage(), 0, 400), 'stage' => 'expected_assertion']],
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        }
        $duration = (int) round((microtime(true) - $started) * 1000);

        $facts = $this->evaluator->observe($report);

        // The model could not be reached at all: an environment problem, not
        // a regression. Reported as skipped with the reason, never as a pass.
        if ($case->mode === AiEvaluationCase::MODE_FULL && $facts['served_by'] === 'model'
            && ($facts['model']['has_text'] ?? true) === false) {
            return AiEvaluationResult::create($base + [
                'status' => AiEvaluationResult::STATUS_SKIPPED,
                'snapshot' => $facts + ['skip_reason' => 'model unavailable ('.($facts['model']['status'] ?? 'unknown').')'],
                'trace_id' => $report['trace_id'],
                'duration_ms' => $duration,
            ]);
        }

        $outcome = $this->evaluator->evaluate((array) $case->assertions, $facts);
        $facts['categories'] = $outcome['categories'];
        $facts['plan_outcomes'] = $outcome['outcomes'];
        $facts['evidence_outcomes'] = $outcome['evidence_outcomes'];
        $facts['fact_outcomes'] = $outcome['fact_outcomes'];
        $facts['failure_class'] = $outcome['failure_class'];
        $facts['retrieval_label'] = $this->retrievalLabel((array) $case->assertions, $facts);

        return AiEvaluationResult::create($base + [
            'status' => $outcome['failed'] === [] ? AiEvaluationResult::STATUS_PASSED : AiEvaluationResult::STATUS_FAILED,
            'failure_stage' => $outcome['failure_stage'],
            'failed_assertions' => $outcome['failed'],
            'snapshot' => $facts,
            'trace_id' => $report['trace_id'],
            'duration_ms' => $duration,
        ]);
    }

    /**
     * For hit@k: where the case's expected page ranked and whether it reached
     * the prompt. Uses the first source_in_top assertion, as the benchmark
     * used one expected page per question.
     *
     * @param  list<array<string, mixed>>  $assertions
     * @return array{rank: ?int, prompt_fate: ?string, matched_by: ?string}|null
     */
    private function retrievalLabel(array $assertions, array $facts): ?array
    {
        foreach ($assertions as $a) {
            if (($a['type'] ?? '') === 'retrieval.source_in_top') {
                $rank = $this->evaluator->rankOf($facts, (string) $a['match']);
                $matchedBy = null;
                foreach ($facts['knowledge'] as $c) {
                    if ($c['rank'] === $rank) {
                        $matchedBy = $c['matched_by'];
                    }
                }

                return [
                    'rank' => $rank,
                    'prompt_fate' => $this->evaluator->promptFate($facts, (string) $a['match']),
                    'matched_by' => $matchedBy,
                ];
            }
        }

        return null;
    }

    /** @return list<array{role: string, content: string}> */
    private function history(AiEvaluationCase $case): array
    {
        return array_values(array_filter(array_map(fn ($turn) => [
            'role' => ($turn['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user',
            'content' => trim((string) ($turn['content'] ?? '')),
        ], (array) $case->previous_turns), fn ($turn) => $turn['content'] !== ''));
    }

    private function userFor(AiEvaluationCase $case, AiEvaluationRun $run): ?User
    {
        foreach ([$case->context_user_email, $run->initiated_by, config('ai.evaluation.user_email')] as $email) {
            if (is_string($email) && $email !== '') {
                $user = User::query()->where('email', $email)->first();
                if ($user !== null) {
                    return $user;
                }
            }
        }

        return null;
    }

    /**
     * What the run was measured against, so two runs can be told apart.
     *
     * @return array<string, ?string>
     */
    public function environment(): array
    {
        $code = '';
        foreach (['Services/Ai/QueryPlanner.php', 'Services/Ai/EntityResolver.php', 'Support/PhraseMatcher.php',
            'Services/Knowledge/KnowledgeBase.php', 'Services/Knowledge/CampusVocabulary.php',
            'Services/Ai/AskPromptBuilder.php', 'Services/Agent/AruverseAgent.php', 'Services/Ai/AskOperations.php'] as $file) {
            $path = app_path($file);
            $code .= is_file($path) ? md5_file($path) : '-';
        }
        $retrieval = [
            'scoring' => config('knowledge.scoring'),
            'embeddings' => array_intersect_key((array) config('knowledge.embeddings'), array_flip(['min_similarity', 'weight', 'max_candidates', 'chunk_chars', 'max_chunks_per_document', 'model_label'])),
            'max_chars_per_page' => config('knowledge.max_chars_per_page'),
            'routing' => config('ai.routing'),
            'lexicon' => QueryPlanner::LEXICON,
            'aliases' => [AiEntityAlias::query()->count(), AiEntityAlias::query()->max('updated_at')],
            'code' => md5($code),
        ];

        return [
            'git_commit' => $this->gitCommit(),
            'environment' => app()->environment(),
            'local_model' => (string) config('ai.providers.local.model') ?: null,
            'embedding_model' => (string) config('knowledge.embeddings.model_label') ?: null,
            'retrieval_fingerprint' => substr(sha1((string) json_encode($retrieval)), 0, 16),
            'prompt_fingerprint' => substr(sha1(AskPromptBuilder::version().'|'.app(InstitutionProfile::class)->version()), 0, 16),
        ];
    }

    /** Read from .git without running git; null when unavailable (deploy images). */
    private function gitCommit(): ?string
    {
        $git = dirname(base_path()).DIRECTORY_SEPARATOR.'.git';
        $head = @file_get_contents($git.DIRECTORY_SEPARATOR.'HEAD');
        if (! is_string($head)) {
            return null;
        }
        $head = trim($head);
        if (! str_starts_with($head, 'ref: ')) {
            return substr($head, 0, 40) ?: null;
        }
        $ref = @file_get_contents($git.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, substr($head, 5)));
        if (is_string($ref)) {
            return substr(trim($ref), 0, 40);
        }
        $packed = @file_get_contents($git.DIRECTORY_SEPARATOR.'packed-refs');
        if (is_string($packed) && preg_match('/^([0-9a-f]{40}) '.preg_quote(substr($head, 5), '/').'$/m', $packed, $m)) {
            return $m[1];
        }

        return null;
    }
}
