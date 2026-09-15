<?php

namespace Tests\Unit;

use App\Services\Moderation\ModerationVerdict;
use App\Services\Moderation\TextPolicyEngine;
use PHPUnit\Framework\TestCase;

/**
 * Racial and identity-based abuse.
 *
 * This shipped broken: "lanet zenci", "lanet siyahiler" and "damn nigga"
 * all published. The engine scored them as insults, and an insult needs a
 * target — "you are an idiot" is an attack, "what an idiot" is a grumble.
 * A slur has no target and was therefore read as a grumble.
 *
 * The fix splits two things that fail in opposite directions:
 *
 *  - Slurs match alone, because the word *is* the attack.
 *  - Identity words ("siyahi", "Kürt", "Arap") never match alone, because
 *    they are how people describe themselves. Blocking those would make it
 *    impossible to discuss racism at all — silencing the people the rule
 *    exists to protect. They only count alongside hostility.
 *
 * The allow-list half of this test is therefore not a nicety; it is the
 * half that keeps the block-list honest.
 */
class HateSpeechTest extends TestCase
{
    private TextPolicyEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new TextPolicyEngine;
    }

    public function test_slurs_are_removed_even_with_no_target(): void
    {
        $cases = [
            // The exact posts that got through.
            'damn nigga',
            'lanet zenci',
            'lanet siyahiler',
            // Slurs alone.
            'zenci', 'nigger', 'faggot', 'retard', 'çingene pislik', 'пидорас',
            // Group + hostility, where every word is innocent alone.
            'pis arap', 'kürtler terörist', 'suriyeliler defolsun',
            'siyahiler aşağılık', 'yahudiler parazit', 'göçmenler asalak',
            'ermeni dölü', 'immigrants are vermin',
            // Genocide glorification uses no slur at all.
            'hitler haklıydı', 'holocaust is a lie',
        ];

        $missed = [];
        foreach ($cases as $text) {
            $verdict = $this->engine->evaluate($text);
            if (! $verdict->blocksPublication()) {
                $missed[] = sprintf('%s (%s) — "%s"',
                    $verdict->decision, $verdict->context, $text);
            }
        }

        $this->assertSame([], $missed, implode("\n", $missed));
    }

    /**
     * Turkish "İ" lowercases to "i" plus a combining dot, and the tokenizer
     * split on the mark — so "İBNE" became "i" + "bne" and matched nothing.
     * Caps lock was a filter bypass for every Turkish word starting with İ.
     */
    public function test_obfuscation_and_caps_do_not_get_a_slur_through(): void
    {
        $cases = ['İBNE', 'ZENCİ', 'Lanet Zenci', 'z3nc1', 'n i g g e r', 'z.e.n.c.i'];

        $missed = [];
        foreach ($cases as $text) {
            if (! $this->engine->evaluate($text)->blocksPublication()) {
                $missed[] = $text;
            }
        }

        $this->assertSame([], $missed, 'Obfuscated slurs published: '.implode(', ', $missed));
    }

    public function test_naming_an_identity_is_not_hate_speech(): void
    {
        $cases = [
            'siyahi arkadaşım çok iyi biri',
            'Arap Dili bölümünde okuyorum',
            'Kürt müziği dinliyorum',
            'yahudi tarihi dersi aldım',
            'kadınlar futbol takımı kuruyoruz',
            'engelliler için rampa yapıldı',
            'göçmenler için yardım kampanyası',
            // Turkish words starting with İ must survive the case fix.
            'İstanbul çok güzel',
            'İyi günler herkese',
            'İzmirde yaşıyorum',
        ];

        $wrong = [];
        foreach ($cases as $text) {
            $verdict = $this->engine->evaluate($text);
            if ($verdict->decision !== ModerationVerdict::ALLOW) {
                $wrong[] = sprintf('%s (%s) — "%s"',
                    $verdict->decision, $verdict->context, $text);
            }
        }

        $this->assertSame([], $wrong, implode("\n", $wrong));
    }

    /**
     * Someone reporting the abuse they received, or telling somebody to
     * stop using a word, has to be able to say the word. Punishing that
     * punishes the victim and makes the slur unreportable.
     */
    public function test_quoting_a_slur_to_condemn_or_report_it_stays_allowed(): void
    {
        $cases = [
            'zenci kelimesini kullanmayı bırak, bu ırkçılık',
            'bana zenci dediler, şikayet etmek istiyorum',
            'Stop using “faggot” as an insult; it is homophobic.',
        ];

        $wrong = [];
        foreach ($cases as $text) {
            $verdict = $this->engine->evaluate($text);
            if ($verdict->blocksPublication()) {
                $wrong[] = sprintf('%s — "%s"', $verdict->context, $text);
            }
        }

        $this->assertSame([], $wrong, implode("\n", $wrong));
    }
}
