<?php

namespace Tests\Feature;

use App\Models\KnowledgeDocument;
use App\Services\Knowledge\HtmlTextExtractor;
use App\Services\Knowledge\KnowledgeBase;
use App\Services\Knowledge\SiteKnowledgeCrawler;
use App\Services\Knowledge\UrlSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The crawler and knowledge base are the self-hosted "bot" behind Ask
 * ARUVERSE. Every network fetch is faked — no test may reach the real ARUCAD
 * sites — and the scope guarantees (allow-list, excluded paths) are pinned so
 * a later change cannot quietly let the bot wander into a login area.
 */
class SiteKnowledgeCrawlerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'knowledge.enabled' => true,
            'knowledge.verify_ssl' => false,
            'knowledge.delay_ms' => 0,
            'knowledge.discover_links' => false,
            // Hermetic: no real DNS (SSRF check) and no sitemap fetch in the
            // core tests. Both get their own dedicated tests below.
            'knowledge.verify_public_ip' => false,
            'knowledge.use_sitemaps' => false,
            'knowledge.allowed_domains' => ['arucad.edu.tr', 'aday.arucad.edu.tr'],
            'knowledge.seed_urls' => ['https://arucad.edu.tr/neden-arucad/'],
        ]);
    }

    private function fakeHtml(string $title, string $body): string
    {
        return "<html><head><title>{$title}</title></head><body>"
            ."<nav>menü giriş çıkış</nav><p>{$body}</p>"
            .'<script>var x=1;</script></body></html>';
    }

    public function test_it_crawls_a_page_and_stores_extracted_text(): void
    {
        Http::fake([
            'arucad.edu.tr/neden-arucad/' => Http::response(
                $this->fakeHtml('Neden ARUCAD', 'ARUCAD sanat ve tasarım odaklı bir üniversitedir. Burslar mevcuttur.'),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        $summary = app(SiteKnowledgeCrawler::class)->crawl(force: true);

        $this->assertSame(1, $summary['updated']);
        $doc = KnowledgeDocument::first();
        $this->assertSame('Neden ARUCAD', $doc->title);
        $this->assertStringContainsString('sanat ve tasarım', $doc->content);
        // Navigation and script content must not be stored as knowledge.
        $this->assertStringNotContainsString('menü giriş', $doc->content);
        $this->assertStringNotContainsString('var x=1', $doc->content);
    }

    /**
     * One oversized file must not take the run down with it.
     *
     * There was a size check, but it read `$response->body()` first — the
     * whole file was in memory before anything looked at how big it was.
     * Measured: a 32 MB body exhausted a 128 MB process part-way through a
     * crawl and every page after it was lost.
     */
    public function test_an_oversized_response_is_skipped_not_fatal(): void
    {
        config(['knowledge.max_bytes' => 2048, 'knowledge.max_pdf_bytes' => 2048]);

        Http::fake([
            'arucad.edu.tr/huge/' => Http::response(
                str_repeat('x', 50000),
                200,
                ['Content-Type' => 'text/html'],
            ),
            'arucad.edu.tr/small/' => Http::response(
                $this->fakeHtml('Küçük Sayfa', 'ARUCAD kütüphanesi sanat ve tasarım kaynakları sunar; çalışma odaları ve dijital kütüphane laboratuvarı bulunur.'),
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        config(['knowledge.seed_urls' => [
            'https://arucad.edu.tr/huge/',
            'https://arucad.edu.tr/small/',
        ]]);

        $summary = app(SiteKnowledgeCrawler::class)->crawl(force: true);

        // The big one is refused and the run carries on to the next page.
        // The big one is refused and the run carries on to the next page.
        $this->assertSame(1, $summary['failed']);
        $this->assertNull(KnowledgeDocument::where('url', 'https://arucad.edu.tr/huge/')->first());
        $this->assertNotNull(KnowledgeDocument::where('url', 'https://arucad.edu.tr/small/')->first());
    }

    /**
     * A connection that dies mid-body fails that page, not the run.
     *
     * Streaming the response moved the read out of Guzzle's error handling,
     * so a truncated body surfaced as an exception from Stream::read() and
     * ended the whole crawl — the same failure the size cap was added to
     * prevent, arriving by another route.
     */
    public function test_a_broken_stream_does_not_end_the_crawl(): void
    {
        Http::fake([
            'arucad.edu.tr/broken/' => function () {
                throw new \RuntimeException('Unable to read from stream');
            },
            'arucad.edu.tr/good/' => Http::response(
                $this->fakeHtml('İyi Sayfa', 'ARUCAD kütüphanesi sanat ve tasarım kaynakları sunar, çalışma odaları vardır.'),
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        config(['knowledge.seed_urls' => [
            'https://arucad.edu.tr/broken/',
            'https://arucad.edu.tr/good/',
        ]]);

        $summary = app(SiteKnowledgeCrawler::class)->crawl(force: true);

        $this->assertNotNull(KnowledgeDocument::where('url', 'https://arucad.edu.tr/good/')->first());
        $this->assertSame(1, $summary['failed']);
    }

    public function test_it_can_refresh_one_allow_listed_page_on_demand(): void
    {
        Http::fake([
            'arucad.edu.tr/lisans-akademik-takvim/' => Http::response(
                $this->fakeHtml(
                    'Lisans Akademik Takvim',
                    '2026-2027 Güz Dönemi Ders Başlangıcı 28 Eylül 2026 olarak ilan edilmiştir.',
                ),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        $crawler = app(SiteKnowledgeCrawler::class);

        $this->assertTrue($crawler->refreshUrl('https://arucad.edu.tr/lisans-akademik-takvim/'));
        $this->assertDatabaseHas('knowledge_documents', [
            'url' => 'https://arucad.edu.tr/lisans-akademik-takvim/',
        ]);
        $this->assertFalse($crawler->refreshUrl('https://evil.example.com/calendar'));
        Http::assertSentCount(1);
    }

    public function test_a_fresh_page_is_not_refetched_without_force(): void
    {
        Http::fake([
            'arucad.edu.tr/*' => Http::response(
                $this->fakeHtml('T', 'Yeterince uzun bir içerik metni burada bulunuyor ki saklansın diye.'),
                200, ['Content-Type' => 'text/html'],
            ),
        ]);

        app(SiteKnowledgeCrawler::class)->crawl(force: true);
        $second = app(SiteKnowledgeCrawler::class)->crawl(force: false);

        // Second pass sees a fresh row and skips the network entirely.
        $this->assertSame(0, $second['fetched']);
        $this->assertSame(1, $second['skipped']);
    }

    public function test_excluded_paths_and_foreign_domains_are_never_fetched(): void
    {
        $crawler = app(SiteKnowledgeCrawler::class);

        $this->assertFalse($crawler->isAllowed('https://arucad.edu.tr/wp-admin/index.php'));
        $this->assertFalse($crawler->isAllowed('https://arucad.edu.tr/login'));
        $this->assertFalse($crawler->isAllowed('https://sis.arucad.edu.tr/'));   // not allow-listed
        $this->assertFalse($crawler->isAllowed('https://evil.example.com/'));
        $this->assertFalse($crawler->isAllowed('http://arucad.edu.tr/'));        // not https
        $this->assertTrue($crawler->isAllowed('https://arucad.edu.tr/fakulteler/'));
    }

    public function test_discovery_only_follows_allowed_in_domain_links(): void
    {
        config(['knowledge.discover_links' => true]);
        Http::fake([
            'arucad.edu.tr/neden-arucad/' => Http::response(
                '<html><title>Seed</title><body><p>'.str_repeat('içerik ', 40).'</p>'
                .'<a href="/fakulteler/">iç</a>'
                .'<a href="https://evil.example.com/x">dış</a>'
                .'<a href="/wp-admin/">yasak</a></body></html>',
                200, ['Content-Type' => 'text/html'],
            ),
            'arucad.edu.tr/fakulteler/' => Http::response(
                '<html><title>Fakülteler</title><body><p>'.str_repeat('bölüm ', 40).'</p></body></html>',
                200, ['Content-Type' => 'text/html'],
            ),
        ]);

        $summary = app(SiteKnowledgeCrawler::class)->crawl(force: true);

        // Seed + the one in-domain link. The external and wp-admin links are
        // never requested (Http::preventStrayRequests would fail otherwise).
        $this->assertSame(2, $summary['fetched']);
        $this->assertTrue(KnowledgeDocument::where('url', 'https://arucad.edu.tr/fakulteler/')->exists());
    }

    public function test_unreadable_pdf_is_recorded_but_not_indexed_and_http_error_is_not_stored(): void
    {
        config(['knowledge.seed_urls' => [
            'https://arucad.edu.tr/a.pdf', 'https://arucad.edu.tr/missing',
        ]]);
        Http::fake([
            'arucad.edu.tr/a.pdf' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']),
            'arucad.edu.tr/missing' => Http::response('nope', 404, ['Content-Type' => 'text/html']),
        ]);

        $summary = app(SiteKnowledgeCrawler::class)->crawl(force: true);

        $this->assertSame(1, KnowledgeDocument::count());
        $pdf = KnowledgeDocument::firstOrFail();
        $this->assertSame('application/pdf', $pdf->content_type);
        $this->assertContains($pdf->document_status, ['no_extractable_text', 'extraction_failed']);
        $this->assertSame('', $pdf->content);
        $this->assertNotNull($pdf->last_error);
        $this->assertSame(2, $summary['failed']);
    }

    public function test_knowledge_base_returns_relevant_sourced_snippets(): void
    {
        KnowledgeDocument::create([
            'id' => KnowledgeDocument::idForUrl('https://aday.arucad.edu.tr/burs-ve-indirimler/'),
            'url' => 'https://aday.arucad.edu.tr/burs-ve-indirimler/',
            'domain' => 'aday.arucad.edu.tr',
            'title' => 'Burs ve İndirimler',
            'content' => 'ARUCAD başarılı öğrencilere burs ve indirim imkânları sunar. Burs oranları programa göre değişir.',
            'content_hash' => 'x', 'content_length' => 100, 'fetched_at' => now(),
        ]);
        KnowledgeDocument::create([
            'id' => KnowledgeDocument::idForUrl('https://arucad.edu.tr/ulasim/'),
            'url' => 'https://arucad.edu.tr/ulasim/',
            'domain' => 'arucad.edu.tr',
            'title' => 'Ulaşım',
            'content' => 'Kampüse ulaşım için servis ve otobüs hatları mevcuttur.',
            'content_hash' => 'y', 'content_length' => 60, 'fetched_at' => now(),
        ]);

        $hits = app(KnowledgeBase::class)->relevant('burs oranları nedir');

        $this->assertNotEmpty($hits);
        $this->assertSame('https://aday.arucad.edu.tr/burs-ve-indirimler/', $hits[0]['url']);

        $block = app(KnowledgeBase::class)->contextBlock('burs oranları nedir');
        $this->assertStringContainsString('Kaynak: https://aday.arucad.edu.tr/burs-ve-indirimler/', $block);
    }

    public function test_knowledge_base_is_empty_when_nothing_matches(): void
    {
        $this->assertSame([], app(KnowledgeBase::class)->relevant('quantum chromodynamics'));
        $this->assertSame('', app(KnowledgeBase::class)->contextBlock('quantum chromodynamics'));
    }

    public function test_html_extractor_decodes_entities_and_strips_tags(): void
    {
        $text = HtmlTextExtractor::extract('<p>ARUCAD &amp; tasarım</p><p>ikinci</p>');
        $this->assertStringContainsString('ARUCAD & tasarım', $text);
        $this->assertStringNotContainsString('<p>', $text);
    }

    public function test_a_404_marks_a_stored_page_stale_not_deleted(): void
    {
        KnowledgeDocument::create([
            'id' => KnowledgeDocument::idForUrl('https://arucad.edu.tr/gone/'),
            'url' => 'https://arucad.edu.tr/gone/', 'domain' => 'arucad.edu.tr',
            'title' => 'Eski', 'content' => str_repeat('içerik ', 30),
            'content_hash' => 'z', 'content_length' => 200, 'fetched_at' => now()->subDay(),
        ]);
        config(['knowledge.seed_urls' => ['https://arucad.edu.tr/gone/']]);
        Http::fake(['arucad.edu.tr/gone/' => Http::response('gone', 404, ['Content-Type' => 'text/html'])]);

        app(SiteKnowledgeCrawler::class)->crawl(force: true);

        $doc = KnowledgeDocument::first();
        $this->assertTrue((bool) $doc->is_stale);           // flagged, not deleted
        $this->assertStringContainsString('404', (string) $doc->last_error);
        $this->assertSame(1, (int) $doc->fail_count);
    }

    public function test_a_transient_failure_increments_fail_count(): void
    {
        KnowledgeDocument::create([
            'id' => KnowledgeDocument::idForUrl('https://arucad.edu.tr/flaky/'),
            'url' => 'https://arucad.edu.tr/flaky/', 'domain' => 'arucad.edu.tr',
            'title' => 'Flaky', 'content' => str_repeat('içerik ', 30),
            'content_hash' => 'z', 'content_length' => 200, 'fetched_at' => now()->subDay(),
        ]);
        config(['knowledge.seed_urls' => ['https://arucad.edu.tr/flaky/']]);
        Http::fake(['arucad.edu.tr/flaky/' => Http::response('err', 500, ['Content-Type' => 'text/html'])]);

        app(SiteKnowledgeCrawler::class)->crawl(force: true);

        $doc = KnowledgeDocument::first();
        $this->assertSame(1, (int) $doc->fail_count);
        $this->assertFalse((bool) $doc->is_stale);          // a 500 is transient, not deleted
    }

    public function test_sitemap_discovery_enqueues_allowed_urls(): void
    {
        config([
            'knowledge.use_sitemaps' => true,
            'knowledge.discover_links' => false,
            'knowledge.seed_urls' => ['https://arucad.edu.tr/'],
        ]);
        Http::fake([
            'arucad.edu.tr/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://arucad.edu.tr/from-sitemap/</loc></url>'
                .'<url><loc>https://evil.example.com/x</loc></url>'  // foreign, must be ignored
                .'</urlset>',
                200, ['Content-Type' => 'application/xml'],
            ),
            'arucad.edu.tr/' => Http::response(
                '<html><title>Home</title><body><p>'.str_repeat('içerik ', 40).'</p></body></html>',
                200, ['Content-Type' => 'text/html'],
            ),
            'arucad.edu.tr/from-sitemap/' => Http::response(
                '<html><title>Sitemap Page</title><body><p>'.str_repeat('bölüm ', 40).'</p></body></html>',
                200, ['Content-Type' => 'text/html'],
            ),
        ]);

        app(SiteKnowledgeCrawler::class)->crawl(force: true);

        $this->assertTrue(KnowledgeDocument::where('url', 'https://arucad.edu.tr/from-sitemap/')->exists());
    }

    public function test_url_safety_blocks_private_and_loopback_targets(): void
    {
        $this->assertFalse(UrlSafety::isPublicHost('localhost'));
        $this->assertFalse(UrlSafety::isPublicIp('127.0.0.1'));
        $this->assertFalse(UrlSafety::isPublicIp('10.0.0.5'));
        $this->assertFalse(UrlSafety::isPublicIp('192.168.1.1'));
        $this->assertFalse(UrlSafety::isPublicIp('169.254.1.1'));
        $this->assertTrue(UrlSafety::isPublicIp('8.8.8.8'));
    }
}
