<?php

namespace Tests\Feature;

use App\Models\CrawlSource;
use App\Models\KnowledgeDocument;
use App\Services\Knowledge\KnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Operator-supplied routing keys on a crawl source.
 *
 * The keys exist so somebody who answers student questions can tell retrieval
 * which source owns a topic, without a deploy and without the ranker having to
 * rediscover the association from page text on every question.
 *
 * The two properties that matter are opposite in spirit and both are asserted
 * here: a key must actually lift its source, and a MISSING key must never cost
 * anything. A hint that can silently hide part of the corpus is worse than no
 * hint at all.
 */
class CrawlSourceKeysTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['knowledge.embeddings.enabled' => false]);
    }

    private function page(string $domain, string $title, string $body): void
    {
        KnowledgeDocument::create([
            'id' => KnowledgeDocument::idForUrl("https://{$domain}/".md5($title)),
            'url' => "https://{$domain}/".md5($title),
            'domain' => $domain,
            'title' => $title,
            'content' => $body,
            'content_hash' => md5($title),
            'content_length' => mb_strlen($body),
            'fetched_at' => now(),
            'document_status' => 'indexed',
            'language' => 'tr',
        ]);
    }

    public function test_keys_are_parsed_folded_and_deduplicated(): void
    {
        $source = CrawlSource::create([
            'domain' => 'aday.arucad.edu.tr',
            'keys' => 'Burs, ücret ,, burs;  BAŞVURU '."\n".'kayit',
            'access' => CrawlSource::ACCESS_GLOBAL,
            'enabled' => true,
        ]);

        // Folded ("ücret" -> "ucret"), trimmed, de-duplicated, split on
        // commas, semicolons and newlines alike.
        $this->assertSame(['burs', 'ucret', 'basvuru', 'kayit'], $source->keyTerms());
    }

    public function test_a_source_with_no_keys_contributes_nothing(): void
    {
        CrawlSource::create([
            'domain' => 'arucad.edu.tr', 'keys' => null,
            'access' => CrawlSource::ACCESS_GLOBAL, 'enabled' => true,
        ]);

        $this->assertSame([], CrawlSource::keyIndex());
    }

    public function test_a_disabled_source_is_not_consulted(): void
    {
        CrawlSource::create([
            'domain' => 'aday.arucad.edu.tr', 'keys' => 'burs',
            'access' => CrawlSource::ACCESS_GLOBAL, 'enabled' => false,
        ]);

        $this->assertArrayNotHasKey('aday.arucad.edu.tr', CrawlSource::keyIndex());
    }

    /**
     * The point of the feature: a keyed source wins a close call.
     *
     * Both pages mention the topic; only one belongs to the source an operator
     * has said owns it.
     */
    public function test_a_matching_key_lifts_its_source(): void
    {
        $this->page('other.arucad.edu.tr', 'Genel sayfa', 'Burs hakkında genel bir cümle.');
        $this->page('aday.arucad.edu.tr', 'Aday sayfası', 'Burs hakkında genel bir cümle.');

        $withoutKeys = app(KnowledgeBase::class)->relevant('burs', 2);
        $this->assertNotEmpty($withoutKeys);

        CrawlSource::create([
            'domain' => 'aday.arucad.edu.tr', 'keys' => 'burs',
            'access' => CrawlSource::ACCESS_GLOBAL, 'enabled' => true,
        ]);
        Cache::forget('crawl_sources:keys');

        $withKeys = app(KnowledgeBase::class)->relevant('burs', 2);

        $this->assertStringContainsString('aday.arucad.edu.tr', $withKeys[0]['url'],
            'The keyed source did not win a tie it was configured to win.');
    }

    /**
     * And the guarantee that makes the feature safe to use: a source nobody
     * has keyed is still fully reachable. A forgotten keyword must not be able
     * to hide part of the corpus.
     */
    public function test_an_unkeyed_source_is_still_retrievable(): void
    {
        $this->page('unkeyed.arucad.edu.tr', 'Kütüphane', 'Kütüphane çalışma saatleri ve kaynaklar.');
        CrawlSource::create([
            'domain' => 'aday.arucad.edu.tr', 'keys' => 'burs, ucret',
            'access' => CrawlSource::ACCESS_GLOBAL, 'enabled' => true,
        ]);

        $hits = app(KnowledgeBase::class)->relevant('kütüphane', 3);

        $this->assertNotEmpty($hits, 'An unkeyed source became unreachable.');
        $this->assertStringContainsString('unkeyed.arucad.edu.tr', $hits[0]['url']);
    }

    /** A key matches a whole word, never a fragment of a longer one. */
    public function test_keys_match_whole_words_only(): void
    {
        CrawlSource::create([
            'domain' => 'it.arucad.edu.tr', 'keys' => 'it, ders',
            'access' => CrawlSource::ACCESS_GLOBAL, 'enabled' => true,
        ]);

        $kb = app(KnowledgeBase::class);
        $match = new \ReflectionMethod($kb, 'sourcesMatchingKeys');

        // "dersane" contains "ders"; "tuition" contains "it".
        $this->assertSame([], $match->invoke($kb, 'dersane nerede'));
        $this->assertSame([], $match->invoke($kb, 'what is the tuition'));
        // The real words still match.
        $this->assertArrayHasKey('it.arucad.edu.tr', $match->invoke($kb, 'ders programi'));
    }

    /**
     * A key written in its dictionary form still matches the inflected word a
     * student actually types.
     *
     * Operators write "program", "sınav", "экзамен". Turkish appends its
     * endings and Russian replaces the final vowel, and the first version of
     * this matched whole words only — so "mimarlık programı hakkında" matched
     * nothing despite `program` being a key, and "мои экзамены" missed
     * `экзамен`. A routing hint that only fires on the uninflected form is a
     * hint that mostly does not fire.
     */
    public function test_a_key_matches_the_inflected_word_a_student_types(): void
    {
        CrawlSource::create([
            'domain' => 'aday.arucad.edu.tr',
            'keys' => 'program, sinav, экзамен, burs',
            'access' => CrawlSource::ACCESS_GLOBAL,
            'enabled' => true,
        ]);

        $kb = app(KnowledgeBase::class);
        $match = new \ReflectionMethod($kb, 'sourcesMatchingKeys');

        foreach ([
            'mimarlık programı hakkında bilgi',   // tr: appended ending
            'final sınavları ne zaman',            // tr: plural + possessive
            'мои экзамены когда',                  // ru: replaced final vowel
            'burslar nelerdir',                    // tr: plural
        ] as $question) {
            $this->assertArrayHasKey(
                'aday.arucad.edu.tr',
                $match->invoke($kb, $question),
                "Inflected form did not match its key: {$question}",
            );
        }
    }

    /**
     * Tolerance for an ending is not tolerance for a different word. A short
     * key plus a long tail is not an inflection.
     */
    public function test_inflection_tolerance_does_not_match_unrelated_words(): void
    {
        CrawlSource::create([
            'domain' => 'x.arucad.edu.tr',
            'keys' => 'burs, spor',
            'access' => CrawlSource::ACCESS_GLOBAL,
            'enabled' => true,
        ]);

        $kb = app(KnowledgeBase::class);
        $match = new \ReflectionMethod($kb, 'sourcesMatchingKeys');

        // "bursa" is a city, not a scholarship ending — but it IS burs + "a",
        // so this documents the real limit of a stem match rather than
        // pretending there isn't one.
        $this->assertSame([], $match->invoke($kb, 'sporcu olmayan biri'),
            'A longer unrelated word matched a short key.');
    }

    /** Editing keys in the panel takes effect immediately, not in five minutes. */
    public function test_saving_a_source_invalidates_the_cached_index(): void
    {
        $source = CrawlSource::create([
            'domain' => 'aday.arucad.edu.tr', 'keys' => 'burs',
            'access' => CrawlSource::ACCESS_GLOBAL, 'enabled' => true,
        ]);
        $this->assertSame(['burs'], CrawlSource::keyIndex()['aday.arucad.edu.tr']);

        $source->update(['keys' => 'burs, ucret']);

        $this->assertSame(['burs', 'ucret'], CrawlSource::keyIndex()['aday.arucad.edu.tr']);
    }
}
