<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Harmful content buried in a long post must not publish — verified by
 * posting it, over HTTP, through the same endpoints a student uses.
 *
 * This exists because the unit tests could not have caught the bug it
 * guards. The deterministic lexicon reads token by token, so position
 * never mattered to it; the semantic layer embedded the *whole post* as
 * one vector, and averaging a threat together with three sentences about
 * the exam timetable destroyed it. Measured: a 49-173% margin drop, and
 * in four of six cases the top category flipped to one that fires
 * nothing. Every layer looked healthy in isolation.
 *
 * The fix was to score clauses as well as the whole post
 * (text_classifier.split_sentences). These cases are the proof, and they
 * are written as real requests because that is the only level at which
 * "can a student publish this" is actually answered.
 *
 * Opt-in: needs the self-hosted service running, because the point is to
 * exercise the real model rather than a fake of it.
 *
 *   RUN_LIVE_MODERATION_TESTS=1 php artisan test --filter=LongFormModerationE2ETest
 */
class LongFormModerationE2ETest extends TestCase
{
    use RefreshDatabase;

    /** Ordinary campus writing, wrapped around whatever is under test. */
    private const OPENING = 'Bugün kütüphanede grup çalışması yaptık, finaller için ders notlarını paylaştık ve yarınki sunum için provaya başladık. ';

