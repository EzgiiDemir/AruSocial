<?php

namespace Tests\Feature;

use App\Services\Moderation\BenchmarkRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Holds the measured moderation numbers so a change cannot quietly undo
 * them.
 *
 * The floors below are what the rule engine *actually achieves*, recorded
 * after the §4 pass and not aspirational. They exist so that widening a
 * pattern to catch one more insult, and thereby refusing a hundred ordinary
 * sentences, fails the build instead of reaching students.
 *
 * Deliberately the rule engine only — no semantic classifier. The classifier
 * is a separate service on another port, and a unit test that silently
 * passes when it is switched off measures nothing. `docs/MODERATION_V4.md`
 * carries the whole-pipeline figures and says how to reproduce them.
 *
 * The precision floor is the one that matters most. Recall can be bought by
 * blocking more, and a moderation system that refuses ordinary campus
 * conversation is one students route around — at which point it protects
 * nobody.
 */
class ModerationBenchmarkTest extends TestCase
{
    /** @return array<string, array{string, float, float}> set => [name, min recall, min precision] */
    public static function sets(): array
    {
        return [
            // Tuned against: these numbers say the fixes work on the
            // examples that drove them, which is the weaker claim.
            'eval_v4 (tuned against)' => ['eval_v4', 0.90, 1.0],

            // Fresh examples. The honest figure, and much lower — the gap
            // between the two is the cost of a rule-based layer meeting
            // wording nobody listed.
            'eval_v4_holdout (fresh)' => ['eval_v4_holdout', 0.28, 1.0],
        ];
    }

    /**
     * @return array{harmful: list<array<string, mixed>>, safe: list<array<string, mixed>>}
     */
    private function load(string $name): array
    {
        $path = base_path("tests/Fixtures/Moderation/{$name}.json");
        $this->assertFileExists($path);

        $set = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($set);
        $this->assertNotEmpty($set['harmful'] ?? []);
        $this->assertNotEmpty($set['safe'] ?? []);

        return $set;
    }

    #[DataProvider('sets')]
    public function test_recall_and_precision_do_not_regress(
        string $name,
        float $minRecall,
        float $minPrecision,
    ): void {
        $runner = new BenchmarkRunner;
        $report = $runner->run($this->load($name));
        $overall = $report['overall'];

        $this->assertGreaterThanOrEqual(
            $minRecall,
            $overall['recall'],
            sprintf(
                "Recall on %s fell to %.1f%% (floor %.1f%%).\n%s",
                $name,
                $overall['recall'] * 100,
                $minRecall * 100,
                $runner->format($report),
            ),
        );

        $this->assertGreaterThanOrEqual(
            $minPrecision,
            $overall['precision'],
            sprintf(
                "Precision on %s fell to %.1f%% — ordinary posts are being refused.\n%s",
                $name,
                $overall['precision'] * 100,
                $runner->format($report),
            ),
        );
    }

    /**
     * The one that protects students from the moderation system rather than
     * the other way round.
     */
    #[DataProvider('sets')]
    public function test_no_safe_post_is_refused(
        string $name,
        float $_minRecall,
        float $_minPrecision,
    ): void {
        $runner = new BenchmarkRunner;
        $report = $runner->run($this->load($name));

        $this->assertSame([], array_map(
            fn ($fp) => $fp['id'].': '.$fp['text'],
            $report['false_positives'],
        ), "Ordinary campus posts are being blocked on {$name}.");
    }

    /**
     * Per-category floors, so an overall average cannot hide a category
     * that collapsed. Only the categories the rule layer is responsible
     * for — the ones it genuinely cannot do alone are covered by the
     * semantic layer and reported in the doc, not asserted here.
     *
     * @return array<string, array{string, float}>
     */
    public static function categoryFloors(): array
    {
        return [
            'PROF' => ['PROF', 0.90],
            'THR' => ['THR', 1.0],
            'HAR' => ['HAR', 1.0],
            'HATE' => ['HATE', 1.0],
            'SELF' => ['SELF', 1.0],
            'SEX' => ['SEX', 1.0],
            'SPAM' => ['SPAM', 1.0],
            'SCAM' => ['SCAM', 0.85],
            'PRIV' => ['PRIV', 0.80],
        ];
    }

    #[DataProvider('categoryFloors')]
    public function test_each_category_holds_its_floor(string $category, float $floor): void
    {
        $runner = new BenchmarkRunner;
        $report = $runner->run($this->load('eval_v4'));

        $this->assertArrayHasKey($category, $report['by_category']);

        $this->assertGreaterThanOrEqual(
            $floor,
            $report['by_category'][$category]['recall'],
            sprintf(
                '%s recall fell to %.1f%% (floor %.1f%%).',
                $category,
                $report['by_category'][$category]['recall'] * 100,
                $floor * 100,
            ),
        );
    }

    /**
     * Every language carries its own weight.
     *
     * Turkish is the language most of the lexicon was written in, and an
     * overall figure can look healthy while Russian quietly does nothing —
     * which is exactly what the first §4 measurement found.
     *
     * @return array<string, array{string, float}>
     */
    public static function languageFloors(): array
    {
        return [
            'Turkish' => ['tr', 0.85],
            'English' => ['en', 0.95],
            'Russian' => ['ru', 0.90],
        ];
    }

    #[DataProvider('languageFloors')]
    public function test_each_language_holds_its_floor(string $language, float $floor): void
    {
        $runner = new BenchmarkRunner;
        $report = $runner->run($this->load('eval_v4'));

        $this->assertGreaterThanOrEqual(
            $floor,
            $report['by_language'][$language]['recall'],
            sprintf(
                '%s recall fell to %.1f%% (floor %.1f%%).',
                $language,
                $report['by_language'][$language]['recall'] * 100,
                $floor * 100,
            ),
        );
    }

    /**
     * Ambiguity is held, not published. The brief asks for this explicitly,
     * and it is the behaviour that makes a lower recall survivable.
     */
    public function test_a_reported_threat_is_held_rather_than_refused(): void
    {
        $runner = new BenchmarkRunner;
        $report = $runner->run($this->load('eval_v4'));

        $reported = null;
        foreach ($report['safe'] as $item) {
            if ($item['id'] === 'tr-safe-08') {
                $reported = $item;
            }
        }

        $this->assertNotNull($reported, 'The reported-threat fixture has gone.');
        $this->assertFalse(
            $reported['false_positive'],
            'Refusing the message of someone reporting a threat is the worst '
            .'possible failure for this app.'
        );
        $this->assertTrue(
            $reported['held'],
            'A reported threat should reach a moderator, not publish silently.'
        );
    }
}
