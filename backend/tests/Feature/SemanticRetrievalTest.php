<?php

namespace Tests\Feature;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Knowledge\BoilerplateFilter;
use App\Services\Knowledge\EmbeddingClient;
use App\Services\Knowledge\KnowledgeBase;
use App\Services\Knowledge\KnowledgeIndexer;
use App\Support\TextFold;
use App\Support\Vector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Retrieval: how a question finds the page that answers it.
 *
 * The embeddings come from the moderation classifier, so every test here
 * fakes that HTTP call rather than requiring the service — and several
 * of them exist precisely to pin what happens when it is NOT there.
 */
class SemanticRetrievalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'knowledge.embeddings.enabled' => true,
            'knowledge.embeddings.base_url' => 'http://classifier.test',
            'knowledge.embeddings.query_cache_minutes' => 0,
        ]);
        Cache::flush();
    }

    /** A unit vector pointing mostly along one axis, for predictable cosines. */
    private function vector(int $axis, int $dimensions = 8): array
    {
        $v = array_fill(0, $dimensions, 0.0);
        $v[$axis % $dimensions] = 1.0;

        return $v;
    }

    private function document(string $id, string $title, string $content, string $language = 'tr'): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'id' => $id,
            'url' => 'https://arucad.edu.tr/'.$id,
            'domain' => 'arucad.edu.tr',
            'title' => $title,
            'content' => $content,
            'content_hash' => sha1($content),
            'content_length' => mb_strlen($content),
            'http_status' => 200,
            'language' => $language,
            'fetched_at' => now(),
            'is_stale' => false,
            'last_seen_at' => now(),
        ]);
    }

    private function chunk(KnowledgeDocument $document, string $text, array $vector): void
    {
        KnowledgeChunk::create([
            'knowledge_document_id' => $document->id,
            'position' => 0,
            'text' => $text,
            'embedding' => Vector::pack($vector),
            'model' => 'test',
        ]);
    }

    // ---------------------------------------------------------- the point

    /**
     * The reason semantic retrieval was added: a question that shares no
     * word with the page that answers it.
     */
    public function test_a_page_is_found_with_no_shared_keyword(): void
    {
        $doc = $this->document('kayit', 'Kayıt ve Başvuru', 'Öğrenci kaydı adımları.');
        $this->chunk($doc, 'Öğrenci kaydı adımları.', $this->vector(0));
        $other = $this->document('spor', 'Spor Tesisleri', 'Basketbol sahası ve fitness salonu.');
        $this->chunk($other, 'Basketbol sahası.', $this->vector(3));

        Http::fake(['classifier.test/*' => Http::response([
            'success' => true, 'vectors' => [$this->vector(0)],
        ])]);

        $hits = app(KnowledgeBase::class)->relevant('enrolment steps', 2);

        $this->assertNotEmpty($hits);
        $this->assertSame('Kayıt ve Başvuru', $hits[0]['title']);
    }

    /** The matched passage is what the model is given, not a keyword window. */
    public function test_the_matching_passage_becomes_the_snippet(): void
    {
        $doc = $this->document('burs', 'Burslar', "Giriş paragrafı.\n\nBurs oranı yüzde elli.");
        $this->chunk($doc, 'Burs oranı yüzde elli.', $this->vector(0));

        Http::fake(['classifier.test/*' => Http::response([
            'success' => true, 'vectors' => [$this->vector(0)],
        ])]);

        $hits = app(KnowledgeBase::class)->relevant('scholarship rate', 1);

        $this->assertStringContainsString('yüzde elli', $hits[0]['snippet']);
    }

    // ------------------------------------------------- degradation on fail

    /**
     * The classifier being down must cost ranking quality and nothing
     * else. An assistant that returns nothing because a ranking signal
     * is missing is a bigger outage than the one it is reacting to.
     */
    public function test_keyword_retrieval_still_works_when_the_classifier_is_down(): void
    {
        $doc = $this->document('kutuphane', 'Kütüphane', 'Kütüphane A blokta yer alır.');
        $this->chunk($doc, 'Kütüphane A blokta.', $this->vector(0));

        Http::fake(['classifier.test/*' => Http::response(['error' => 'down'], 503)]);

        $hits = app(KnowledgeBase::class)->relevant('kütüphane nerede', 2);

        $this->assertNotEmpty($hits, 'A down classifier must not empty the results.');
        $this->assertSame('Kütüphane', $hits[0]['title']);
    }

    public function test_a_connection_error_does_not_raise(): void
    {
        $doc = $this->document('yemek', 'Yemekhane', 'Yemekhane 08:00 - 19:00 açıktır.');
        $this->chunk($doc, 'Yemekhane açık.', $this->vector(0));

        Http::fake(fn () => throw new \RuntimeException('connection refused'));

        $hits = app(KnowledgeBase::class)->relevant('yemekhane saatleri', 2);

        $this->assertSame('Yemekhane', $hits[0]['title']);
    }

    /**
     * A short batch cannot be matched back to its inputs, and guessing
     * the alignment would attach one page's meaning to another.
     */
    public function test_a_mismatched_batch_is_refused_rather_than_misaligned(): void
    {
        Http::fake(['classifier.test/*' => Http::response([
            'success' => true, 'vectors' => [$this->vector(0)],
        ])]);

        $this->assertNull(app(EmbeddingClient::class)->embed(['one', 'two']));
    }

    public function test_a_failure_is_remembered_so_the_next_question_is_not_slow(): void
    {
        Http::fake(['classifier.test/*' => Http::response([], 500)]);
        $client = app(EmbeddingClient::class);

        $this->assertNull($client->embedOne('ilk soru'));
        $this->assertNull($client->embedOne('ikinci soru'));

        // The second question must not have gone to the wire at all.
        Http::assertSentCount(1);
    }

    /**
     * ...but an indexing run must not be stopped by it. One bad page
     * used to skip every page after it, because the cooldown armed by
     * the first failure suppressed the rest of the loop.
     */
    public function test_indexing_is_not_blocked_by_an_earlier_failure(): void
    {
        $responses = [
            Http::response([], 500),
            Http::response(['success' => true, 'vectors' => [$this->vector(1)]]),
        ];
        Http::fake(['classifier.test/*' => Http::sequence()->pushResponse($responses[0])->pushResponse($responses[1])]);

        $client = app(EmbeddingClient::class);
        $this->assertNull($client->embed(['first page'], interactive: false));
        $this->assertNotNull($client->embed(['second page'], interactive: false),
            'A failure while indexing must not suppress the rest of the run.');
    }

    // ------------------------------------------------------------ chunking

    /** Long pages are split, or everything past the intro is unsearchable. */
    public function test_a_long_page_is_split_into_several_passages(): void
    {
        $paragraphs = [];
        for ($i = 0; $i < 6; $i++) {
            $paragraphs[] = str_repeat("Paragraf {$i} içerik cümlesi. ", 20);
        }
        $doc = $this->document('uzun', 'Uzun Sayfa', implode("\n\n", $paragraphs));

        $texts = app(KnowledgeIndexer::class)->chunkTexts($doc);

        $this->assertGreaterThan(1, count($texts));
        foreach ($texts as $text) {
            $this->assertStringStartsWith('Uzun Sayfa', $text,
                'Every passage carries its page title, or it cannot say what it is about.');
        }
    }

    /**
     * Splitting an over-long sentence must not cut a character in half.
     * Byte-splitting produced text Postgres refused outright.
     */
    public function test_splitting_never_breaks_a_multibyte_character(): void
    {
        config(['knowledge.embeddings.chunk_chars' => 200]);
        $doc = $this->document('turkce', 'Türkçe', str_repeat('çğıöşüÇĞİÖŞÜ', 120));

        foreach (app(KnowledgeIndexer::class)->chunkTexts($doc) as $text) {
            $this->assertTrue(mb_check_encoding($text, 'UTF-8'),
                'A passage was cut mid-character.');
        }
    }

    public function test_invalid_utf8_from_a_crawled_page_is_cleaned(): void
    {
        $doc = $this->document('bozuk', 'Bozuk Sayfa', "Geçerli metin \xC3\x28 devam ediyor.");

        foreach (app(KnowledgeIndexer::class)->chunkTexts($doc) as $text) {
            $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        }
    }

    // --------------------------------------------------------- boilerplate

    /**
     * Site chrome on every page makes every page's embedding look the
     * same. Measured: it made "kütüphane" return an exam page.
     */
    public function test_navigation_repeated_across_pages_is_stripped(): void
    {
        $menu = "Hakkımızda\nFakülteler\nİletişim";
        for ($i = 0; $i < 10; $i++) {
            $this->document("sayfa-{$i}", "Sayfa {$i}", $menu."\n\nSayfaya özgü içerik {$i}.");
        }
        app(BoilerplateFilter::class)->forget();

        $stripped = app(BoilerplateFilter::class)->strip($menu."\n\nSayfaya özgü içerik 3.", 'tr');

        $this->assertStringNotContainsString('Fakülteler', $stripped);
        $this->assertStringContainsString('Sayfaya özgü içerik 3.', $stripped);
    }

    /** A line on one page is content, however short. */
    public function test_a_line_that_appears_once_is_never_stripped(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->document("p-{$i}", "P{$i}", "Ortak satır\n\nBenzersiz cümle {$i}.");
        }
        app(BoilerplateFilter::class)->forget();

        $stripped = app(BoilerplateFilter::class)->strip("Ortak satır\n\nBenzersiz cümle 4.", 'tr');

        $this->assertStringContainsString('Benzersiz cümle 4.', $stripped);
    }

    // ------------------------------------------------------------- folding

    public function test_a_query_without_turkish_diacritics_still_matches(): void
    {
        $this->document('kut', 'Kütüphane', 'Kütüphane çalışma saatleri ve kuralları.');

        Http::fake(['classifier.test/*' => Http::response([], 503)]);

        $hits = app(KnowledgeBase::class)->relevant('kutuphane calisma saatleri', 2);

        $this->assertNotEmpty($hits);
        $this->assertSame('Kütüphane', $hits[0]['title']);
    }

    public function test_folding_is_only_for_matching(): void
    {
        $this->assertSame('kutuphane ogrenci basvuru', TextFold::fold('Kütüphane Öğrenci Başvuru'));
    }

    /**
     * The measured gap this closes: the sentence model scored
     * "What scholarships are available?" at 0.115 against the Turkish
     * title "Burslar ve Ücretler", below three unrelated pages.
     */
    public function test_an_english_question_reaches_a_turkish_page(): void
    {
        $this->document('burslar', 'Burslar ve Ücretler', 'Burs oranları ve ücret bilgileri.');
        $this->document('spor2', 'Spor', 'Basketbol ve voleybol sahaları.');

        Http::fake(['classifier.test/*' => Http::response([], 503)]);

        $hits = app(KnowledgeBase::class)->relevant('What scholarships are available?', 2);

        $this->assertNotEmpty($hits);
        $this->assertSame('Burslar ve Ücretler', $hits[0]['title']);
    }

    public function test_a_russian_question_reaches_a_turkish_page(): void
    {
        $this->document('kutuphane2', 'Kütüphane', 'Kütüphane hizmetleri.');
        $this->document('yemek2', 'Yemekhane', 'Yemek saatleri.');

        Http::fake(['classifier.test/*' => Http::response([], 503)]);

        $hits = app(KnowledgeBase::class)->relevant('Где библиотека?', 2);

        $this->assertNotEmpty($hits);
        $this->assertSame('Kütüphane', $hits[0]['title']);
    }

    // -------------------------------------------------------------- safety

    /**
     * Ranking must not be swamped by repetition: a long page saying a
     * common word thirty times used to beat the page about the subject.
     */
    public function test_repeating_a_word_does_not_win_on_volume(): void
    {
        $this->document('spam', 'Genel Sayfa', str_repeat('öğrenci ', 200));
        $this->document('hedef', 'Öğrenci Kayıt İşlemleri', 'Öğrenci kayıt adımları burada.');

        Http::fake(['classifier.test/*' => Http::response([], 503)]);

        $hits = app(KnowledgeBase::class)->relevant('öğrenci kayıt', 2);

        $this->assertSame('Öğrenci Kayıt İşlemleri', $hits[0]['title']);
    }

    /** A question of nothing but stop words must not return the corpus. */
    public function test_a_contentless_question_returns_nothing(): void
    {
        $this->document('bir', 'Bir Sayfa', 'İçerik.');

        Http::fake(['classifier.test/*' => Http::response([], 503)]);

        $this->assertSame([], app(KnowledgeBase::class)->relevant('ne var ne yok', 3));
    }

    /** Disabled means no call at all, not a failed one. */
    public function test_disabling_embeddings_makes_no_request(): void
    {
        config(['knowledge.embeddings.enabled' => false]);
        $this->document('x', 'X', 'İçerik.');
        Http::fake();

        app(KnowledgeBase::class)->relevant('içerik', 1);

        Http::assertNothingSent();
    }
}