    private const CLOSING = ' Neyse, akşam yemeği için kafeteryada buluşuruz, herkes gelsin, program panoda asılı.';

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_LIVE_MODERATION_TESTS') !== '1') {
            $this->markTestSkipped('Needs the self-hosted moderation service running.');
        }

        config([
            'moderation.text.enabled' => true,
            'moderation.text.base_url' => getenv('LIVE_TEXT_MODERATION_URL')
                ?: 'http://127.0.0.1:8801',
            'services.moderation.openai_key' => '',
            // These cases run in sequence against one account. With the
            // strike ladder live the first refusals would ban it and
            // everything after would return 403 — which reads as "did not
            // publish" while proving nothing about the content.
            'moderation.enforcement.enabled' => false,
        ]);
    }

    /**
     * A 2xx is the only outcome that means the post went up: the feed
     * returns 200 on success and 400 when moderation refuses. An earlier
     * draft of this file asserted `!== 201` and so passed on everything,
     * including the six ordinary posts it was meant to protect.
     */
    private function published(string $text): bool
    {
        $status = $this->postJson('/api/v1/feed', ['text' => $text])->status();

        return $status >= 200 && $status < 300;
    }

    /** @return array<string, list<string>> */
    public static function buriedHarm(): array
    {
        return [
            'tr threat' => ['Bir daha karşıma çıkarsan yüzünü dağıtırım.'],
            'tr third person threat' => ['Mehmet karşıma çıkarsa onu geberteceğim.'],
            'tr sexual harassment' => ['Numaranı ver güzelim, geceleri seni yalnız bırakmam.'],
            'tr scam' => ['Garantili kazanç, önce kapora gönder, hemen katlıyorum.'],
            'tr doxxing' => ['İşte adresi, gidin bulun kendisi anlar.'],
            'tr profanity' => ['Şoför bana siktir git dedi resmen.'],
            // Reported from the live feed, typo included.
            'en sexual solicitation' => ['gorup sex do u want to come in'],
            'tr sexual solicitation' => ['Grup seks isteyen var mı, akşam buluşalım.'],
            'en threat' => ['If that guy shows up again I will break his legs.'],
            'en harassment' => ['You are a pathetic loser and nobody wants you here.'],
            'en cybercrime' => ['Send me your portal password and I will register for you.'],
            'en profanity' => ['I asked him about it and he told me to fuck off.'],
            'ru threat' => ['Если этот парень ещё раз появится, я его убью.'],
            'ru harassment' => ['Ты жалкое ничтожество, тебя здесь никто не хочет видеть.'],
            'ru profanity' => ['Я спросил водителя, а он сказал мне иди на хуй.'],
        ];
    }

    #[DataProvider('buriedHarm')]
    public function test_a_harmful_clause_inside_a_long_post_does_not_publish(string $clause): void
    {
        $this->actingAsUser();

        $this->assertFalse(
            $this->published(self::OPENING.$clause.self::CLOSING),
            "Published a post containing: \"{$clause}\"",
        );
    }

    /**
     * Position must not matter. The same clause at the start, the middle
     * and the end of a post is the same violation.
     */
    public function test_position_within_the_post_does_not_change_the_outcome(): void
    {
        $this->actingAsUser();
        $clause = 'Bir daha karşıma çıkarsan yüzünü dağıtırım.';

        $published = [];
        foreach ([
            'start' => $clause.self::CLOSING,
            'middle' => self::OPENING.$clause.self::CLOSING,
            'end' => self::OPENING.$clause,
            'alone' => $clause,
        ] as $where => $text) {
            if ($this->published($text)) {
                $published[] = $where;
            }
        }

        $this->assertSame([], $published,
            'Published when the threat sat at: '.implode(', ', $published));
    }

    /**
     * The other half, and the one that decides whether this system stays
     * switched on: long ordinary posts about hard subjects must publish.
     *
     * Clause scoring made the layer strictly more sensitive, and the first
     * run of it refused an exhibition announcement as DRUG and held a
     * warning about phishing as impersonation.
     */
    public function test_long_posts_about_difficult_subjects_still_publish(): void
    {
        $this->actingAsUser();

        $cases = [
            'Psikolojik danışmanlık birimi bu dönem intiharı önleme ve kendine zarar verme konularında bir seminer dizisi düzenliyor, katılım ücretsiz ve kayıt gerekmiyor. Seminerlerde kriz anında nasıl destek alınacağı anlatılacak. İlk oturum perşembe günü Rodin salonunda.',
            'Siber güvenlik farkındalık haftası kapsamında oltalama saldırılarından korunma eğitimi düzenlenecek. Öğrenci işleri hiçbir koşulda sizden şifre istemez, böyle bir e-posta alırsanız bilgi işleme bildirin. Eğitim salı günü amfide yapılacak.',
            'The life drawing class is running again this term and you will need charcoal and a large sketchbook. The course follows the classical figure drawing tradition with a strong emphasis on anatomy studies. Places are limited to twenty, apply through the department office.',
            'Ношение оружия на территории кампуса строго запрещено, и это правило распространяется также на посетителей, охрана проводит проверку на входе. К нарушителям применяются дисциплинарные меры. Линия охраны работает круглосуточно.',
            'Malzemeleri atölyeye taşıdık ve maliyeti hesapladık. Sikke koleksiyonu sergisi için vitrin düzenlemesi tamamlandı, açılış cuma akşamı. Hafif siklet boks maçı aynı akşam olduğu için saat çakışması var.',
            'I left the studio key with security this morning, sorry about last night. The lock sticks a bit so do not force it when you turn it or it will break. Maintenance are looking at it next week.',
            // The announcements the sexual-solicitation rule most endangers:
            // each contains the explicit word, and the consent workshop even
            // contains an invitation.
            'Cinsel tacize karşı bilinçlendirme çalıştayı başvuruları açıldı, rıza kavramı ve bildirim mekanizmaları ele alınacak. Katılmak isteyen arkadaşlar bölüm sekreterliğine yazabilir. Katılım belgesi verilecektir.',
            'The sex education and sexual health seminar is on Tuesday at two, everyone is welcome and no registration is needed. Anyone want to come along with me? The counselling service is running it.',
            'Семинар по профилактике сексуального насилия пройдёт во вторник, обсудим согласие и порядок обращения. Приходите, участие свободное. Организует психологическая служба.',
        ];

        $refused = [];
        foreach ($cases as $text) {
            if (! $this->published($text)) {
                $refused[] = mb_substr($text, 0, 70);
            }
        }

        $this->assertSame([], $refused, "Refused ordinary posts:\n".implode("\n", $refused));
    }
}
