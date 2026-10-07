<?php

namespace Tests\Feature;

use App\Filament\Resources\CrawlSources\CrawlSourceResource;
use App\Models\CrawlSource;
use App\Services\Knowledge\SiteKnowledgeCrawler;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Admin-managed crawl sources drive the AICAD crawler's allow-list and seeds,
 * with a local/global access flag. Config is only a fallback when the table
 * is empty.
 */
class CrawlSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'knowledge.enabled' => true,
            'knowledge.verify_ssl' => false,
            'knowledge.verify_public_ip' => false,
            'knowledge.use_sitemaps' => false,
            'knowledge.discover_links' => false,
            'knowledge.delay_ms' => 0,
            'knowledge.crawl_local' => true,
        ]);
    }

    public function test_enabled_sources_become_the_allow_list_and_seeds(): void
    {
        CrawlSource::create(['domain' => 'arucad.edu.tr', 'access' => 'global', 'enabled' => true]);
        CrawlSource::create(['domain' => 'quality.arucad.edu.tr', 'access' => 'global', 'enabled' => true]);
        CrawlSource::create(['domain' => 'off.arucad.edu.tr', 'access' => 'global', 'enabled' => false]);

        $crawler = app(SiteKnowledgeCrawler::class);
        $domains = $crawler->allowedDomains();

        $this->assertContains('arucad.edu.tr', $domains);
        $this->assertContains('quality.arucad.edu.tr', $domains);
        $this->assertNotContains('off.arucad.edu.tr', $domains);   // disabled excluded

        $this->assertContains('https://arucad.edu.tr/', $crawler->seedUrls());
        $this->assertContains('https://quality.arucad.edu.tr/', $crawler->seedUrls());
    }

    public function test_local_sources_are_skipped_when_crawl_local_is_off(): void
    {
        config(['knowledge.crawl_local' => false]);
        CrawlSource::create(['domain' => 'arucad.edu.tr', 'access' => 'global', 'enabled' => true]);
        CrawlSource::create(['domain' => 'qualityhub.arucad.edu.tr', 'access' => 'local', 'enabled' => true]);

        $domains = app(SiteKnowledgeCrawler::class)->allowedDomains();

        $this->assertContains('arucad.edu.tr', $domains);
        $this->assertNotContains('qualityhub.arucad.edu.tr', $domains);   // local skipped off-network
    }

    public function test_local_sources_are_included_when_on_network(): void
    {
        config(['knowledge.crawl_local' => true]);
        CrawlSource::create(['domain' => 'qualityhub.arucad.edu.tr', 'access' => 'local', 'enabled' => true]);

        $this->assertContains(
            'qualityhub.arucad.edu.tr',
            app(SiteKnowledgeCrawler::class)->allowedDomains(),
        );
    }

    public function test_a_source_not_in_the_list_is_never_fetched(): void
    {
        CrawlSource::create(['domain' => 'arucad.edu.tr', 'access' => 'global', 'enabled' => true]);
        $crawler = app(SiteKnowledgeCrawler::class);

        $this->assertTrue($crawler->isAllowed('https://arucad.edu.tr/fakulteler/'));
        $this->assertFalse($crawler->isAllowed('https://evil.example.com/'));
        // A disabled/absent ARUCAD subdomain is also refused.
        $this->assertFalse($crawler->isAllowed('https://sis.arucad.edu.tr/'));
    }

    public function test_empty_table_falls_back_to_config(): void
    {
        config(['knowledge.allowed_domains' => ['configonly.arucad.edu.tr']]);
        // No CrawlSource rows.
        $this->assertSame(
            ['configonly.arucad.edu.tr'],
            app(SiteKnowledgeCrawler::class)->allowedDomains(),
        );
    }

    // --- admin panel gating ---

    public function test_only_integration_managers_can_manage_sources(): void
    {
        Filament::setCurrentPanel('admin');

        $this->actingAsRole('student');
        $this->assertFalse(CrawlSourceResource::canViewAny());

        $this->actingAsRole('superAdmin');
        $this->assertTrue(CrawlSourceResource::canViewAny());
        $this->assertTrue(CrawlSourceResource::canCreate());
    }
}
