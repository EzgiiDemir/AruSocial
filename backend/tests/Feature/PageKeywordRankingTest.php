<?php

namespace Tests\Feature;

use App\Models\CrawlSource;
use App\Models\KnowledgeDocument;
use App\Models\PageKeyword;
use App\Services\Knowledge\KnowledgeBase;
use App\Services\Knowledge\SiteKnowledgeCrawler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Authored per-page keywords: what a page is FOR, as opposed to what its
 * text happens to contain.
 */
class PageKeywordRankingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['knowledge.embeddings.enabled' => false]);
        Cache::flush();
    }

    private function page(string $path, string $title, string $content): KnowledgeDocument
    {
        return KnowledgeDocument::create([
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

    /** @return list<string> */
    private function titles(string $query, int $limit = 3): array
    {
        return array_map(
            fn (array $h) => (string) $h['title'],
            app(KnowledgeBase::class)->relevant($query, $limit),
        );
    }

    /**
     * The case the keywords exist for.
     *
     * A ceremony report repeats "burs" and is not the scholarships page. No
     * amount of counting words in the text distinguishes them; a human
     * saying which page is about scholarships does.
     */
    public function test_an_authored_keyword_lifts_the_page_that_is_about_the_topic(): void
    {
        $canonical = $this->page('/burslar/', 'Burslar ve Ücretler', 'Burs oranları ve başvuru.');
        foreach (range(1, 6) as $n) {
            $this->page(
                "/burs-toreni-{$n}/",
                "Burs Töreni {$n} Gerçekleşti",
                "Burs töreni {$n} yapıldı. Burs alan öğrenciler burs belgelerini aldı; burs komitesi katıldı.",
            );
        }

        // Without keywords the ceremony reports win on sheer repetition.
        $this->assertNotSame('Burslar ve Ücretler', $this->titles('burs koşulları')[0]);

        PageKeyword::create([
            'url' => $canonical->url,
            'keywords' => 'burs, scholarship, burs koşulları, ücret',
        ]);
        Cache::flush();

        $this->assertSame('Burslar ve Ücretler', $this->titles('burs koşulları')[0]);
    }

    /**
     * A keyword on nearly every page must not be able to lift anything.
     *
     * "ARUCAD" is authored on all 852 pages of the real import. Weighting the
     * bonus by the same rarity scale as the body terms is what stops it
     * reordering the entire corpus.
     */
    public function test_a_keyword_present_everywhere_does_not_decide_the_ranking(): void
    {
        $target = $this->page('/kutuphane/', 'Kütüphane', 'Kütüphane hizmetleri.');
        foreach (range(1, 8) as $n) {
            $other = $this->page("/sayfa-{$n}/", "Sayfa {$n}", "Sayfa {$n} içeriği.");
            PageKeyword::create(['url' => $other->url, 'keywords' => 'arucad, universite']);
        }
        PageKeyword::create(['url' => $target->url, 'keywords' => 'arucad, universite']);
        Cache::flush();

        // "arucad" is on every page, so it cannot pick one out.
        $this->assertSame('Kütüphane', $this->titles('kütüphane', 1)[0]);
    }

    /** Keywords for a page that has not been crawled are kept, not dropped. */
    public function test_keywords_survive_a_page_that_is_not_indexed_yet(): void
    {
        PageKeyword::create([
            'url' => 'https://arucad.edu.tr/arucad/yonetim/rektor/',
            'keywords' => 'rektör, rector, yönetim',
        ]);

        $this->assertArrayHasKey(
            'https://arucad.edu.tr/arucad/yonetim/rektor/',
            PageKeyword::index(),
        );
    }

    /**
     * A page worth curating is a page worth fetching.
     *
     * Measured: the rector's page had the right keywords authored against it
     * and was never crawled, so the curation changed nothing.
     */
    public function test_a_curated_url_becomes_a_crawl_seed(): void
    {
        // The allow-list decides what may be fetched at all; without a
        // source the crawler has no domains and seeding cannot apply.
        CrawlSource::create([
            'domain' => 'arucad.edu.tr',
            'access' => CrawlSource::ACCESS_GLOBAL,
            'enabled' => true,
        ]);

        PageKeyword::create([
            'url' => 'https://arucad.edu.tr/arucad/yonetim/rektor/',
            'keywords' => 'rektör, rector',
        ]);
        // A curated page on a domain we do not crawl must not be fetched.
        PageKeyword::create([
            'url' => 'https://example.com/whatever/',
            'keywords' => 'test',
        ]);
        Cache::flush();

        $seeds = app(SiteKnowledgeCrawler::class)->seedUrls();

        $this->assertContains('https://arucad.edu.tr/arucad/yonetim/rektor/', $seeds);
        $this->assertNotContains('https://example.com/whatever/', $seeds);
    }

    // ------------------------------------------- on-demand refresh guards

    /**
     * A question about a curated page re-reads that page before answering.
     *
     * This is the difference between today's exam timetable and whatever the
     * last scheduled crawl captured.
     */
    public function test_a_rare_keyword_refreshes_its_page(): void
    {
        config(['knowledge.on_demand_enabled' => true]);
        PageKeyword::create([
            'url' => 'https://arucad.edu.tr/erasmus/',
            'keywords' => 'erasmus, degisim programi',
        ]);
        Cache::flush();

        $crawler = \Mockery::mock(SiteKnowledgeCrawler::class);
        $crawler->shouldReceive('refreshUrl')->once()
            ->with('https://arucad.edu.tr/erasmus/')->andReturnTrue();
        $this->app->instance(SiteKnowledgeCrawler::class, $crawler);

        app(KnowledgeBase::class)->refreshFor('erasmus başvurusu nasıl yapılır');
    }

    /**
     * A keyword authored on many pages identifies none of them, so it must
     * not cause a fetch. "arucad" is on all 852 pages of the real import.
     */
    public function test_a_vague_keyword_refreshes_nothing(): void
    {
        config(['knowledge.on_demand_enabled' => true, 'knowledge.on_demand_max_spread' => 3]);
        foreach (range(1, 8) as $n) {
            PageKeyword::create([
                'url' => "https://arucad.edu.tr/sayfa-{$n}/",
                'keywords' => 'arucad, universite',
            ]);
        }
        Cache::flush();

        $crawler = \Mockery::mock(SiteKnowledgeCrawler::class);
        $crawler->shouldNotReceive('refreshUrl');
        $this->app->instance(SiteKnowledgeCrawler::class, $crawler);

        app(KnowledgeBase::class)->refreshFor('arucad universite hakkinda');
    }

    /** One question cannot fan out into an unbounded number of fetches. */
    public function test_the_number_of_refreshes_per_question_is_capped(): void
    {
        config([
            'knowledge.on_demand_enabled' => true,
            'knowledge.on_demand_max_pages' => 2,
            'knowledge.on_demand_max_spread' => 20,
        ]);
        foreach (range(1, 9) as $n) {
            PageKeyword::create([
                'url' => "https://arucad.edu.tr/burs-{$n}/",
                'keywords' => 'burs',
            ]);
        }
        Cache::flush();

        $crawler = \Mockery::mock(SiteKnowledgeCrawler::class);
        $crawler->shouldReceive('refreshUrl')->twice()->andReturnTrue();
        $this->app->instance(SiteKnowledgeCrawler::class, $crawler);

        app(KnowledgeBase::class)->refreshFor('burs');
    }

    /** Disabled by configuration means no outbound request at all. */
    public function test_nothing_is_fetched_when_on_demand_is_off(): void
    {
        config(['knowledge.on_demand_enabled' => false]);
        PageKeyword::create([
            'url' => 'https://arucad.edu.tr/erasmus/',
            'keywords' => 'erasmus',
        ]);
        Cache::flush();

        $crawler = \Mockery::mock(SiteKnowledgeCrawler::class);
        $crawler->shouldNotReceive('refreshUrl');
        $this->app->instance(SiteKnowledgeCrawler::class, $crawler);

        app(KnowledgeBase::class)->refreshFor('erasmus');
    }

    /** Short noise is not a keyword: a two-letter term would match anything. */
    public function test_very_short_terms_are_discarded(): void
    {
        $this->assertSame(
            ['burs', 'ucret'],
            PageKeyword::split('burs, a, x, ücret, ,'),
        );
    }
}
