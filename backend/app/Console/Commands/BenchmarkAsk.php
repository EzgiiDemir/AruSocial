<?php

namespace App\Console\Commands;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Ai\AskTrace;
use App\Services\Ai\QueryPlanner;
use App\Services\Knowledge\EmbeddingClient;
use App\Services\Knowledge\KnowledgeBase;
use App\Support\TextFold;
use Illuminate\Console\Command;

/**
 * Measures whether Ask ARUVERSE finds the page that answers a question.
 *
 * "The assistant gives true answers" is not something anyone can assert;
 * it has to be measured, and the measurable half is retrieval. A model
 * grounded in the right page can still phrase it badly, but a model
 * handed the wrong page cannot be right except by luck — so
 * retrieval@k is the number that decides whether the rest has a chance.
 *
 * Two kinds of case, because two layers answer questions:
 *  - `cases`: a crawled page must be retrieved. Reported as the rank of the
 *    first expected page, and as hit@1/3/5/10.
 *  - `routing_cases`: live campus data (offices, menus, events) is answered
 *    from database tools, so the case asserts which tools QueryPlanner runs.
 *
 * This deliberately mirrors `moderation:benchmark`: a labelled set, a
 * number, and a stated caveat about who wrote the set.
 */
class BenchmarkAsk extends Command
{
    private const DEPTHS = [1, 3, 5, 10];

    protected $signature = 'ask:benchmark
        {--k=3 : Count a case as found (and set the exit code) if the expected page is in the top k}
        {--verbose-misses : Print what was returned for each miss}
        {--candidates : Print the top candidates of every case with their score components}';

    protected $description = 'Measure whether retrieval finds the page that answers each labelled question';

    public function handle(KnowledgeBase $knowledge, EmbeddingClient $embeddings, QueryPlanner $planner): int
    {
        $path = database_path('seeders/data/ask_benchmark.json');
        if (! is_file($path)) {
            $this->error("Benchmark set not found: {$path}");

            return self::FAILURE;
        }

        $set = json_decode((string) file_get_contents($path), true) ?: [];
        $cases = $set['cases'] ?? [];
        if ($cases === []) {
            $this->error('Benchmark set is empty.');

            return self::FAILURE;
        }

        $documents = KnowledgeDocument::query()->count();
        if ($documents === 0) {
            $this->error('No pages indexed. Run `php artisan knowledge:crawl` first.');

            return self::FAILURE;
        }

        $semantic = $embeddings->isEnabled() && KnowledgeChunk::query()->exists();
        $k = max(1, (int) $this->option('k'));
        $depth = max(self::DEPTHS);

        $this->line("Corpus: {$documents} pages, "
            .KnowledgeChunk::query()->count().' passages, semantic '
            .($semantic ? 'ON' : 'OFF (keyword only)'));
        $this->newLine();

        $hitsAt = array_fill_keys(self::DEPTHS, 0);
        $hitsAtK = 0;
        $byLanguage = [];
        $misses = [];
        $timings = [];
        $scored = [];
        $rows = [];

        foreach ($cases as $case) {
            $question = (string) ($case['q'] ?? '');
            $expects = array_values(array_filter(array_merge(
                (array) ($case['expect'] ?? []),
                (array) ($case['also'] ?? []),
            ), fn ($e) => trim((string) $e) !== ''));
            $lang = (string) ($case['lang'] ?? '??');
            if ($question === '' || $expects === []) {
                continue;
            }

            $trace = new AskTrace;
            $trace->setVerbose();
            app()->instance(AskTrace::class, $trace);

            $started = microtime(true);
            $results = $knowledge->relevant($question, $depth);
            $timings[] = (microtime(true) - $started) * 1000;
            $scored[] = (int) ($trace->get('knowledge.search')['scored_documents'] ?? 0);

            $rank = $this->rankOf($results, $expects);
            foreach (self::DEPTHS as $d) {
                if ($rank !== null && $rank <= $d) {
                    $hitsAt[$d]++;
                }
            }
            $found = $rank !== null && $rank <= $k;
            $byLanguage[$lang] ??= ['hit' => 0, 'total' => 0];
            $byLanguage[$lang]['total']++;
            if ($found) {
                $hitsAtK++;
                $byLanguage[$lang]['hit']++;
            } else {
                $misses[] = ['q' => $question, 'expect' => implode(' | ', $expects),
                    'got' => array_map(fn ($r) => (string) ($r['title'] ?? $r['url']), array_slice($results, 0, $k))];
            }
            $rows[] = [$lang, mb_strimwidth($question, 0, 44, '…'), $rank ?? '—',
                mb_strimwidth((string) ($results[0]['title'] ?? '—'), 0, 48, '…')];

            if ($this->option('candidates')) {
                $this->printCandidates($question, $trace->get('knowledge.candidates')['candidates'] ?? [], $expects);
            }
        }

        $total = array_sum(array_column($byLanguage, 'total'));
        $rate = $total === 0 ? 0.0 : $hitsAtK / $total;

        $this->table(['lang', 'question', 'rank', 'top result'], $rows);
        foreach (self::DEPTHS as $d) {
            $this->line(sprintf('hit@%-2d %d/%d  (%.1f%%)', $d, $hitsAt[$d], $total, $total ? 100 * $hitsAt[$d] / $total : 0));
        }
        $this->line(sprintf('retrieval@%d: %d/%d  (%.1f%%)', $k, $hitsAtK, $total, $rate * 100));
        foreach ($byLanguage as $lang => $stats) {
            $this->line(sprintf('  %-3s %d/%d', $lang, $stats['hit'], $stats['total']));
        }
        sort($timings);
        $this->line(sprintf('latency ms: median %.0f, max %.0f | documents scored per question: median %d, max %d',
            $timings[intdiv(count($timings), 2)] ?? 0, end($timings) ?: 0,
            $this->median($scored), $scored === [] ? 0 : max($scored)));

        $routingFailures = $this->routing($set['routing_cases'] ?? [], $planner);

        if ($misses !== []) {
            $this->newLine();
            $this->warn(count($misses).' miss(es):');
            foreach ($misses as $miss) {
                $this->line("  · {$miss['q']}  — expected a page matching \"{$miss['expect']}\"");
                if ($this->option('verbose-misses')) {
                    foreach ($miss['got'] as $title) {
                        $this->line('      got: '.mb_substr($title, 0, 60));
                    }
                }
            }
        }

        $this->newLine();
        $this->line('Caveat: this set was written against this corpus by the same person who');
        $this->line('tuned retrieval, so it measures whether known topics are reachable — not');
        $this->line('how the assistant handles a question nobody anticipated. A set drawn from');
        $this->line('real student questions is what would make this number trustworthy.');

        // The bar the set is held to. Below it, something regressed.
        $floor = 0.8;

        return $rate >= $floor && $routingFailures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 1-based rank of the first result whose title or URL contains one of
     * the expected substrings (folded), or null.
     *
     * @param  list<array<string, mixed>>  $results
     * @param  list<string>  $expects
     */
    private function rankOf(array $results, array $expects): ?int
    {
        $needles = array_map(fn ($e) => TextFold::fold((string) $e), $expects);
        foreach ($results as $i => $hit) {
            $haystack = TextFold::fold((string) ($hit['title'] ?? '').' '.rawurldecode((string) ($hit['url'] ?? '')));
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $i + 1;
                }
            }
        }

