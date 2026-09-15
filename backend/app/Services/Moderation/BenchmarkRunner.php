<?php

namespace App\Services\Moderation;

/**
 * Measures the text policy engine against a labelled set.
 *
 * Kept out of the test file on purpose: the same numbers are wanted from a
 * test (so a regression fails the build) and from an artisan command (so
 * somebody can read the breakdown without running PHPUnit), and two copies
 * of the scoring arithmetic would eventually disagree about what recall
 * means.
 *
 * Definitions, stated because they are easy to blur:
 *
 *   - **Caught** means the verdict does something other than ALLOW. A
 *     REVIEW counts as caught: the brief asks for ambiguous content to be
 *     held rather than published, and held is not published.
 *   - **Recall** = harmful items caught / harmful items. Missing one means
 *     something harmful reached the timeline.
 *   - **False positive** = a safe item whose publication was *prevented*.
 *     Deliberately not "anything but ALLOW": a message quoting a threat in
 *     order to report it is genuinely ambiguous to an automated system, and
 *     holding it for a moderator is the behaviour the brief asks for — not
 *     the same failure as refusing it outright. Counting the two together
 *     would push the design towards publishing ambiguous content to keep a
 *     number down.
 *   - **Held** = a safe item sent to review. Tracked separately as friction:
 *     a moderator will release it, but the student waited.
 *   - **Precision** = caught harmful / (caught harmful + false positives).
 */
class BenchmarkRunner
{
    public function __construct(
        private readonly TextPolicyEngine $engine = new TextPolicyEngine,
        private readonly ?SelfHostedTextClient $semantic = null,
    ) {}

    /**
     * The verdict for one text, optionally through the semantic layer too.
     *
     * The rule engine and the semantic classifier answer different halves
     * of the problem: rules catch the wording people actually use, the
     * classifier catches the paraphrase nobody thought to list. Measuring
     * only the rules understates the product, and measuring only the
     * classifier hides that it is not always reachable — so the harness can
     * do either and the report says which.
     */
    private function verdictFor(string $text): ModerationVerdict
    {
        $verdict = $this->engine->evaluate($text);

        if ($this->semantic === null || $verdict->decision !== ModerationVerdict::ALLOW) {
            return $verdict;
        }

        $result = $this->semantic->inspect($text);

        if ($result->flagged) {
            return new ModerationVerdict(
                ModerationVerdict::REVIEW,
                'S2',
                $result->categories ?: ['SEMANTIC'],
                false,
                'semantic',
                ['semantic'],
            );
        }

        return $verdict;
    }

