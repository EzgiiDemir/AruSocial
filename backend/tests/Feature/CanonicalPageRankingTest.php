<?php

namespace Tests\Feature;

use App\Models\KnowledgeDocument;
use App\Services\Knowledge\KnowledgeBase;
use App\Services\Knowledge\SiteKnowledgeCrawler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Does a question find the page ABOUT its subject, or one that mentions it?
 *
 * All three behaviours here were measured failing on the live 1,190-page
 * corpus after it grew from 380, most of the new pages news posts. Embeddings
 * are left off throughout: these are properties of the keyword half, and it is
 * also the half that carries the whole ranking whenever the classifier is
 * down.
 */
class CanonicalPageRankingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['knowledge.embeddings.enabled' => false]);
        Cache::flush();
    }

    private function page(string $path, string $title, string $content, string $language = 'tr'): KnowledgeDocument
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
            'language' => $language,
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
            fn (array $hit) => (string) $hit['title'],
            app(KnowledgeBase::class)->relevant($query, $limit),
        );
    }

    // ------------------------------------------------- aboutness over volume

    /**
     * The measured failure, reproduced.
     *
     * Live, "library opening hours" returned two unrelated news posts at #1
     * and #2 and the Library page not at all. An article repeats the subject
     * while covering an occasion, so it can out-score the page whose subject
     * it is on term frequency alone — and once the corpus holds hundreds of
     * articles, several of them do.
     *
     * The fixture is built so the articles genuinely win on keywords: with
     * the penalty off the canonical page is not in the top three, which is
     * what makes this a test of the penalty and not of the fixture.
     */
    public function test_the_page_named_for_the_subject_beats_articles_mentioning_it(): void
    {
        $this->seedLibraryAndArticles();

        $withPenalty = $this->titles('kütüphane çalışma saatleri');

        config(['knowledge.scoring.article_penalty_cap' => 0]);
        $without = $this->titles('kütüphane çalışma saatleri');

        $this->assertSame('Kütüphane', $withPenalty[0]);
        $this->assertNotContains('Kütüphane', $without);
    }

    /**
     * A news question is scored as though the penalty did not exist.
     *
     * The escape hatch, pinned by toggling the penalty rather than by
     * asserting an ordering: what must hold is that a question asking for
     * news is not subject to the demotion at all.
     */
    public function test_a_news_question_is_ranked_as_if_there_were_no_penalty(): void
    {
        $this->seedLibraryAndArticles();

        $withPenalty = $this->titles('kütüphane ile ilgili son haberler');

        config(['knowledge.scoring.article_penalty_cap' => 0]);
        $without = $this->titles('kütüphane ile ilgili son haberler');

        $this->assertSame($without, $withPenalty);
    }

    /**
     * A short canonical page and several articles that beat it on keywords.
     * Both carry the subject in title and slug; only the slug length differs.
     */
    private function seedLibraryAndArticles(): void
    {
        $this->page(
            '/kutuphane/',
            'Kütüphane',
            'ARUCAD Kütüphane hizmetleri. Kütüphane çalışma saatleri hafta içi 08:30-17:30.',
        );

        foreach (range(1, 4) as $n) {
            $this->page(
                "/arucad-kutuphanesinde-yeni-kitap-bagisi-toplantisi-duzenlendi-ve-ogrenciler-katildi-{$n}/",
                "ARUCAD Kütüphanesinde Yeni Kitap Bağışı Toplantısı Düzenlendi Ve Öğrenciler Katıldı {$n}",
                'ARUCAD kütüphane binasında toplantı yapıldı. Kütüphane bağış kabul etti. '
                    .'Kütüphane çalışma saatleri boyunca açıktı. Çalışma saatleri uzatıldı.',
            );
        }
    }

    // ------------------------------------------------- rare words decide

    /**
     * The measured failure: "architecture programme" returned a double-major
     * regulation, a directive on programmes and an acting-department news
     * post, and never the architecture programme page.
     *
     * "program" is in 47% of the real corpus and "architecture" in 7%, but
     * every term counted the same — so the word that says nothing about which
     * page is wanted weighed as much as the word that says everything, and it
     * sat in the TITLE of a dozen regulations.
     */
    public function test_the_rare_word_in_a_query_decides_it(): void
    {
        // The page about the subject carries only the RARE word.
        $this->page('/bolumler/mimarlik/', 'Mimarlık', 'Mimarlık bölümü hakkında bilgi.');

        // The distractors carry only the COMMON one — but in the title, in
        // the slug and repeatedly in the body, which is everything the
        // scoring rewards. Without weighting by rarity they win.
        foreach (range(1, 9) as $n) {
            $this->page(
                "/program-yonetmeligi-{$n}/",
                "Program Yönetmeliği {$n}",
                // Each body distinct: nine identical ones would be stripped
                // as site chrome, which is correct behaviour and would empty
                // the fixture of the very thing it is testing.
                "Madde {$n}. Bu yönetmelik program açma ve program kapatma "
                    .'esaslarını düzenler. Program koşulları program başına '
                    ."belirlenir; {$n}. maddeye göre program değerlendirilir.",
            );
        }

        $this->assertSame('Mimarlık', $this->titles('mimarlık programı')[0]);

        // And the weighting is what does it: without it the regulations win,
        // which is the behaviour that was measured on the live corpus.
        config(['knowledge.scoring.rarity_weighting' => false]);
        $this->assertStringStartsWith('Program Yönetmeliği', $this->titles('mimarlık programı')[0]);
    }

    /** A deep path is not a long slug: only the page's own name is counted. */
    public function test_a_short_name_deep_in_a_hierarchy_is_not_penalised(): void
    {
        $deep = $this->page(
            '/arucad/kampus-yasami/saglik/merkezi/kutuphane/',
            'Kütüphane',
            'Kütüphane hizmetleri ve çalışma saatleri.',
        );

        $this->assertSame([$deep->title], $this->titles('kütüphane', 1));
    }

    /**
     * Interrogatives are not subjects.
     *
     * "how", "what", "where" and "when" were stop words and their siblings
     * were not, so "who is the rector" searched the corpus for "who" — which
     * matches inside "whole" and "whoever" — and "rektör kim" searched for
     * "kim". The stray term pulled up regulations that merely contain it:
     * measured, "who is the rector" returned a dormitory regulation, a
     * library regulation and an internship regulation, none of which mention
     * a rector in their title.
     */
    public function test_an_interrogative_is_not_searched_for(): void
    {
        $this->page('/arucad/yonetim/', 'Yönetim', 'Prof. Dr. Asım Vehbi, ARUCAD rektörüdür.');
        // Contains "kim" inside an ordinary word, and nothing about a rector.
        $this->page('/yonetmelik-kimya/', 'Kimya Laboratuvarı Yönetmeliği',
            'Kimya laboratuvarı kullanım esasları. Kimyasal madde saklama kuralları.');

        $titles = $this->titles('rektör kim', 2);

        $this->assertSame('Yönetim', $titles[0]);
        $this->assertNotContains('Kimya Laboratuvarı Yönetmeliği', $titles);
    }

    // ------------------------------------------------------- site chrome

    /**
     * KnowledgeIndexer strips the menu before embedding, so the semantic half
     * never saw one. The keyword half and the snippet read `content` straight
     * from the column, which is the raw extraction — measured putting 924
     * characters of menu in front of every page, and quoting it to the model
     * as grounding whenever a page won on keywords alone.
     */
    public function test_the_snippet_given_to_the_model_is_not_the_site_menu(): void
    {
        $menu = implode("\n", [
            'Hakkımızda', 'Yönetim', 'Fakülteler', 'Kütüphane', 'İletişim',
        ]);

        // The same menu on enough pages for it to be recognised as chrome.
        foreach (range(1, 8) as $n) {
            $this->page("/sayfa-{$n}/", "Sayfa {$n}", $menu."\n\nSayfa {$n} içeriği.");
        }
        $this->page('/arucad/yonetim/', 'Yönetim', $menu."\n\nProf. Dr. Asım Vehbi, Rektör.");

        $hits = app(KnowledgeBase::class)->relevant('yönetim', 1);

        $this->assertNotEmpty($hits);
        $this->assertStringContainsString('Asım Vehbi', $hits[0]['snippet']);
        $this->assertStringNotContainsString('Fakülteler', $hits[0]['snippet']);
    }

    // --------------------------------------------------------- language

    /**
     * Language was a URL substring test with no Russian branch at all, so all
     * 35 pages under /ru/ were stored as Turkish — including ones whose whole
     * text is Cyrillic. That cost them the language bonus AND kept their menu,
     * because chrome is counted within a language group.
     */
    public function test_a_russian_page_is_not_stored_as_turkish(): void
    {
        $crawler = app(SiteKnowledgeCrawler::class);

        $this->assertSame('ru', $crawler->languageFor(
            'https://prospective.arucad.edu.tr/ru/prozhivanie/',
            'ПРОЖИВАНИЕ — Университет Креативных Искусств',
        ));
        $this->assertSame('en', $crawler->languageFor(
            'https://arucad.edu.tr/en/library/',
            'Library',
        ));
        $this->assertSame('tr', $crawler->languageFor(
            'https://arucad.edu.tr/kutuphane/',
            'Kütüphane',
        ));
    }

    /** A Russian page that does not say so in its URL is read from its text. */
    public function test_language_falls_back_to_the_page_text(): void
    {
        $this->assertSame('ru', app(SiteKnowledgeCrawler::class)->languageFor(
            'https://prospective.arucad.edu.tr/2026-priem/',
            'Приём заявок на Осень 2026 уже открыт! Университет Креативных Искусств и Дизайна.',
        ));
    }

    /** Relabelling corrects rows already stored, without re-fetching them. */
    public function test_relabelling_fixes_documents_already_indexed(): void
    {
        $page = $this->page('/ru/pochemu-arucad/', 'Почему ARUCAD', 'Чтобы получить образование.');
        $this->assertSame('tr', $page->fresh()->language);

        $changes = app(SiteKnowledgeCrawler::class)->relabelLanguages();

        $this->assertSame('ru', $page->fresh()->language);
        $this->assertSame(['tr->ru' => 1], $changes);
    }
}
