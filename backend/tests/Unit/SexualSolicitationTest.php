<?php

namespace Tests\Unit;

use App\Services\Moderation\ModerationVerdict;
use App\Services\Moderation\TextPolicyEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Proposing sex on a public feed is refused; teaching about it is not.
 *
 * Found by hand-testing, not by any labelled set: "gorup sex do u want to
 * come in" published. Every SEX rule in the lexicon was about harassing a
 * *person* — quid pro quo, coercion, objectification — and none covered
 * an open proposition to a whole feed. The semantic layer did not cover
 * it either: its SEX exemplars are harassment-shaped, so "group sex"
 * scored **-0.121**, further from the category than ordinary writing.
 *
 * The safe half is the half that decides whether this rule survives. An
 * art and design university runs consent workshops, sexual-health
 * clinics, gender and sexuality modules and harassment-reporting
 * campaigns, and every one of those announcements contains the word. A
 * rule that refuses them would be switched off within a week, and then
 * it protects nobody.
 */
class SexualSolicitationTest extends TestCase
{
    private TextPolicyEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new TextPolicyEngine;
    }

    public static function propositions(): array
    {
        return [
            // The post that was actually reported, typo and all.
            'reported case' => ['gorup sex do u want to come in'],
            'corrected spelling' => ['group sex do u want to come in'],
            'invitation to join' => ['group sex anyone want to join'],
            'turkish' => ['grup seks isteyen var mi'],
            'english question' => ['who wants to have sex'],
            'bare with time' => ['sex tonight?'],
            'compound term' => ['anyone up for a threesome'],
            'channel request' => ['dm me for sex'],
            'one night stand tr' => ['tek gecelik isteyen yazsin'],
            'russian' => ['кто хочет секс, пиши'],
            'named act' => ['anal sex anyone interested'],
            'buried in a longer post' => [
                'Yarınki sunum için provaya başladık ve notları paylaştık. '
                .'Bu akşam buluşalım seks yapalım isteyen yazsın. '
                .'Program panoda asılı, herkes bakabilir.',
            ],
            // The academic guard used to be checked across the whole post,
            // so one unrelated mention of a lesson switched the rule off
            // for everything else in it. Both of these carry academic words
            // far from the proposition and must still be refused.
            'academic word elsewhere in post' => [
                'Bugün kütüphanede grup çalışması yaptık, finaller için ders '
                .'notlarını paylaştık. gorup sex do u want to come in. '
                .'Neyse, akşam yemeği için kafeteryada buluşuruz.',
            ],
            'academic word appended' => ['group sex anyone want to join, ders'],
            // Obfuscation, misspelling and slang — the forms a student
            // reaches for once the plain one stops working.
            'spaced letters' => ['s e x anyone want to join'],
            'digit substitution' => ['s3x anyone want to join'],
            'uppercase' => ['SEX WHO WANTS'],
            'misspelling' => ['sexs isteyen var mi'],
            'russian plural imperative' => ['групповой секс приходите'],
            'english verb phrase' => ['who wants to fuck'],
            'initialism' => ['looking for a fwb on campus'],
            'slang' => ['anyone wanna smash tonight'],
        ];
    }

    #[DataProvider('propositions')]
    public function test_an_explicit_proposition_does_not_publish(string $text): void
    {
        $this->assertNotSame(ModerationVerdict::ALLOW,
            $this->engine->evaluate($text)->decision, "Published: \"{$text}\"");
    }

    public static function academicUses(): array
    {
        return [
            'harassment workshop tr' => ['Cinsel tacize karşı bilinçlendirme çalıştayı başvuruları açıldı'],
            'harassment workshop en' => ['Sexual harassment awareness workshop applications are open'],
            'sex education' => ['The sex education seminar is on Tuesday, everyone is welcome'],
            // Explicit term AND an invitation — saved only by the framing.
            'invitation to a seminar' => ['Anyone want to come to the sex education seminar with me?'],
            'methods section' => ['The sex of the participants was recorded in the study'],
            'health seminar tr' => ['Cinsel sağlık semineri için kayıtlar başladı, katılmak isteyen yazsın'],
            'art history' => ['My thesis covers gender and sexuality in twentieth century photography'],
            'prevention ru' => ['Семинар по профилактике сексуального насилия пройдёт во вторник'],
            'gender module' => ['Toplumsal cinsiyet dersi bu dönem açıldı, almak isteyen var mı'],
            // `sex` sits inside this word; whole-token matching is why it
            // survives.
            'substring' => ['Unisex tuvaletler ikinci katta'],
            'ordinary invitation' => ['Who wants to come to the cinema tonight?'],
        ];
    }

    #[DataProvider('academicUses')]
    public function test_teaching_and_reporting_about_sex_still_publishes(string $text): void
    {
        $verdict = $this->engine->evaluate($text);

        $this->assertSame(ModerationVerdict::ALLOW, $verdict->decision,
            "Refused: \"{$text}\" as {$verdict->context}");
    }
}