    /**
     * @param  array{harmful: list<array<string, mixed>>, safe: list<array<string, mixed>>}  $set
     * @return array<string, mixed>
     */
    public function run(array $set): array
    {
        $harmful = [];
        foreach ($set['harmful'] ?? [] as $item) {
            $verdict = $this->verdictFor((string) $item['text']);

            $harmful[] = [
                'id' => (string) $item['id'],
                'lang' => (string) $item['lang'],
                'cat' => (string) $item['cat'],
                'text' => (string) $item['text'],
                'decision' => $verdict->decision,
                'labels' => $verdict->labels,
                'caught' => $verdict->decision !== ModerationVerdict::ALLOW,
                'blocked' => $verdict->blocksPublication(),
                // Catching a threat under PROF is still a catch, but it is
                // worth seeing: the label drives the strike ladder and the
                // message the student is shown.
                'right_label' => in_array((string) $item['cat'], $verdict->labels, true),
            ];
        }

        $safe = [];
        foreach ($set['safe'] ?? [] as $item) {
            $verdict = $this->verdictFor((string) $item['text']);

            $safe[] = [
                'id' => (string) $item['id'],
                'lang' => (string) $item['lang'],
                'near' => $item['near'] ?? null,
                'text' => (string) $item['text'],
                'decision' => $verdict->decision,
                'labels' => $verdict->labels,
                'false_positive' => $verdict->blocksPublication(),
                'held' => $verdict->needsReview(),
            ];
        }

        return [
            'overall' => $this->summarise($harmful, $safe),
            'by_language' => $this->group($harmful, $safe, 'lang'),
            'by_category' => $this->byCategory($harmful, $safe),
            'missed' => array_values(array_filter($harmful, fn ($r) => ! $r['caught'])),
            'false_positives' => array_values(array_filter($safe, fn ($r) => $r['false_positive'])),
            'mislabelled' => array_values(array_filter(
                $harmful,
                fn ($r) => $r['caught'] && ! $r['right_label'],
            )),
            'harmful' => $harmful,
            'safe' => $safe,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $harmful
     * @param  list<array<string, mixed>>  $safe
     * @return array<string, float|int>
     */
    private function summarise(array $harmful, array $safe): array
    {
        $caught = count(array_filter($harmful, fn ($r) => $r['caught']));
        $blocked = count(array_filter($harmful, fn ($r) => $r['blocked']));
        $falsePositives = count(array_filter($safe, fn ($r) => $r['false_positive']));
        $held = count(array_filter($safe, fn ($r) => $r['held']));

        $recall = $harmful === [] ? 0.0 : $caught / count($harmful);
        $fpRate = $safe === [] ? 0.0 : $falsePositives / count($safe);
        $heldRate = $safe === [] ? 0.0 : $held / count($safe);
        $precision = ($caught + $falsePositives) === 0
            ? 0.0
            : $caught / ($caught + $falsePositives);

        return [
            'harmful' => count($harmful),
            'caught' => $caught,
            'blocked' => $blocked,
            'safe' => count($safe),
            'false_positives' => $falsePositives,
            'held' => $held,
            'recall' => round($recall, 4),
            'precision' => round($precision, 4),
            'false_positive_rate' => round($fpRate, 4),
            'held_rate' => round($heldRate, 4),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $harmful
     * @param  list<array<string, mixed>>  $safe
     * @return array<string, array<string, float|int>>
     */
    private function group(array $harmful, array $safe, string $key): array
    {
        $keys = array_unique(array_merge(
            array_column($harmful, $key),
            array_column($safe, $key),
        ));
        sort($keys);

        $out = [];
        foreach ($keys as $value) {
            $out[$value] = $this->summarise(
                array_values(array_filter($harmful, fn ($r) => $r[$key] === $value)),
                array_values(array_filter($safe, fn ($r) => $r[$key] === $value)),
            );
        }

        return $out;
    }

    /**
     * Safe items are attributed to the category they are *near*, because a
     * safe sentence that looks like a threat is the honest denominator for
     * "how precise are we about threats".
     *
     * @param  list<array<string, mixed>>  $harmful
     * @param  list<array<string, mixed>>  $safe
     * @return array<string, array<string, float|int>>
     */
    private function byCategory(array $harmful, array $safe): array
    {
        $categories = array_unique(array_column($harmful, 'cat'));
        sort($categories);

        $out = [];
        foreach ($categories as $category) {
            $out[$category] = $this->summarise(
                array_values(array_filter($harmful, fn ($r) => $r['cat'] === $category)),
                array_values(array_filter($safe, fn ($r) => ($r['near'] ?? null) === $category)),
            );
        }

        return $out;
    }

    /**
     * The breakdown as a table someone can read.
     */
    public function format(array $report): string
    {
        $lines = [];

        $row = fn (string $label, array $s) => sprintf(
            '  %-6s recall %5.1f%%  precision %5.1f%%  FP %5.1f%%  held %4.1f%%  (%d/%d caught, %d blocked + %d held of %d safe)',
            $label,
            $s['recall'] * 100,
            $s['precision'] * 100,
            $s['false_positive_rate'] * 100,
            $s['held_rate'] * 100,
            $s['caught'],
            $s['harmful'],
            $s['false_positives'],
            $s['held'],
            $s['safe'],
        );

        $lines[] = 'OVERALL';
        $lines[] = $row('all', $report['overall']);

        $lines[] = '';
        $lines[] = 'BY LANGUAGE';
        foreach ($report['by_language'] as $language => $stats) {
            $lines[] = $row($language, $stats);
        }

        $lines[] = '';
        $lines[] = 'BY CATEGORY';
        foreach ($report['by_category'] as $category => $stats) {
            $lines[] = $row($category, $stats);
        }

        if ($report['missed'] !== []) {
            $lines[] = '';
            $lines[] = 'MISSED (harmful, allowed through)';
            foreach ($report['missed'] as $miss) {
                $lines[] = sprintf('  %-14s %s', $miss['id'], mb_substr($miss['text'], 0, 70));
            }
        }

        if ($report['false_positives'] !== []) {
            $lines[] = '';
            $lines[] = 'FALSE POSITIVES (safe, publication prevented)';
            foreach ($report['false_positives'] as $fp) {
                $lines[] = sprintf(
                    '  %-14s [%s %s] %s',
                    $fp['id'],
                    $fp['decision'],
                    implode(',', $fp['labels']),
                    mb_substr($fp['text'], 0, 60),
                );
            }
        }

        $heldSafe = array_values(array_filter($report['safe'], fn ($r) => $r['held']));
        if ($heldSafe !== []) {
            $lines[] = '';
            $lines[] = 'HELD FOR REVIEW (safe, a moderator has to release it)';
            foreach ($heldSafe as $item) {
                $lines[] = sprintf(
                    '  %-14s [%s] %s',
                    $item['id'],
                    implode(',', $item['labels']),
                    mb_substr($item['text'], 0, 58),
                );
            }
        }

        if ($report['mislabelled'] !== []) {
            $lines[] = '';
            $lines[] = 'CAUGHT BUT UNDER A DIFFERENT LABEL';
            foreach ($report['mislabelled'] as $item) {
                $lines[] = sprintf(
                    '  %-14s wanted %-5s got %s',
                    $item['id'],
                    $item['cat'],
                    implode(',', $item['labels']) ?: '-',
                );
            }
        }

        return implode("\n", $lines);
    }
}
