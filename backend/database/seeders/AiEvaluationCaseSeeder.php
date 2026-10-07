<?php

namespace Database\Seeders;

use App\Models\AiEvaluationCase;
use Illuminate\Database\Seeder;

/**
 * The initial AICAD evaluation suite:
 *  - every labelled page in ask_benchmark.json (page within the top 3,
 *    the benchmark's own pass bar) and its routing cases;
 *  - aicad_evaluation_cases.json: acceptance probes, regressions for fixed
 *    bugs, known data gaps, and the small full-answer subset.
 *
 * Idempotent by name, and it never overwrites a case that exists: once an
 * admin edits a seeded case, the edit wins.
 *
 *   php artisan db:seed --class=AiEvaluationCaseSeeder
 */
class AiEvaluationCaseSeeder extends Seeder
{
    public function run(): void
    {
        $benchmark = json_decode((string) file_get_contents(database_path('seeders/data/ask_benchmark.json')), true) ?: [];
        foreach ($benchmark['cases'] ?? [] as $case) {
            $match = implode('|', array_merge((array) $case['expect'], (array) ($case['also'] ?? [])));
            $this->create([
                'name' => 'benchmark: '.$case['q'],
                'question' => $case['q'],
                'locale' => $case['lang'] ?? null,
                'tags' => ['benchmark', 'retrieval', $case['lang'] ?? 'tr'],
                'assertions' => [['type' => 'retrieval.source_in_top', 'match' => $match, 'n' => 3]],
            ]);
        }
        foreach ($benchmark['routing_cases'] ?? [] as $case) {
            $this->create([
                'name' => 'routing: '.$case['q'],
                'question' => $case['q'],
                'locale' => $case['lang'] ?? null,
                'tags' => ['routing', $case['lang'] ?? 'tr'],
                'assertions' => [['type' => 'routing.tools_include', 'values' => $case['tools']]],
            ]);
        }

        $curated = json_decode((string) file_get_contents(database_path('seeders/data/aicad_evaluation_cases.json')), true) ?: [];
        foreach ($curated['cases'] ?? [] as $case) {
            $this->create($case);
        }
    }

    /** @param array<string, mixed> $case */
    private function create(array $case): void
    {
        AiEvaluationCase::query()->firstOrCreate(['name' => $case['name']], [
            'question' => $case['question'],
            'locale' => $case['locale'] ?? null,
            'mode' => $case['mode'] ?? AiEvaluationCase::MODE_RETRIEVAL,
            // Fixture-dependent cases ship inactive: run them by id after installing the fixtures.
            'active' => $case['active'] ?? true,
            'tags' => $case['tags'] ?? [],
            'previous_turns' => $case['previous_turns'] ?? null,
            'assertions' => $case['assertions'],
            'notes' => $case['notes'] ?? null,
            'created_by' => 'seed',
        ]);
    }
}
