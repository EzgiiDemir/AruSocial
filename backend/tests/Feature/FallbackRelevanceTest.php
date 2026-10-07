<?php

namespace Tests\Feature;

use App\Models\KnowledgeDocument;
use App\Services\CampusAskFallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The degraded path, taken when the model is unavailable.
 *
 * Nothing downstream checks it, so a page that merely ranks first must not
 * be presented as an answer.
 */
class FallbackRelevanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['knowledge.embeddings.enabled' => false]);
        Cache::flush();
    }

    private function page(string $path, string $title, string $content): void
    {
        KnowledgeDocument::create([
            'id' => sha1($path),
            'url' => 'https://arucad.edu.tr'.$path,
            'domain' => 'arucad.edu.tr',
            'title' => $title,
            'content' => $content,
            'content_hash' => sha1($path.$content),
            'content_length' => mb_strlen($content),
            'http_status' => 200,
            'language' => 'tr',
            'document_status' => 'indexed',
            'fetched_at' => now(),
            'is_stale' => false,
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Measured: "tatil ne zaman" was answered with a news item about a
     * children's event, introduced by "Bu bilgiyi resmi ARUCAD sayfasında
     * buldum" — noise presented as a finding.
     */
    public function test_an_unrelated_page_is_not_quoted_as_an_answer(): void
    {
        $this->page(
            '/cocuk-etkinligi/',
            'Çocuklar için Eğlence Etkinliği',
            'ARUCAD öğrencileri çocuklarla bir araya geldi. Etkinlik halka açık ve ücretsizdi.',
        );

        $answer = app(CampusAskFallback::class)->answer('kayıt dondurma nasıl yapılır');

        $this->assertStringNotContainsString('resmi ARUCAD sayfasında buldum', $answer);
        $this->assertStringNotContainsString('Çocuklar için', $answer);
    }

    /** A page that does address the question is still quoted, with its URL. */
    public function test_a_related_page_is_still_quoted(): void
    {
        $this->page(
            '/kutuphane/',
            'Kütüphane',
            'ARUCAD Kütüphane hafta içi 09:00-17:00 arası açıktır ve çalışma odaları bulunur.',
        );

        $answer = app(CampusAskFallback::class)->answer('kütüphane çalışma saatleri nedir');

        $this->assertStringContainsString('resmi ARUCAD sayfasında buldum', $answer);
        $this->assertStringContainsString('arucad.edu.tr/kutuphane', $answer);
    }
}
