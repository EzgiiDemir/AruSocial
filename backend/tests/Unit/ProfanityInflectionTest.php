<?php

namespace Tests\Unit;

use App\Services\Moderation\ModerationVerdict;
use App\Services\Moderation\TextPolicyEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Abusive words are matched by root, not by finished form.
 *
 * Turkish is agglutinative and Russian declines, so listing finished
 * words means listing a fraction of them. Measured before this: "siktir
 * git" was refused while "siktir", "sikerim seni", "sikik herif",
 * "yarrağımı", "amk", "хуй" and "блять" all published. A filter that
 * loses to a suffix is one students learn to beat in an afternoon.
 *
 * The safe half of this file is the half that keeps the feature alive.
 * Turkish normalisation folds ş→s, so the root `sik` reaches **şikayet**
 * — *complaint* — the word a campus app most needs to accept. A filter
 * that refuses complaints gets switched off, and then it protects nobody.
 *
 * The labelled set lives at
 * image-moderation-service/text_profanity_forms.json.
 */
class ProfanityInflectionTest extends TestCase
{
    private TextPolicyEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new TextPolicyEngine;
    }

    public static function abusiveForms(): array
    {
        return [
            // Turkish: one root, many suffixes.
            'bare imperative' => ['siktir'],
            'imperative with particle' => ['siktir lan'],
            'imperative with verb' => ['siktir git buradan'],
            'first person inflection' => ['sikerim seni'],
            'derived adjective' => ['sikik herif'],
            'consonant softening k->g' => ['yarragimi ye'],
            'spaced compound' => ['got veren herif'],
            'dotted masking' => ['s.i.k.t.i.r git'],
            'digit substitution' => ['s1kt1r lan'],
            'spaced letters' => ['s i k t i r'],
            'vowel repeat' => ['siiiktir'],
            'doubled consonant' => ['sikktir'],
            'asterisk masking' => ['s*ktir git'],
            'hyphen split' => ['siktir-git'],
            'uppercase' => ['SİKTİR GİT'],

            // English.
            'english imperative' => ['fuck off'],
            'english vowel drop' => ['fck you man'],
            'english misspelling' => ['fuk off'],
            'english acronym' => ['stfu already'],
            'english targeted' => ['you are a bitch'],
            'english dotted' => ['f.u.c.k you'],
            // The particle carries the target, so the spelling in front
            // of it is free — these are caught without being listed.
            'unlisted spelling, particle' => ['phuck off'],
            'v for u, particle' => ['fvck you'],
            'compound insult' => ['you are a shithead'],

            // Russian: declension.
            'russian imperative' => ['иди на хуй'],
            'russian fused' => ['нахуй пошёл'],
            'russian targeted' => ['сука ты'],
            'russian noun' => ['пиздец полный'],
            'russian insult' => ['мудак ты редкий'],
            'russian spaced' => ['и д и  н а  х у й'],
            // A Latin letter dropped into a Cyrillic word.
            'russian mixed script' => ['пиzдец ты'],
            'russian latin homoglyph' => ['иди нa хуй'],
        ];
    }

    #[DataProvider('abusiveForms')]
    public function test_an_inflected_or_masked_form_does_not_publish(string $text): void
    {
        $verdict = $this->engine->evaluate($text);

        $this->assertNotSame(ModerationVerdict::ALLOW, $verdict->decision,
            "Published: \"{$text}\"");
    }

    public static function ordinaryWords(): array
    {
        return [
            // The one that matters most: ş folds to s.
            'complaint' => ['Bu konuda şikayet etmek istiyorum'],
            'complaint, inflected' => ['Şikayetlerinizi öğrenci konseyine iletebilirsiniz'],
            'complainant' => ['Şikayetçi olmak için dilekçe gerekiyor mu'],
            'coin' => ['Sikke koleksiyonu sergisi müzede açıldı'],
            'weight class' => ['Hafif siklet boks maçı bu akşam'],
            'to take away' => ['Kitapları kütüphaneye götürmem gerekiyor'],
            'to take away, inflected' => ['Beni de götürür müsün'],
            'materials and cost' => ['Malzemeleri taşıdık, maliyeti hesapladık'],
            'classical music' => ['Klasik müzik konseri cuma akşamı'],
            'psychology' => ['Psikoloji bölümünden arkadaşlarla ders çalışıyoruz'],
            'street' => ['Sokakta park yeri bulmak imkansız'],

            'picture' => ['Take a picture of the prototype for the report'],
            'shift and shirt' => ['The shift starts at nine, bring a clean shirt'],
            'a surname' => ['We analysed Dickens in the literature seminar'],
            'scunthorpe' => ['Scunthorpe is a town in Lincolnshire'],
            // Doubled letters are collapsed when matching roots, so these
            // pass through that path and must survive it.
            'doubled letters' => ['The hall was full, the kill switch works'],
            'doubled turkish' => ['Dikkat edin, bakkal saat altıda kapanıyor'],

            'artist' => ['Художник показал новую работу в галерее'],
            'cloth' => ['Сукно для обивки можно купить в мастерской'],
            'wisdom' => ['Мудрость этого текста в его простоте'],
            'pancakes' => ['Блины на завтрак были очень вкусные'],
        ];
    }

    #[DataProvider('ordinaryWords')]
    public function test_an_ordinary_word_a_root_prefixes_still_publishes(string $text): void
    {
        $verdict = $this->engine->evaluate($text);

        $this->assertSame(ModerationVerdict::ALLOW, $verdict->decision,
            "Refused: \"{$text}\" via ".implode(',', $verdict->matches ?? []));
    }

    /**
     * Swearing at a situation is not abuse, and stays published.
     *
     * This distinction is deliberate and is encoded in a 150-case
     * corpus. An attempt to close the "siktir" gap by holding all
     * untargeted obscenity held "What the fuck is wrong with this
     * system?" along with it — the parity problem belongs to imperatives,
     * not to profanity in general.
     */
    public static function exclamations(): array
    {
        return [
            'english' => ['What the fuck is wrong with this system?'],
            'russian' => ['Какого хуя эта система не работает?'],
            // "amk"/"aq" are the SMS spellings of the same exclamation and
            // were labelled harmful in the first draft of this file. They
            // are not: "amk ya yeter artık" is a student complaining about
            // the app, and the corpus already settled that such a post
            // publishes. Left here so the labelling stays deliberate.
            'turkish sms' => ['amk ya yeter artik'],
            'turkish two letter' => ['aq neden boyle'],
        ];
    }

    #[DataProvider('exclamations')]
    public function test_swearing_at_a_situation_still_publishes(string $text): void
    {
        $this->assertSame(ModerationVerdict::ALLOW,
            $this->engine->evaluate($text)->decision, "Refused: \"{$text}\"");
    }
}
