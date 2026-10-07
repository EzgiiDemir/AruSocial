<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Ai\AicadReadiness;
use App\Services\Ai\AskDiagnostics;
use App\Services\Ai\Facts\ClaimVerifier;
use App\Services\Ai\Facts\FactPromptBlock;
use App\Services\Ai\Facts\SupportedFactsRollout;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Staging/production readiness of AICAD's SupportedFacts path.
 *
 *   php artisan ask:readiness            config, queue, corpus, aliases, data gaps
 *   php artisan ask:readiness --live     also probes the model, the embedder and retrieval
 *   php artisan ask:readiness --metrics  also prints the rollout aggregates (counters only)
 *
 * Exit code 1 when any check fails. Never prints a credential.
 */
class AskReadiness extends Command
{
    protected $signature = 'ask:readiness
        {--live : Probe the model, the embedder and a retrieval query (network calls)}
        {--metrics : Print the SupportedFacts rollout aggregates}
        {--probe-fallbacks : Exercise the three fact-path fallback branches in-process (staging; makes up to 3 model calls)}
        {--as= : E-mail of the user the fallback probes run as (full answers need a signed-in user)}
        {--json : Print as JSON}';

    protected $description = 'Check whether this environment is ready for the AICAD SupportedFacts rollout';

    public function handle(AicadReadiness $readiness): int
    {
        $checks = $readiness->checks((bool) $this->option('live'));
        $verdict = AicadReadiness::verdict($checks);
        $metrics = $this->option('metrics') ? SupportedFactsRollout::aggregates() : null;

        if ($this->option('json')) {
            $this->line((string) json_encode(array_filter(['verdict' => $verdict, 'checks' => $checks, 'rollout' => $metrics], fn ($v) => $v !== null),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['check', 'status', 'detail'], array_map(fn ($c) => [$c['check'], strtoupper($c['status']), $c['detail']], $checks));
            $this->line('Verdict: '.$verdict);
            if ($metrics !== null) {
                $this->table(['metric', 'value'], collect($metrics['counters'])->map(fn ($v, $k) => [$k, $v])->values()->all()
                    + [count($metrics['counters']) => ['model_calls_per_request', $metrics['model_calls_per_request'] ?? '—']]);
                $this->line('Latency (fact answers): median '.($metrics['latency_ms']['median'] ?? '—').' ms, p95 '.($metrics['latency_ms']['p95'] ?? '—').' ms, n='.$metrics['latency_ms']['n']);
                $this->line('Rates: '.collect($metrics['rates'] ?? [])->map(fn ($v, $k) => $k.' '.($v === null ? '—' : round($v * 100, 1).'%'))->implode(', '));
            }
        }

        $probesOk = true;
        if ($this->option('probe-fallbacks')) {
            $probes = $this->probeFallbacks();
            $probesOk = collect($probes)->every(fn ($p) => $p['ok']);
            $this->table(['fallback probe', 'result', 'observed'], array_map(fn ($p) => [$p['probe'], $p['ok'] ? 'PASS' : 'FAIL', $p['observed']], $probes));
        }

        return $verdict === 'not_ready' || ! $probesOk ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Controlled, in-process probes of the fact-path fallbacks: a failing
     * component is substituted for this process only, a diagnostic question
     * is answered, and the branch taken is read back from the trace. No user
     * is affected and nothing is counted in the rollout aggregates.
     *
     * @return list<array{probe: string, ok: bool, observed: string}>
     */
    private function probeFallbacks(): array
    {
        $user = User::query()->where('email', (string) $this->option('as'))->first();
        if ($user === null) {
            return [['probe' => 'setup', 'ok' => false, 'observed' => 'pass --as=<email of an existing user>']];
        }
        $previous = config('ai.supported_facts.mode');
        config(['ai.supported_facts.mode' => 'on', 'ai.cache.enabled' => false]);
        $fail = fn () => throw new RuntimeException('readiness probe: simulated failure');
        $stage = fn (array $report, string $name) => collect($report['stages'])->last(fn ($s) => $s['stage'] === $name)['data'] ?? [];
        $run = function (string $class, string $question, array $history = []) use ($user, $fail): array {
            $this->laravel->bind($class, $fail);
            try {
                return app(AskDiagnostics::class)->run($question, AskDiagnostics::MODE_FULL, $history, false, $user);
            } finally {
                $this->laravel->bind($class, $class);
            }
        };

        try {
            $out = [];
            // 1. Every task has its facts → the Phase 3B path may answer.
            $r = $run(FactPromptBlock::class, 'Kütüphane açık mı ve nerede?');
            $path = $stage($r, 'generation_path');
            $out[] = ['probe' => 'legacy compatibility fallback', 'ok' => ($path['generation_path'] ?? null) === 'legacy'
                && ($path['fallback_reason'] ?? null) === SupportedFactsRollout::FALLBACK_PATH_ERROR,
                'observed' => ($path['generation_path'] ?? '?').', reason '.($path['fallback_reason'] ?? '—').', model calls '.($path['generation_attempts'] ?? '?')];
            // 2. Data missing → deterministic answer, no model call.
            $r = $run(FactPromptBlock::class, 'Bu kulübün instagramı ne ve kulüp odası nerede?', [['role' => 'user', 'content' => 'Fotoğraf kulübü hakkında bilgi ver'],
                ['role' => 'assistant', 'content' => '—']]);
            $path = $stage($r, 'generation_path');
            $out[] = ['probe' => 'deterministic no-model fallback', 'ok' => ($path['generation_path'] ?? null) === 'deterministic_fallback' && ($path['generation_attempts'] ?? 1) === 0,
                'observed' => ($path['generation_path'] ?? '?').', model calls '.($path['generation_attempts'] ?? '?')];
            // 3. Verification fails → the unverified answer is withheld.
            $r = $run(ClaimVerifier::class, 'Kütüphane açık mı ve nerede?');
            $path = $stage($r, 'generation_path');
            $out[] = ['probe' => 'verification failure withholds', 'ok' => ($path['request_outcome'] ?? null) === 'FAILED' && ($path['answer_withheld'] ?? false),
                'observed' => 'outcome '.($path['request_outcome'] ?? '?').', withheld '.json_encode($path['answer_withheld'] ?? null)];

            return $out;
        } finally {
            config(['ai.supported_facts.mode' => $previous]);
        }
    }
}
