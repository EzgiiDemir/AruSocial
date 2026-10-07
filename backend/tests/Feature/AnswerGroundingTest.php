<?php

namespace Tests\Feature;

use App\Models\KnowledgeDocument;
use App\Services\Ai\AnswerGrounding;
use App\Services\Knowledge\KnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression for an answer that invented, in one reply, monthly scholarship
 * amounts ("12.000 TL/ay"), a source URL (arucad.edu.tr/burslar/) and a contact
 * address (burs@arucad.edu.tr) — none of which existed in any indexed page.
 *
 * Prompt rules did not hold, so the checkable claims are now verified against
 * what the model was actually given.
 */
class AnswerGroundingTest extends TestCase
{
    use RefreshDatabase;

    private AnswerGrounding $grounding;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grounding = app(AnswerGrounding::class);
    }

    private const SOURCE = 'ARUCAD burs oranları %50, %75, %90 ve %100 olabilir. '
        .'Arkeoloji 65.000,00₺ ücret. Kaynak: https://aday.arucad.edu.tr/burs-ve-indirimler/ '
        .'İletişim: ogrenciisleri@arucad.edu.tr';

    public function test_it_catches_an_invented_amount_url_and_email(): void
    {
        $answer = 'Burs 12.000 TL/ay tutarındadır. (Kaynak: https://arucad.edu.tr/burslar/) '
            .'Detay için burs@arucad.edu.tr adresine yazın.';

        $report = $this->grounding->ungrounded($answer, self::SOURCE);

        $this->assertFalse($this->grounding->isClean($report));
        $this->assertContains('https://arucad.edu.tr/burslar/', $report['urls']);
        $this->assertContains('burs@arucad.edu.tr', $report['emails']);
        $this->assertNotEmpty($report['figures']);
    }

    public function test_it_accepts_an_answer_built_from_the_sources(): void
    {
        $answer = 'ARUCAD %50 ve %75 burs sunar; Arkeoloji ücreti 65.000,00₺. '
            .'Kaynak: https://aday.arucad.edu.tr/burs-ve-indirimler/ '
            .'Sorular için ogrenciisleri@arucad.edu.tr';

        $this->assertTrue(
            $this->grounding->isClean($this->grounding->ungrounded($answer, self::SOURCE)),
        );
    }

    /** Formatting differences must not read as fabrication. */
    public function test_number_formatting_does_not_count_as_invention(): void
    {
        $report = $this->grounding->ungrounded('Ücret 65000 ₺.', 'Ücret 65.000,00₺');

        $this->assertTrue($this->grounding->isClean($report));
    }

    /** A link we indexed is legitimate even if this prompt did not carry it. */
    public function test_an_indexed_url_is_grounded_even_if_absent_from_the_prompt(): void
    {
        KnowledgeDocument::create([
            'id' => KnowledgeDocument::idForUrl('https://arucad.edu.tr/kampus/'),
            'url' => 'https://arucad.edu.tr/kampus/',
            'domain' => 'arucad.edu.tr', 'title' => 'Kampüs',
            'content' => 'Kampüs bilgisi.', 'content_hash' => 'k',
            'content_length' => 20, 'fetched_at' => now(),
        ]);

        $report = $this->grounding->ungrounded(
            'Bilgi: https://arucad.edu.tr/kampus/', 'başka bir metin',
        );

        $this->assertTrue($this->grounding->isClean($report));
    }

    /** Ordinary prose has nothing checkable and must never be flagged. */
    public function test_advice_without_figures_is_left_alone(): void
    {
        $answer = 'Grafik tasarım yaratıcı bir alandır; ilgi alanlarına göre '
            .'seçim yapmanı öneririm.';

        $this->assertTrue(
            $this->grounding->isClean($this->grounding->ungrounded($answer, self::SOURCE)),
        );
    }

    /**
     * "ne burslarınız var?" found nothing, because a single-pass stemmer never
     * reduced "burslarınız" to the "burs" the page actually contains.
     */
    public function test_turkish_suffix_chains_still_reach_the_root(): void
    {
        $kb = app(KnowledgeBase::class);
        $stem = new \ReflectionMethod($kb, 'stem');

        $this->assertSame('burs', $stem->invoke($kb, 'burslarınız'));
        $this->assertSame('burs', $stem->invoke($kb, 'burslar'));
        $this->assertSame('burs', $stem->invoke($kb, 'bursları'));
        $this->assertSame('program', $stem->invoke($kb, 'programlarınız'));
        $this->assertSame('bölüm', $stem->invoke($kb, 'bölümler'));
    }

    public function test_a_scholarship_question_retrieves_the_scholarship_page(): void
    {
        KnowledgeDocument::create([
            'id' => KnowledgeDocument::idForUrl('https://aday.arucad.edu.tr/burs-ve-indirimler/'),
            'url' => 'https://aday.arucad.edu.tr/burs-ve-indirimler/',
            'domain' => 'aday.arucad.edu.tr', 'title' => 'Burslar ve Ücretler',
            'content' => 'ARUCAD burs ve indirim oranları: %50, %75, %90, %100.',
            'content_hash' => 'b', 'content_length' => 60, 'fetched_at' => now(),
        ]);

        $hits = app(KnowledgeBase::class)->relevant('ne burslarınız var', 3);

        $this->assertNotEmpty($hits);
        $this->assertSame('https://aday.arucad.edu.tr/burs-ve-indirimler/', $hits[0]['url']);
    }
}
