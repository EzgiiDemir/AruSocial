<?php

namespace Tests\Unit;

use App\Services\Moderation\ModerationVerdict;
use App\Services\Moderation\TextPolicyEngine;
use PHPUnit\Framework\TestCase;

/**
 * Campus-specific policy, on top of the general abuse corpus.
 *
 * A general moderation model is trained on abuse between people and is very
 * good at it — but it has no opinion about someone selling exam answers,
 * because that is not harmful speech, it is a university rules violation.
 * These are the rules we own: academic integrity, student-targeted fraud,
 * dealing, weapons, sextortion, doxxing, spam, and personal identifiers.
 *
 * The false-positive cases at the bottom matter as much as the detections.
 * A campus feed is full of numbers and blunt language, and a filter that
 * eats ordinary posts gets switched off.
 *
 * Each test collects every failure before asserting, so a change that
 * breaks five rules reports five lines rather than hiding four of them.
 */
class CampusPolicyRulesTest extends TestCase
{
    private TextPolicyEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new TextPolicyEngine;
    }

    public function test_campus_policy_violations_are_actioned(): void
    {
        $cases = [
            // Academic integrity — invisible to a general abuse model.
            ['Sinav sorulari elimde, isteyene satiyorum', 'academic_dishonesty', ModerationVerdict::REMOVE],
            ['Senin yerine sinava girerim, konusalim', 'academic_dishonesty', ModerationVerdict::REMOVE],
            ['I can write your essay for 500 lira', 'academic_dishonesty', ModerationVerdict::REMOVE],
            ['Продам ответы на экзамен, пишите', 'academic_dishonesty', ModerationVerdict::REMOVE],

            // Fraud aimed at students.
            ['Garantili kazanc, once kapora gonder', 'scam_fraud', ModerationVerdict::REMOVE],
            ['Guaranteed scholarship, just pay the deposit first', 'scam_fraud', ModerationVerdict::REMOVE],

            ['Kampuste ot satiyorum yazin', 'drug_sale', ModerationVerdict::REMOVE],
            ['Selling weed, dm me', 'drug_sale', ModerationVerdict::REMOVE],

            // Weapons and coercion escalate rather than merely blocking.
            ['Silahim var yarin getirecegim', 'weapon', ModerationVerdict::REMOVE_ESCALATE],
            ['I have a gun and I am coming tomorrow', 'weapon', ModerationVerdict::REMOVE_ESCALATE],
            ['Fotograflarini yayarim eger konusursan', 'sextortion', ModerationVerdict::REMOVE_ESCALATE],
            ['I will leak your nudes unless you reply', 'sextortion', ModerationVerdict::REMOVE_ESCALATE],

            ['Iste adresi, gidin bulun', 'doxxing', ModerationVerdict::REMOVE],
            ['Here is her address, go find her', 'doxxing', ModerationVerdict::REMOVE],

            // Spam is a nuisance, not an attack — a warning, not a removal.
            ['Bedava takipci icin link tikla kazan', 'spam_solicitation', ModerationVerdict::REMOVE],
        ];

        $failures = [];
        foreach ($cases as [$text, $expectedContext, $expectedDecision]) {
            $verdict = $this->engine->evaluate($text);
            if ($verdict->decision !== $expectedDecision || $verdict->context !== $expectedContext) {
                $failures[] = sprintf(
                    'expected %s/%s, got %s/%s — "%s"',
                    $expectedDecision, $expectedContext,
                    $verdict->decision, $verdict->context, $text,
                );
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_personal_identifiers_are_not_published(): void
    {
        $cases = [
            'Numarasi 05321234567, arayin ve sorun',
            'Call him on +90 532 123 45 67',
            'TC kimlik 10000000146 paylasiyorum',
            'Para gonderin TR330006100519786457841326',
        ];

        $failures = [];
        foreach ($cases as $text) {
            $verdict = $this->engine->evaluate($text);
            if (! $verdict->blocksPublication() || $verdict->context !== 'pii_exposure') {
                $failures[] = sprintf(
                    'got %s/%s — "%s"', $verdict->decision, $verdict->context, $text,
                );
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    /**
     * A student in crisis must never be punished for saying so.
     *
     * This is the most important assertion in the moderation suite. The
     * generic "self_directed" exemption — written so "I'm such an idiot"
     * isn't treated as an insult — used to swallow these silently, which is
     * worse than a strike in one respect: nobody found out at all.
     */
    public function test_a_student_in_crisis_is_offered_help_not_a_strike(): void
    {
        $cases = [
            'Kendimi oldurecegim, artik dayanamiyorum',
            'artik yasamak istemiyorum',
            'I want to kill myself',
            'I have been cutting myself again',
            'Не хочу жить больше',
            'Хочу покончить с собой',
        ];

        $failures = [];
        foreach ($cases as $text) {
            $verdict = $this->engine->evaluate($text);

            if ($verdict->context !== 'self_harm') {
                $failures[] = sprintf(
                    'read as "%s" — a crisis post must not be exempted away: "%s"',
                    $verdict->context, $text,
                );

                continue;
            }
            if ($verdict->decision !== ModerationVerdict::REVIEW) {
                $failures[] = sprintf('decision %s — "%s"', $verdict->decision, $text);
            }
            if ($verdict->blocksPublication()) {
                $failures[] = sprintf('blocked — that tells a student to be quiet: "%s"', $text);
            }
            if ($verdict->isEscalation()) {
                $failures[] = sprintf('escalated — this must never cost a strike: "%s"', $text);
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_ordinary_campus_posts_are_untouched(): void
    {
        $cases = [
            // Numbers everywhere, none of them identifying.
            'Ders 204 numarali sinifta, dahili 1006',
            'Bugun 2026 mezuniyet toreni var',
            'Etkinlik 15:30 da, 120 kisilik salonda',
            'Spor takimi icin sports@arucad.edu.tr / 1006',
            // Legitimate selling and studying.
            'Ikinci el ders kitabi satiyorum, 200 TL',
            'Kutuphanede sinava calisiyorum, gelen olursa yazsin',
            'Bu odev beni bitirdi ama tesliminde yetistirdim',
            // Self-deprecation, which must stay allowed.
            'Kendime cok kizdim, ne aptalim ya',
            'I am so mad at myself, I am such an idiot',
        ];

        $failures = [];
        foreach ($cases as $text) {
            $verdict = $this->engine->evaluate($text);
            if ($verdict->decision !== ModerationVerdict::ALLOW) {
                $failures[] = sprintf(
                    'actioned as %s (%s) — this is a normal post: "%s"',
                    $verdict->decision, $verdict->context, $text,
                );
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }
}
