<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The benchmark command's own guards.
 *
 * The measurement itself needs the real crawled corpus, so it is not
 * something a test with an empty database can assert. What a test can
 * pin is that the command fails loudly rather than reporting a
 * meaningless score when there is nothing to measure — a benchmark that
 * prints 0% on an empty corpus looks identical to a broken retriever.
 */
class AskBenchmarkCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_to_score_an_empty_corpus(): void
    {
        $this->artisan('ask:benchmark')
            ->expectsOutputToContain('No pages indexed')
            ->assertExitCode(1);
    }

    public function test_the_labelled_set_is_present_and_well_formed(): void
    {
        $path = database_path('seeders/data/ask_benchmark.json');
        $this->assertFileExists($path);

        $cases = json_decode((string) file_get_contents($path), true)['cases'] ?? [];

        $this->assertGreaterThanOrEqual(20, count($cases),
            'A set this small cannot say much; each case moves the score several points.');

        $languages = [];
        foreach ($cases as $case) {
            $this->assertArrayHasKey('q', $case);
            $this->assertArrayHasKey('expect', $case);
            $this->assertNotSame('', trim((string) $case['q']));
            $this->assertNotSame('', trim((string) $case['expect']));
            $languages[(string) ($case['lang'] ?? '')] = true;
        }

        // All three languages the app supports, or the score hides a
        // language that does not work.
        foreach (['tr', 'en', 'ru'] as $language) {
            $this->assertArrayHasKey($language, $languages,
                "The set has no {$language} cases, so it cannot detect {$language} regressions.");
        }
    }
}
