<?php

namespace Tests\Unit;

use App\Services\Moderation\ModerationVerdict;
use App\Services\Moderation\TextPolicyEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Obscene compounds are insults, not exclamations — and the short
 * syllables they are built from must stay harmless.
 *
 * The engine allows untargeted strong profanity, because a 150-case
 * corpus deliberately publishes someone swearing at a broken app.
 * "Sik kafalı" inherited that reading and published: the root matched
 * and the verdict came back `general_exclamation`. But a compound like
 * that is not swearing at a situation, it is a name for a person.
 *
 * The safe half is the half that decides whether any of this survives
 * contact with Turkish. `am`, `göt` and `top` are all ordinary syllables:
 * **tamam**, **ambulans**, **amfi**, **Amasya**, **götürmek**,
 * **Göteborg**, **top oynamak**. None of those three is ever listed as a
 * root for exactly that reason — only compounds are — and one of them
 * appears in most posts on this campus.
 */
class VulgarCompoundTest extends TestCase
{
    private TextPolicyEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new TextPolicyEngine;
    }

    public static function compounds(): array
    {
        return [
            'sik compound' => ['sik kafalı'],
            'sik compound, spaced out' => ['sik kafalisin sen'],
            'sik compound, joined' => ['sikkafali herif'],
            'got compound' => ['göt lalesi'],
            'got compound, anatomical' => ['göt deliği'],
            'got compound, possessive' => ['götünün deliği'],
            'am compound' => ['amına kodugumun herifi'],
            'family abuse' => ['ananı sikeyim'],
            'family abuse, full form' => ['ananın amına koyayım'],
            'family abuse, spouse' => ['avradını sikeyim'],
            'homophobic compound' => ['top herif yine geldi'],
            'english compound' => ['you absolute dickhead'],
            'english compound, spaced' => ['what a shit for brains'],
            'russian compound' => ['ты хуйло конченое'],
            // Obfuscation has to survive the compound rules too.
            'uppercase' => ['SİK KAFALI'],
            'repeated letters' => ['siiik kafaliii'],
            'punctuation split' => ['s.i.k k.a.f.a.l.i'],
            'digit substitution' => ['s1k kafali'],
        ];
    }

    #[DataProvider('compounds')]
    public function test_an_obscene_compound_does_not_publish(string $text): void
    {
        $this->assertNotSame(ModerationVerdict::ALLOW,
            $this->engine->evaluate($text)->decision, "Published: \"{$text}\"");
    }

    public static function ordinaryWords(): array
    {
        return [
            // `am` — the one that matters most; it is inside the commonest
            // word in Turkish conversation.
            'tamam' => ['Tamam, yarın görüşürüz'],
            'tamamen' => ['Proje tamamen bitti, teslim ettim'],
            'ambulans' => ['Ambulans kampüse geldi, herkes iyi'],
            'amfi' => ['Amfi tiyatroda ders var, saat üçte'],
            'amasya' => ['Amasya elması bu sezon çok iyi'],
            'amerika' => ['Amerika Birleşik Devletleri üzerine sunum'],
            'amac' => ['Bu çalışmanın amacı nedir'],
            // `göt` — inside "to take", which is everyday.
            'goturmek' => ['Kitapları kütüphaneye götürmek istiyorum'],
            'goturur' => ['Beni de götürür müsün arabayla'],
            'goteborg' => ['Göteborg üniversitesinden bir konuk geldi'],
            // `top` — a ball, and half the sports posts.
            'top oyna' => ['Sahada top oynayacağız, gelen olur mu'],
            'toplanti' => ['Toplantı saat üçte başlıyor'],
            'toplum' => ['Toplumsal cinsiyet dersi bu dönem açıldı'],
            // `koymak` — to put.
            'koymak' => ['Kitabı masaya koymak istiyorum'],
            'koyalim' => ['Dosyayı buraya koyalım mı'],
            // `sik` — the guarded ones.
            'sikayet' => ['Bu konuda şikayet etmek istiyorum'],
            'sikke' => ['Sikke koleksiyonu sergisi müzede'],
            'siklet' => ['Hafif siklet boks maçı bu akşam'],
            // English and Russian near-misses.
            'english' => ['We analysed Dickens in the seminar'],
            'english shift' => ['The shift starts at nine, bring a clean shirt'],
            'russian artist' => ['Художник показал новую работу в галерее'],
            'russian cloth' => ['Сукно для обивки можно купить в мастерской'],
        ];
    }

    #[DataProvider('ordinaryWords')]
    public function test_an_ordinary_word_containing_the_syllable_publishes(string $text): void
    {
        $verdict = $this->engine->evaluate($text);

        $this->assertSame(ModerationVerdict::ALLOW, $verdict->decision,
            "Refused: \"{$text}\" as {$verdict->context}");
    }

    /**
     * Swearing at a situation still publishes with the default policy, and
     * the switch that changes that is honest about its cost: it cannot
     * hold "siktiğim ders" without also holding a student complaining
     * about the app, because they are the same speech act.
     */
    public function test_the_untargeted_switch_is_off_by_default(): void
    {
        $this->assertSame(ModerationVerdict::ALLOW,
            $this->engine->evaluate('What the fuck is wrong with this system?')->decision);
        $this->assertSame(ModerationVerdict::ALLOW,
            $this->engine->evaluate('siktiğim ders yine iptal')->decision);
    }
}
