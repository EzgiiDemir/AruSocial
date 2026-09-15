<?php

namespace Tests\Unit;

use App\Services\Moderation\ModerationVerdict;
use App\Services\Moderation\TextPolicyEngine;
use PHPUnit\Framework\TestCase;

/**
 * One case per published policy category, so "we moderate X" stays a fact
 * rather than an intention.
 *
 * Written after an audit found nine categories that were on the policy list
 * and enforced nowhere — child safety, terrorism, criminal instructions,
 * phishing, impersonation, misinformation, piracy and animal abuse all
 * published freely. A list of categories in a document proves nothing; this
 * is the thing that fails when one of them silently stops working.
 *
 * Not every category ends in a block, and that is the design:
 *   - self-harm publishes and offers support, because punishing someone in
 *     crisis is the wrong response;
 *   - spam warns, because a nuisance is not an attack;
 *   - misinformation goes to a human, because deciding what is true is not
 *     a word list's job.
 */
class PolicyCoverageTest extends TestCase
{
    private TextPolicyEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new TextPolicyEngine;
    }

    /** @return array<string, list<array{0: string, 1: string}>> category => [text, expected context] */
    private function categories(): array
    {
        return [
            // `directed_profanity`, not `dismissal`: the decision is
            // unchanged (remove), but the reason is now the more precise
            // one. "Siktir" is an imperative aimed at a person, and it is
            // refused on its own rather than only when followed by "git"
            // — which is the gap this context was added to close.
            'profanity' => [['Siktir git buradan.', 'directed_profanity']],
            'harassment' => [['@ahmet sen tam bir aptalsın.', 'direct_attack']],
            'threats' => [['Bir daha karşıma çıkarsan yüzünü dağıtırım.', 'threat']],
            'hate_speech' => [
                ['lanet zenci', 'hate_slur'],
                ['pis arap', 'hate_group'],
                ['hitler haklıydı', 'hate_group'],
            ],
            'sexual_harassment' => [
                ['Numaranı ver güzelim, geceleri seni yalnız bırakmam.', 'sexual_harassment'],
            ],
            'sextortion' => [['Fotoğraflarını yayarım eğer konuşursan', 'sextortion']],
            'minor_safety' => [
                ['13 yaşındaki kızlarla görüşmek istiyorum, numaram var', 'minor_safety'],
            ],
            'self_harm' => [['Kendimi öldürmek istiyorum', 'self_harm']],
            'terrorism' => [
                ['Kampüse bomba koyacağım', 'terrorism'],
                ['IŞİD için savaşmaya gidiyorum', 'terrorism'],
            ],
            'weapons' => [['Silahım var yarın getireceğim', 'weapon']],
            'criminal_instructions' => [
                ['Evde bomba nasıl yapılır anlatayım', 'criminal_instructions'],
            ],
            'drug_sale' => [['Kampüste ot satıyorum yazın', 'drug_sale']],
            'scam_fraud' => [['Garantili kazanç, önce kapora gönder', 'scam_fraud']],
            'cybercrime' => [
                ['Öğrenci portalı şifreni bana yolla', 'cybercrime'],
            ],
            'doxxing' => [['İşte adresi, gidin bulun', 'doxxing']],
            'pii' => [
                ['Numarası 05321234567 arayın', 'pii_exposure'],
                ['TC kimlik 10000000146 paylaşıyorum', 'pii_exposure'],
            ],
            'impersonation' => [
                ['Ben rektörüm, hesabınıza para yatırın', 'impersonation'],
            ],
            'spam' => [['Bedava takipçi için link tıkla kazan', 'spam_solicitation']],
            'misinformation' => [['Aşılar çip içeriyor, olmayın', 'misinformation']],
            'piracy' => [
                ['Bütün ders kitaplarının pdf crackini satıyorum', 'piracy'],
            ],
            'animal_abuse' => [['Kampüsteki kedileri zehirleyeceğim', 'animal_abuse']],
            'political' => [
                ['Genel seçimlerde oy verin, AKP kazanmalı', 'political'],
            ],
            'academic_dishonesty' => [
                ['Sınav soruları elimde, isteyene satıyorum', 'academic_dishonesty'],
            ],
        ];
    }

    public function test_every_published_category_is_actually_decided(): void
    {
        $unenforced = [];
        foreach ($this->categories() as $category => $rows) {
            foreach ($rows as [$text, $expected]) {
                $verdict = $this->engine->evaluate($text);
                if ($verdict->context !== $expected) {
                    $unenforced[] = sprintf(
                        '%s: expected %s, got %s/%s — "%s"',
                        $category, $expected, $verdict->decision, $verdict->context, $text,
                    );
                }
            }
        }

        $this->assertSame([], $unenforced, implode("\n", $unenforced));
    }

    /**
     * Three categories deliberately do not block. Pinned here so a later
     * "tighten everything" change cannot quietly start punishing a student
     * in crisis or refusing an argument someone disagrees with.
     */
    public function test_the_non_blocking_categories_stay_non_blocking(): void
    {
        $cases = [
            // Published, no strike, support offered.
            ['Kendimi öldürmek istiyorum', ModerationVerdict::REVIEW],
            // Ordinary advertising/link sharing is not automated spam.
            ['Yeni sergimizi web sitemizden inceleyebilirsiniz', ModerationVerdict::ALLOW],
            // Discussion is not a harmful misinformation call to action.
            ['Aşı güvenliği hakkında akademik bir panel düzenleniyor', ModerationVerdict::ALLOW],
        ];

        $wrong = [];
        foreach ($cases as [$text, $expected]) {
            $verdict = $this->engine->evaluate($text);
            if ($verdict->decision !== $expected || $verdict->blocksPublication()) {
                $wrong[] = sprintf('%s → %s (%s)', $text, $verdict->decision, $verdict->context);
            }
        }

        $this->assertSame([], $wrong, implode("\n", $wrong));
    }

    /**
     * A university has to be able to announce difficult things.
     *
     * These are the posts most likely to be caught by a policy word list —
     * and refusing them is worse than useless: a suicide-prevention notice
     * or a phishing warning is the campus doing its job. The impersonation
     * case is here because it actually happened: anchoring the rule on
     * "öğrenci işleri" near "şifre" refused "öğrenci işleri asla şifre
     * istemez", the exact advice that prevents the scam.
     */
    public function test_official_announcements_about_hard_subjects_publish(): void
    {
        $cases = [
            'İntiharı önleme semineri 14 Mart Perşembe günü Rodin salonunda.',
            'Psikolojik danışmanlık birimi kendine zarar verme konusunda destek sunar.',
            'Kampüste silah bulundurmak kesinlikle yasaktır.',
            'Uyuşturucu ile mücadele semineri: bağımlılık ve tedavi süreçleri.',
            'Terörle Mücadele Hukuku dersi bu dönem açılmıştır.',
            'Siber güvenlik eğitimi: phishing saldırılarından korunma yolları.',
            'Şifrenizi kimseyle paylaşmayın, öğrenci işleri asla şifre istemez.',
            'Rektörlük adına yapılan sahte e-postalara dikkat edin.',
            'Nefret söylemine karşı farkındalık paneli düzenlenecektir.',
            'Cinsel tacize karşı bilinçlendirme çalıştayı başvuruları açıldı.',
            'Telif hakları ve intihal semineri kütüphanede.',
        ];

        $refused = [];
        foreach ($cases as $text) {
            $verdict = $this->engine->evaluate($text);
            if ($verdict->blocksPublication()) {
                $refused[] = sprintf('%s (%s) — "%s"',
                    $verdict->decision, $verdict->context, $text);
            }
        }

        $this->assertSame([], $refused, implode("\n", $refused));
    }

    /**
     * The other half of coverage: ordinary campus speech that touches these
     * subjects must survive. A filter that eats normal posts gets disabled,
     * and then nothing is moderated at all.
     */
    public function test_ordinary_speech_about_these_subjects_is_untouched(): void
    {
        $cases = [
            'Bu ödev beni öldürecek',                  // hyperbole, not self-harm
            'intihar oranları arttı, seminer var',     // discussion, not intent
            'siyahi arkadaşım çok iyi biri',           // identity, not hate
            'Kürt müziği dinliyorum',
            'Ders 204 numaralı sınıfta, dahili 1006',  // numbers, not PII
            'Sipariş takip kodu 12345678901',          // invalid T.C. checksum
            'Terörle mücadele dersi aldım',            // academic subject
            '13 yaşındaki kardeşimle sinemaya gittik', // minor, benign context
            'Silahların tarihi üzerine belgesel izledim',
            'Kedimi veterinere götürdüm',
            'Siyaset bilimi bölümünde okuyorum',
            'CHP ve AKP tartışması haberlerdeydi',      // reference, not propaganda
            'Kütüphanede sınava çalışıyorum',
        ];

        $wrong = [];
        foreach ($cases as $text) {
            $verdict = $this->engine->evaluate($text);
            if ($verdict->decision !== ModerationVerdict::ALLOW) {
                $wrong[] = sprintf('%s → %s (%s)', $text, $verdict->decision, $verdict->context);
            }
        }

        $this->assertSame([], $wrong, implode("\n", $wrong));
    }
}