        return null;
    }

    /**
     * Routing cases: every expected tool must be selected.
     *
     * @param  list<array<string, mixed>>  $cases
     */
    private function routing(array $cases, QueryPlanner $planner): int
    {
        if ($cases === []) {
            return 0;
        }
        $this->newLine();
        $failures = 0;
        $rows = [];
        foreach ($cases as $case) {
            $plan = $planner->plan((string) $case['q']);
            $missing = array_diff((array) ($case['tools'] ?? []), $plan['tools']);
            $failures += $missing === [] ? 0 : 1;
            $rows[] = [$case['lang'] ?? '??', $case['q'], implode(',', $plan['tools']),
                $missing === [] ? 'ok' : 'MISSING '.implode(',', $missing)];
        }
        $this->table(['lang', 'routing question', 'tools', 'result'], $rows);
        $this->line(sprintf('routing: %d/%d', count($cases) - $failures, count($cases)));

        return $failures;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<string>  $expects
     */
    private function printCandidates(string $question, array $candidates, array $expects): void
    {
        $this->line("<comment>{$question}</comment>");
        $rows = [];
        foreach ($candidates as $c) {
            $p = $c['parts'];
            $rows[] = [
                $c['rank'].($c['selected'] ? '*' : '').($this->rankOf([$c], $expects) ? ' ✓' : ''),
                $c['score'],
                $p['lexical'] ?? 0,
                $p['similarity'] ?? '—',
                $p['language'] ?? 0,
                $p['freshness'] ?? 0,
                $p['authority'] ?? 0,
                mb_strimwidth((string) $c['title'], 0, 50, '…'),
            ];
        }
        $this->table(['#', 'final', 'lexical', 'similarity', 'lang', 'fresh', 'auth', 'title'], $rows);
    }

    /** @param list<int> $values */
    private function median(array $values): int
    {
        if ($values === []) {
            return 0;
        }
        sort($values);

        return $values[intdiv(count($values), 2)];
    }
}
