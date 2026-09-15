<?php

namespace Tests\Unit;

use App\Services\Moderation\ModerationVerdict;
use App\Services\Moderation\PolicyLexicon;
use App\Services\Moderation\TextPolicyEngine;
use PHPUnit\Framework\TestCase;

/**
 * Every phrase rule must reach a decision.
 *
 * `decideFromPhrase` maps a rule's `kind` to a verdict and ends in
 * `default => null`. A rule whose kind is not in that list therefore
 * matches the text, produces a hit, and then does nothing at all — no
 * error, no log, no decision. It looks exactly like working code.
 *
 * That is not hypothetical: `hate_exclusion` was added with 28 phrases
 * covering collective-expulsion rhetoric across three languages, and
 * published everything it matched until the kind was registered. The
 * cost of finding that by eye is a whole category silently disabled;
 * the cost of this test is one loop.
 */
class PhraseRuleWiringTest extends TestCase
{
    public function test_every_phrase_rule_kind_reaches_a_decision(): void
    {
        $engine = new TextPolicyEngine;
        $unwired = [];

        foreach (PolicyLexicon::PHRASE_RULES as $rule) {
            // A phrase with the gap operator needs both halves present, so
            // the probe text joins them with a filler word rather than
            // feeding the raw `a~b` form to the engine.
            $phrase = str_replace('~', ' bu ', $rule['phrases'][0]);

            $verdict = $engine->evaluate($phrase);

            if ($verdict->decision === ModerationVerdict::ALLOW) {
                $unwired[] = sprintf('%s — "%s" produced no decision',
                    $rule['kind'], $phrase);
            }
        }

        $this->assertSame([], $unwired, implode("\n", $unwired));
    }

    /**
     * The rule table itself has to be well formed, since a typo in a key
     * would surface as the same silent nothing.
     */
    public function test_every_rule_declares_the_fields_the_engine_reads(): void
    {
        $malformed = [];

        foreach (PolicyLexicon::PHRASE_RULES as $index => $rule) {
            foreach (['kind', 'labels', 'severity', 'phrases'] as $key) {
                if (! array_key_exists($key, $rule)) {
                    $malformed[] = "rule #{$index} is missing '{$key}'";
                }
            }
            if (($rule['phrases'] ?? []) === []) {
                $malformed[] = sprintf("rule '%s' has no phrases", $rule['kind'] ?? $index);
            }
        }

        $this->assertSame([], $malformed, implode("\n", $malformed));
    }
}
