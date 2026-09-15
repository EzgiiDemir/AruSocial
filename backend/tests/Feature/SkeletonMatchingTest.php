<?php

namespace Tests\Feature;

use App\Services\Moderation\ModerationVerdict;
use App\Services\Moderation\TextNormalizer;
use App\Services\Moderation\TextPolicyEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Consonant-skeleton matching must be evidence, not coincidence.
 *
 * The engine compares consonant skeletons when it detects that an author
 * masked characters — `s.e.n.i ö.l.d.ü.r` has to be caught. The loophole
 * was that a skeleton is only unlikely by chance if it is long, and some
 * phrases are very short once vowels go: **"beat you up" reduces to
 * `btp`**, three consonants that occur in ordinary prose constantly.
 *
 * Two things then combined into a live false positive. Homoglyph folding
 * transliterates Cyrillic, so "завтра" becomes "зabtpa" whose skeleton
 * contains `btp`; and skeleton matching switches on for any text
 * containing a masking character — which includes `#`. The result was
 * that the Russian question "Во сколько завтра открывается библиотека?"
 * with a hashtag was refused **for making a threat**, and so was an
 * English orientation announcement.
 *
 * Any student using a hashtag was exposed to this.
 */
class SkeletonMatchingTest extends TestCase
{
    private TextPolicyEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new TextPolicyEngine;
    }

    public static function ordinaryTextWithMaskingCharacters(): array
    {
        return [
            'russian question with a hashtag' => [
                'Во сколько завтра открывается библиотека? Нужно готовиться. #ders',
            ],
            'english announcement with a hashtag' => [
                'The international office is running an orientation for new students #arucad',
            ],
            'russian absence note' => [
                'Завтра меня не будет на паре, я в отъезде, кто законспектирует? #not',
            ],
            'turkish post with two hashtags' => [
                'Kütüphanede buluşalım, notları paylaşırım #ders #kampus',
            ],
            'a reference code' => [
                'Sipariş numaram 4f8d2e, kargo gelmedi #yardim',
            ],
        ];
    }

    #[DataProvider('ordinaryTextWithMaskingCharacters')]
    public function test_a_hashtag_does_not_turn_ordinary_text_into_a_threat(string $text): void
    {
        $verdict = $this->engine->evaluate($text);

        $this->assertSame(ModerationVerdict::ALLOW, $verdict->decision,
            'Blocked as '.implode(',', $verdict->labels)
            .' via '.implode(',', $verdict->matches ?? []));
    }

    public static function genuineMasking(): array
    {
        return [
            'dots between letters' => ['s.e.n.i ö.l.d.ü.r.e.c.e.ğ.i.m'],
            'digits for letters' => ['i w1ll k1ll y0u'],
            'asterisks between letters' => ['l*a*n*e*t z*e*n*c*i'],
        ];
    }

    /**
     * The capability this exists for. A limit that also disabled real
     * obfuscation detection would trade one failure for a worse one.
     */
    #[DataProvider('genuineMasking')]
    public function test_deliberate_masking_is_still_caught(string $text): void
    {
        $verdict = $this->engine->evaluate($text);

        $this->assertNotSame(ModerationVerdict::ALLOW, $verdict->decision,
            'Masked abuse published: '.$text);
    }

    /**
     * The rule in one assertion, so the reason survives even if the
     * example above is someday reworded.
     */
    public function test_a_short_skeleton_cannot_match_on_its_own(): void
    {
        $normalizer = new TextNormalizer;

        $this->assertLessThan(
            6,
            mb_strlen($normalizer->skeleton('beatyouup')),
            'This phrase was the example: if its skeleton is now long enough '
            .'to qualify, pick another short one rather than deleting the test.',
        );

        // Long, ordinary, contains `btp` inside it, carries a masking char.
        $this->assertSame(
            ModerationVerdict::ALLOW,
            $this->engine->evaluate('зabtpa otkpыbaetcя библиoteka #x')->decision,
        );
    }
}
