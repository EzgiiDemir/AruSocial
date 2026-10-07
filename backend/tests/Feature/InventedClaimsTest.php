<?php

namespace Tests\Feature;

use App\Services\Ai\AnswerGrounding;
use App\Support\CredentialRequest;
use Tests\TestCase;

/**
 * Claims that a fluent answer can get wrong in ways a student acts on.
 *
 * Each case here was produced by the real assistant on a real question. They
 * are grouped because they share a cause: the topic was retrieved, so every
 * relevance check passed, and the specific claim was invented anyway.
 */
class InventedClaimsTest extends TestCase
{
    private function grounding(): AnswerGrounding
    {
        return app(AnswerGrounding::class);
    }

    // --------------------------------------------------------- named things

    /**
     * Asked whether ARUCAD is a state university, the assistant answered
     * that it is governed by "Arkın Eğitim Kültür ve Araştırma Vakfı
     * (AREKAV)". There is no such body. Seven sources were retrieved, so
     * the topic was grounded and the claim was not.
     */
    public function test_an_invented_institution_is_caught(): void
    {
        $report = $this->grounding()->ungrounded(
            'ARUCAD, Arkın Eğitim Kültür ve Araştırma Vakfı (AREKAV) tarafından yönetilir.',
            'ARUCAD Kuzey Kıbrıs KKTC vakıf üniversitesidir. YÖK onaylıdır.',
        );

        $this->assertSame(['AREKAV'], $report['names']);
        $this->assertFalse($this->grounding()->isClean($report));
    }

    /** Asked about dormitories it invented an application system, "AYDIN". */
    public function test_an_invented_system_name_is_caught(): void
    {
        $report = $this->grounding()->ungrounded(
            'Yurt başvurusunu AYDIN sistemi üzerinden yapman gerekir.',
            'Yurt başvurusu aday.arucad.edu.tr adresinden yapılır.',
        );

        $this->assertSame(['AYDIN'], $report['names']);
    }

    /** Acronyms the prompt already carries are not invented. */
    public function test_real_acronyms_are_not_flagged(): void
    {
        $report = $this->grounding()->ungrounded(
            'ARUCAD bir vakıf üniversitesidir ve YÖK tarafından onaylıdır.',
            'ARUCAD Kuzey Kıbrıs vakıf üniversitesi, YÖK onaylı.',
        );

        $this->assertSame([], $report['names']);
    }

    // --------------------------------------------------------------- dates

    /**
     * The regression that prompted this check.
     *
     * Told that the only exam timetable held was last year's, the model did
     * not conclude that the new one was unpublished — it produced a date for
     * the new year that exists nowhere. A stale-but-real date had been
     * replaced by a fabricated one.
     */
    public function test_an_invented_date_is_caught(): void
    {
        $report = $this->grounding()->ungrounded(
            "2026-2027 final sınavları 2026-09-01'den itibaren başlamıştır.",
            '2025-2026 Güz Dönemi Final Sınav Takvimi 21/01/2026 tarihinde başlar.',
        );

        $this->assertSame(['2026-09-01'], $report['dates']);
    }

    /** The same day in another format is the same day, not an invention. */
    public function test_a_real_date_in_another_format_passes(): void
    {
        $report = $this->grounding()->ungrounded(
            'Sınavlar 21.01.2026 tarihinde başlar.',
            'Final Sınav Takvimi 21/01/2026 12:12',
        );

        $this->assertSame([], $report['dates']);
    }

    /** An academic year is a reference, not a claim about a day. */
    public function test_a_bare_academic_year_is_not_a_date_claim(): void
    {
        $report = $this->grounding()->ungrounded(
            '2026-2027 akademik yılı takvimi henüz yayımlanmamıştır.',
            '2025-2026 Güz Dönemi Final Sınav Takvimi.',
        );

        $this->assertSame([], $report['dates']);
    }

    // --------------------------------------------------------- credentials

    /**
     * "wifi şifresi ne" was answered `Wi-Fi şifresi genellikle "ARUCAD"
     * olarak ayarlanmıştır`. Nothing in that sentence is checkable — no URL,
     * no figure, no acronym — so it is refused by category instead.
     */
    public function test_asking_for_a_password_is_refused_by_category(): void
    {
        foreach ([
            'wifi şifresi ne',
            'kampüs wifi parolası nedir',
            'what is the wifi password?',
            'какой пароль от wifi',
        ] as $question) {
            $this->assertTrue(
                CredentialRequest::isCredentialRequest($question),
                "should refuse: {$question}",
            );
        }
    }

    /**
     * Resetting a password is an ordinary support question and must still be
     * answered from the pages — the refusal is about handing out a secret,
     * not about the word "şifre".
     */
    public function test_password_procedures_are_still_answerable(): void
    {
        foreach ([
            'şifremi unuttum ne yapmalıyım',
            'şifremi nasıl değiştirebilirim',
            'how do I reset my password',
            'kütüphane nerede',
        ] as $question) {
            $this->assertFalse(
                CredentialRequest::isCredentialRequest($question),
                "should answer: {$question}",
            );
        }
    }

    /** The refusal names where to go instead of just declining. */
    public function test_the_credential_refusal_points_somewhere(): void
    {
        $this->assertStringContainsString('BT Destek', CredentialRequest::refusal('tr'));
        $this->assertStringContainsString('IT Support', CredentialRequest::refusal('en'));
    }
}
