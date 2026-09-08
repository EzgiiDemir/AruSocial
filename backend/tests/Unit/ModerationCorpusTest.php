<?php

namespace Tests\Unit;

use App\Services\Moderation\TextPolicyEngine;
use PHPUnit\Framework\TestCase;

/**
 * Runs the labelled TR/EN/RU corpus through the policy engine.
 *
 * The corpus (tests/fixtures/moderation_cases.jsonl) is the specification:
 * each line carries the decision a human moderator reached for that text,
 * including the awkward ones — sarcasm, banter, quoting a slur in order to
 * report it, and trash talk inside a game. Regressions here mean the filter
 * has started either missing abuse or punishing ordinary speech.
 */
class ModerationCorpusTest extends TestCase
{
    private TextPolicyEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new TextPolicyEngine();
    }

    /** @return list<array<string, mixed>> */
    private function corpus(): array
    {
        $path = __DIR__.'/../fixtures/moderation_cases.jsonl';
        $this->assertFileExists($path);

        $cases = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $cases[] = $decoded;
            }
        }

        return $cases;
    }

    public function test_every_labelled_case_reaches_the_expected_decision(): void
    {
        $cases = $this->corpus();
        $this->assertGreaterThan(100, count($cases), 'Corpus should not shrink silently.');

        $failures = [];
        foreach ($cases as $case) {
            $verdict = $this->engine->evaluate($case['text']);
            if ($verdict->decision !== $case['decision']) {
                $failures[] = sprintf(
                    '%s [%s] expected %s, got %s (%s) — "%s"',
                    $case['id'],
                    $case['language'],
                    $case['decision'],
                    $verdict->decision,
                    $verdict->context,
                    mb_substr($case['text'], 0, 60),
                );
            }
        }

        $this->assertSame([], $failures, sprintf(
            "%d/%d cases decided incorrectly:\n%s",
            count($failures),
            count($cases),
            implode("\n", $failures),
        ));
    }

    public function test_abuse_is_never_silently_published(): void
    {
        $missed = [];
        foreach ($this->corpus() as $case) {
            $expectsAction = in_array($case['decision'], ['remove', 'remove_escalate'], true);
            if (! $expectsAction) {
                continue;
            }
            $verdict = $this->engine->evaluate($case['text']);
            if ($verdict->decision === 'allow') {
                $missed[] = $case['id'].': "'.mb_substr($case['text'], 0, 60).'"';
            }
        }

        $this->assertSame([], $missed, "Content that must be blocked was allowed through:\n".implode("\n", $missed));
    }

    public function test_ordinary_campus_speech_is_not_punished(): void
    {
        $allowed = [
            'Bugün stüdyoda harika bir gün geçirdim',
            'Kulüp başkanlığı seçimi için adaylık başvuruları başladı.',
            'Kütüphanede yer var mı acaba?',
            'The library closes at 22:00 tonight.',
            'Bu proje için malzeme listesi hazır.',
            'Nice pic! Where was this taken?',
            'Завтра встречаемся в студии в 10.',
            'Yarın maçta görüşürüz, bol şans!',
        ];

        foreach ($allowed as $text) {
            $verdict = $this->engine->evaluate($text);
            $this->assertSame(
                'allow',
                $verdict->decision,
                sprintf('False positive on "%s" (%s / %s)', $text, $verdict->decision, $verdict->context),
            );
        }
    }

    public function test_obfuscated_abuse_is_still_caught(): void
    {
        $obfuscated = [
            'Sen tam bir a p t a l s ı n',
            's4l4k herif',
            'You are such an 1d10t',
            'Ты полный идиoт',
        ];

        foreach ($obfuscated as $text) {
            $verdict = $this->engine->evaluate($text);
            $this->assertNotSame(
                'allow',
                $verdict->decision,
                sprintf('Obfuscated abuse slipped through: "%s"', $text),
            );
        }
    }
}
