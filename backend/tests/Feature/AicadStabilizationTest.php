<?php

namespace Tests\Feature;

use App\Models\AiEntityAlias;
use App\Models\Club;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Place;
use App\Services\Agent\AruverseAgent;
use App\Services\Ai\AiPrivacy;
use App\Services\Ai\AiResponder;
use App\Services\Ai\AnswerGrounding;
use App\Services\Ai\AskOperations;
use App\Services\Ai\AskPromptBuilder;
use App\Services\Ai\AskTrace;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\QueryPlanner;
use App\Services\Knowledge\CampusVocabulary;
use App\Services\Knowledge\KnowledgeBase;
use App\Support\TextFold;
use App\Support\Vector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regressions found by the Phase 1 stabilization pass against real data:
 * citation integrity, bounded hybrid retrieval, word-start matching,
 * planner false positives, alias ambiguity and answer-cache staleness.
 */
class AicadStabilizationTest extends TestCase
{
    use RefreshDatabase;

    private AskTrace $trace;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config([
            'ai.provider' => 'local',
            'ai.fallback' => '',
            'ai.providers.local.base_url' => 'http://local.test/v1',
            'ai.providers.local.model' => 'arucad-ask',
            'ai.retries' => 0,
            'ai.web_research.enabled' => false,
            'knowledge.on_demand_enabled' => false,
            'knowledge.embeddings.enabled' => false,
        ]);
        $this->trace = new AskTrace;
        $this->trace->setVerbose();
        $this->app->instance(AskTrace::class, $this->trace);
    }

    private function page(string $slug, string $title, string $body, string $language = 'tr'): KnowledgeDocument
    {
        $url = "https://arucad.edu.tr/{$slug}/";

        return KnowledgeDocument::create([
            'id' => KnowledgeDocument::idForUrl($url), 'url' => $url, 'domain' => 'arucad.edu.tr',
            'title' => $title, 'language' => $language, 'content' => $body, 'content_clean' => $body,
            'content_folded' => TextFold::fold($body), 'content_hash' => sha1($url.$body),
            'content_length' => mb_strlen($body), 'fetched_at' => now(), 'is_stale' => false,
            'document_status' => 'indexed',
        ]);
    }

    // --- Citations -------------------------------------------------------

    public function test_a_source_cut_by_the_context_budget_is_not_cited(): void
    {
        config(['ai.context_budget_chars' => 500]);
        foreach (['a', 'b', 'c'] as $slug) {
            $this->page("burslar-{$slug}", "Burslar {$slug}", str_repeat("Burs oranları ve başvuru koşulları {$slug}. ", 40));
        }

        $built = app(AskPromptBuilder::class)->build('burs oranları');

        $candidates = $this->trace->get('citations')['candidates'];
        $fates = array_column($candidates, 'fate', 'url');
        $this->assertCount(3, $fates, 'every retrieved page is a diagnostic candidate');
        $this->assertContains('dropped', $fates);
        $this->assertCount(count(array_diff($fates, ['dropped'])), $built['sources'],
            'only sources that reached the prompt are returned for citation');
        foreach ($built['sources'] as $source) {
            // A truncated entry lost its trailing URL but its text was given
            // to the model; a kept entry is in the prompt whole.
            $this->assertStringContainsString(
                $fates[$source['url']] === 'kept' ? $source['url'] : $source['title'],
                $built['prompt'],
            );
        }
        foreach ($candidates as $candidate) {
            if ($candidate['fate'] === 'dropped') {
                $this->assertStringNotContainsString($candidate['title'].' [', $built['prompt']);
                $this->assertNotNull($candidate['reason']);
            }
        }
    }

    public function test_sources_that_fit_are_all_cited(): void
    {
        $this->page('burslar', 'Burslar ve Ücretler', 'Burs oranları yüzde elli.');

        $built = app(AskPromptBuilder::class)->build('burs oranları');

        $this->assertSame(['kept'], array_column($this->trace->get('citations')['candidates'], 'fate'));
        $this->assertCount(1, $built['sources']);
    }

    // --- Retrieval -------------------------------------------------------

    public function test_a_stem_inside_another_word_does_not_earn_title_or_body_points(): void
    {
        $this->page('infografik', 'İnfografik Tasarımı', str_repeat('infografik tasarımı dersi ', 20));
        $this->page('grafik-tasarim', 'Grafik Tasarım Bölümü', 'Grafik tasarım bölümü hakkında bilgi.');

        $results = app(KnowledgeBase::class)->relevant('grafik tasarım', 2);

        $this->assertSame('Grafik Tasarım Bölümü', $results[0]['title']);
    }

    public function test_semantic_similarity_nominates_only_a_bounded_shortlist_but_scores_every_keyword_candidate(): void
    {
        config([
            'knowledge.embeddings.enabled' => true,
            'knowledge.embeddings.base_url' => 'http://embed.test',
            'knowledge.embeddings.min_similarity' => 0.25,
            'knowledge.embeddings.max_candidates' => 1,
        ]);
        Http::fake(['embed.test/*' => Http::response(['vectors' => [[1.0, 0.0, 0.0]]])]);
        $keyword = $this->page('burslar', 'Burslar', 'Burs oranları.');
        $closest = $this->page('closest', 'Closest page', 'Unrelated words.');
        $second = $this->page('second', 'Second page', 'Other words.');
        foreach ([[$keyword, [0.5, 0.866, 0.0]], [$closest, [1.0, 0.0, 0.0]], [$second, [0.9, 0.436, 0.0]]] as [$doc, $vector]) {
            KnowledgeChunk::create(['knowledge_document_id' => $doc->id, 'position' => 0, 'text' => $doc->title,
                'embedding' => Vector::pack($vector), 'model' => 'test']);
        }

        app(KnowledgeBase::class)->relevant('burs', 10);

        $search = $this->trace->get('knowledge.search');
        $this->assertSame(1, $search['semantic_candidates']);
        $this->assertSame(2, $search['candidates'], 'keyword page + the single closest page');
        $titles = array_column($this->trace->get('knowledge.candidates')['candidates'], 'title');
        $this->assertNotContains('Second page', $titles, 'a merely similar page beyond the shortlist is not a candidate');
        $keywordRow = collect($this->trace->get('knowledge.candidates')['candidates'])->firstWhere('title', 'Burslar');
        $this->assertEqualsWithDelta(0.5, $keywordRow['parts']['similarity'], 0.01,
            'a keyword candidate keeps its similarity even outside the shortlist');
    }

    // --- Planner ---------------------------------------------------------

    /** @return list<string> */
    private function domains(string $q): array
    {
        return array_keys(app(QueryPlanner::class)->plan($q)['domains']);
    }

    public function test_turkish_consonant_softening_and_short_vowel_words_still_route(): void
    {
        $this->assertContains('clubs', $this->domains('yemek kulübü'));
        $this->assertContains('clubs', $this->domains('kulübe nasıl katılırım'));
        $this->assertContains('staff', $this->domains('hocaya nasıl ulaşırım'));
        $this->assertContains('food', $this->domains('menüde ne var'));
    }

    public function test_english_or_generic_words_do_not_take_turkish_endings_or_loose_typos(): void
    {
        $this->assertNotContains('shuttle', $this->domains('ringa balığı'));
        $this->assertNotContains('calendar', $this->domains('termos nerede satılır'));
        $this->assertNotContains('career', $this->domains('часы работы библиотеки'));
        $this->assertNotContains('career', $this->domains('иду на работу'));
        $this->assertNotContains('shuttle', $this->domains('servet ne kadar'), 'six-letter keywords are not typo-matched');
    }

    public function test_a_recognised_domain_without_tables_is_not_a_fallback(): void
    {
        $plan = app(QueryPlanner::class)->plan('başvurum ne durumda');

        $this->assertSame(['account'], array_keys($plan['domains']));
        $this->assertFalse($plan['fallback']);
        $this->assertSame([], $plan['tools'], 'personal context answers this, not campus tables');
    }

    public function test_calendar_phrasings_route_to_the_live_term_dates(): void
    {
        $this->assertContains('calendar', app(QueryPlanner::class)->plan('dersler ne zaman başlıyor?')['tools']);
    }

    public function test_a_generic_what_is_there_about_food_is_not_answered_as_events(): void
    {
        $food = app(AskOperations::class)->resolve('bugün yemekte ne var?');
        $events = app(AskOperations::class)->resolve('bugün kampüste ne var?');

        $this->assertStringNotContainsString('etkinli', (string) $food['answer']);
        $this->assertStringContainsString('etkinli', (string) $events['answer']);
    }

    public function test_an_inflected_word_still_reaches_its_cross_language_equivalents(): void
    {
        $expanded = CampusVocabulary::expand(['стипендию']);

        $this->assertContains('burs', $expanded);
        $this->assertSame(['ne'], CampusVocabulary::expand(['ne']), 'short words never stem-match');
    }

    public function test_a_date_written_differently_from_its_source_is_still_grounded(): void
    {
        $grounding = app(AnswerGrounding::class);
        $source = '- 2025-2026 (aktif): 2025-09-01 — 2026-08-31';

        $this->assertSame([], $grounding->ungrounded('Dersler 1 Eylül 2025 tarihinde başlar.', $source)['dates']);
        $this->assertSame([], $grounding->ungrounded('Term ends 31.08.2026.', $source)['dates']);
        $this->assertSame(['15 Eylül 2025'], $grounding->ungrounded('Dersler 15 Eylül 2025 tarihinde başlar.', $source)['dates'],
            'an invented date is still caught');
    }

    // --- Ambiguity -------------------------------------------------------

    public function test_an_alias_shared_by_two_entities_of_one_type_is_reported_ambiguous(): void
    {
        Place::create(['id' => 'rodin', 'name' => 'Rodin', 'category' => 'Administration', 'lat' => 1, 'lng' => 1, 'description' => '', 'distance' => '', 'density' => '', 'street' => '']);
        Place::create(['id' => 'titan', 'name' => 'Titan', 'category' => 'Admin', 'lat' => 1, 'lng' => 1, 'description' => '', 'distance' => '', 'density' => '', 'street' => '']);
        AiEntityAlias::create(['entity_type' => 'place', 'entity_id' => 'rodin', 'alias' => 'idari bina']);
        AiEntityAlias::create(['entity_type' => 'place', 'entity_id' => 'titan', 'alias' => 'idari bina']);

        $entities = app(EntityResolver::class)->resolve('idari bina nerede');

        $this->assertEqualsCanonicalizing(['rodin', 'titan'], array_column($entities, 'id'), 'neither is silently dropped');
        $this->assertSame([true, true], array_column($entities, 'ambiguous'));
        $this->assertLessThan(0.9, $entities[0]['score'], 'an ambiguous match ranks below an unambiguous alias');
        $this->assertFalse(app(EntityResolver::class)->resolve('titan nerede')[0]['ambiguous']);

        $context = app(AruverseAgent::class)->buildContext('idari bina nerede')['context'];
        $this->assertStringContainsString('BELİRSİZ AD', $context, 'the model is told rather than left to pick');
        $this->assertStringContainsString('Rodin', $context);
        $this->assertStringContainsString('Titan', $context);
    }

    // --- Answer cache ----------------------------------------------------

    public function test_a_cached_answer_is_not_served_after_aliases_or_live_campus_data_change(): void
    {
        Http::fake(['local.test/*' => Http::response([
            'choices' => [['message' => ['content' => 'Cevap.'], 'finish_reason' => 'stop']],
        ])]);
        $built = 0;
        $ask = function () use (&$built) {
            return app(AiResponder::class)->generate(function () use (&$built) {
                $built++;

                return [['role' => 'system', 'content' => 'kurallar'], ['role' => 'user', 'content' => 'gym nerede']];
            }, 'gym nerede');
        };

        $ask();
        $this->assertTrue($ask()->cached, 'an unchanged question is served from cache');
        $this->assertSame(1, $built);

        AiEntityAlias::create(['entity_type' => 'place', 'entity_id' => 'x', 'alias' => 'gym']);
        $this->assertFalse($ask()->cached, 'a new alias changes what is retrieved');

        Club::create(['id' => 'club-new', 'name' => 'Yeni Kulüp', 'category' => 'x', 'description' => '']);
        $this->assertFalse($ask()->cached, 'live campus data changed');

        $this->travel(1)->days();
        $this->assertFalse($ask()->cached, '"today" moved');
        $this->assertSame(4, $built);
    }
}
