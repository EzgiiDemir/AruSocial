<?php

namespace Tests\Feature;

use App\Filament\Pages\AicadHealthPage;
use App\Filament\Resources\AiQueryConcepts\AiQueryConceptResource;
use App\Filament\Resources\AiQueryConcepts\Pages\ListAiQueryConcepts;
use App\Models\AcademicYear;
use App\Models\AiEntityAlias;
use App\Models\AiQueryConcept;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeFact;
use App\Models\Place;
use App\Models\RoleGrant;
use App\Models\ServiceItem;
use App\Models\Sport;
use App\Models\StaffProfile;
use App\Services\Agent\AruverseAgent;
use App\Services\Ai\AicadHealth;
use App\Services\Ai\AnswerGrounding;
use App\Services\Ai\AskOperations;
use App\Services\Ai\AskTrace;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Evaluation\AssertionEvaluator;
use App\Services\Ai\QueryPlanner;
use App\Services\Knowledge\KnowledgeBase;
use App\Services\Knowledge\KnowledgeFactExtractor;
use App\Support\RequestMemo;
use App\Support\TextFold;
use App\Support\Vector;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 2.5 quality hardening: query concepts, entity-driven retrieval
 * terms, citation eligibility, deterministic ambiguity, programme facts,
 * grounding of named claims and fact contradictions, salvage, cache
 * invalidation through any write path, and the AICAD health page.
 */
class AicadHardeningTest extends TestCase
{
    use RefreshDatabase;

    private AskTrace $trace;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config(['knowledge.on_demand_enabled' => false, 'knowledge.embeddings.enabled' => false, 'ai.web_research.enabled' => false]);
        $this->trace = new AskTrace;
        $this->trace->setVerbose();
        $this->app->instance(AskTrace::class, $this->trace);
    }

    private function page(string $url, string $title, string $body): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'id' => KnowledgeDocument::idForUrl($url), 'url' => $url, 'domain' => (string) parse_url($url, PHP_URL_HOST),
            'title' => $title, 'language' => 'tr', 'content' => $body, 'content_clean' => $body,
            'content_folded' => TextFold::fold($body), 'content_hash' => sha1($url.$body), 'content_length' => mb_strlen($body),
            'fetched_at' => now(), 'is_stale' => false, 'document_status' => 'indexed',
        ]);
    }

    private function place(string $id, string $name): void
    {
        Place::create(['id' => $id, 'name' => $name, 'category' => 'x', 'lat' => 35.3, 'lng' => 33.3, 'description' => '', 'distance' => '', 'density' => '', 'street' => '']);
    }

    // --- Concepts --------------------------------------------------------

    public function test_a_concept_routes_and_expands_retrieval_and_shows_in_the_trace(): void
    {
        $this->page('https://arucad.edu.tr/lisans-akademik-takvim/', 'Lisans Akademik Takvim', 'Akademik takvim: güz dönemi 15 Eylül.');
        $this->page('https://arucad.edu.tr/haber/', 'Haber', 'Dersler ve atölyeler hakkında bir haber.');

        $plan = app(QueryPlanner::class)->plan('dersler ne zaman başlıyor');
        $results = app(KnowledgeBase::class)->relevant('dersler ne zaman başlıyor', 2);

        $this->assertContains('calendar', $plan['tools']);
        $this->assertSame('academic_calendar', $plan['concepts'][0]['concept'] ?? null, 'seeded by the migration');
        $this->assertSame('Lisans Akademik Takvim', $results[0]['title']);
        $search = $this->trace->get('knowledge.search');
        $this->assertSame(['academic_calendar'], $search['concepts']);
        $this->assertContains('takvim', $search['expanded_terms']);
        $this->assertArrayHasKey('concept_path', $this->trace->get('knowledge.candidates')['candidates'][0]['parts']);
    }

    public function test_concepts_leave_unrelated_questions_alone(): void
    {
        $this->assertSame([], app(QueryPlanner::class)->plan('kütüphane nerede')['concepts']);
        $this->assertSame([], app(QueryPlanner::class)->plan('burs oranları')['concepts']);
    }

    public function test_a_concept_edited_through_any_path_takes_effect(): void
    {
        $this->assertSame([], app(QueryPlanner::class)->plan('yaz okulu ne zaman')['concepts']);

        // Query-builder insert: no model event fires.
        DB::table('ai_query_concepts')->insert(['concept' => 'summer_school', 'phrases' => json_encode(['yaz okulu']),
            'domains' => json_encode(['calendar']), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        app(RequestMemo::class)->forgetPrefix('');

        $this->assertSame('summer_school', app(QueryPlanner::class)->plan('yaz okulu ne zaman')['concepts'][0]['concept'] ?? null);
    }

    // --- Cache invalidation ---------------------------------------------

    public function test_a_bulk_alias_delete_is_seen_without_model_events(): void
    {
        $this->place('sports-center', 'Sports Center');
        AiEntityAlias::create(['entity_type' => 'place', 'entity_id' => 'sports-center', 'alias' => 'gym']);
        $this->assertSame('sports-center', app(EntityResolver::class)->resolve('gym nerede')[0]['id'] ?? null);

        // A query-builder delete fires no model event and leaves the cache key's
        // old content behind; the table stamp in the key moves anyway.
        AiEntityAlias::query()->where('normalized_alias', 'gym')->delete();
        app(RequestMemo::class)->forgetPrefix('');

        $this->assertSame([], app(EntityResolver::class)->resolve('gym nerede'));
    }

    public function test_a_bulk_alias_update_is_seen_without_model_events(): void
    {
        $this->place('sports-center', 'Sports Center');
        AiEntityAlias::create(['entity_type' => 'place', 'entity_id' => 'sports-center', 'alias' => 'gym']);
        app(EntityResolver::class)->resolve('gym nerede');

        // An Eloquent bulk update touches updated_at, which is in the stamp.
        $this->travel(1)->seconds();
        AiEntityAlias::query()->update(['active' => false]);
        app(RequestMemo::class)->forgetPrefix('');

        $this->assertSame([], app(EntityResolver::class)->resolve('gym nerede'));
    }

    // --- Retrieval terms and citation eligibility -------------------------

    public function test_a_typo_resolved_to_an_entity_retrieves_by_the_entity_name(): void
    {
        ServiceItem::create(['id' => 'library', 'title' => 'Kütüphane', 'category' => 'Akademik', 'description' => 'Kitaplar', 'contact' => 'k@x', 'building' => 'Meditation']);
        $this->page('https://arucad.edu.tr/kutuphane/', 'Kütüphane', 'Kütüphane çalışma saatleri ve kurallar.');

        $results = app(KnowledgeBase::class)->relevant('kutupane nerde', 1);

        $this->assertSame('Kütüphane', $results[0]['title'] ?? null);
        $this->assertContains('kutuphane', $this->trace->get('knowledge.search')['expanded_terms']);
    }

    public function test_similarity_only_pages_are_neither_supplied_nor_cited_when_structured_data_answers(): void
    {
        config(['knowledge.embeddings.enabled' => true, 'knowledge.embeddings.base_url' => 'http://embed.test']);
        Http::fake(['embed.test/*' => Http::response(['vectors' => [[1.0, 0.0, 0.0]]])]);
        $noise = $this->page('https://arucad.edu.tr/hamlet/', 'HAMLET 3', 'Tiyatro metni.');
        KnowledgeChunk::create(['knowledge_document_id' => $noise->id, 'position' => 0, 'text' => 'Tiyatro', 'embedding' => Vector::pack([0.7, 0.71, 0.0]), 'model' => 't']);

        $structured = app(KnowledgeBase::class)->contextWithSources('kutupane nerde', 4, true);
        $this->assertSame([], $structured['sources']);
        $this->assertSame('similarity only, while structured data answers', $this->trace->get('knowledge.eligibility')['dropped'][0]['reason']);

        // Without structured data, the same page needs 0.60 similarity.
        config(['knowledge.citations.semantic_only_min_similarity' => 0.75]);
        $this->assertSame([], app(KnowledgeBase::class)->contextWithSources('kutupane nerde', 4)['sources']);
        config(['knowledge.citations.semantic_only_min_similarity' => 0.6]);
        $this->assertCount(1, app(KnowledgeBase::class)->contextWithSources('kutupane nerde', 4)['sources']);
    }

    // --- Ambiguity -------------------------------------------------------

    public function test_an_ambiguous_entity_gets_a_deterministic_clarification_with_choices(): void
    {
        $this->place('rodin', 'Rodin');
        $this->place('titan', 'Titan');
        AiEntityAlias::create(['entity_type' => 'place', 'entity_id' => 'rodin', 'alias' => 'idari bina']);
        AiEntityAlias::create(['entity_type' => 'place', 'entity_id' => 'titan', 'alias' => 'idari bina']);

        $tr = app(AskOperations::class)->resolve('idari bina nerede?');
        $en = app(AskOperations::class)->resolve('where is the idari bina?');

        $this->assertStringContainsString('Rodin', (string) $tr['answer']);
        $this->assertStringContainsString('Titan', (string) $tr['answer']);
        $this->assertStringContainsString('Hangisini', (string) $tr['answer']);
        $this->assertStringContainsString('Which one', (string) $en['answer']);
        $this->assertEqualsCanonicalizing(['rodin', 'titan'], array_column($tr['places'], 'id'));
        $this->assertSame('AMBIGUOUS', $tr['warnings'][0]['code']);
        $this->assertCount(2, $tr['warnings'][0]['choices']);

        // Naming one outright is not ambiguous.
        $this->assertNull(app(AskOperations::class)->resolve('Titan idari bina nerede?')['answer'] === 'x' ? 'x' : null);
        $this->assertStringNotContainsString('Hangisini', (string) app(AskOperations::class)->resolve('Titan nerede?')['answer']);
    }

    // --- Programme facts -------------------------------------------------

    public function test_programme_facts_come_only_from_the_field_block_with_provenance(): void
    {
        $block = $this->page('https://aday.arucad.edu.tr/rt-program/gorsel-iletisim-tasarimi/', 'Görsel İletişim Tasarımı – Aday – ARUCAD',
            'Program Bilgileri Eğitim Dili İngilizce Eğitim Süresi 4 Yıl Zorunlu İngilizce Hazırlık Programı Var');
        $prose = $this->page('https://arucad.edu.tr/rt-program/kentsel/', 'Kentsel Tasarım - ARUCAD',
            'Eğitim dili nedir? Plastik Sanatlar Bölümünün eğitim dili İngilizce’dir.');

        $extractor = app(KnowledgeFactExtractor::class);
        $this->assertSame(2, $extractor->extract($block));
        $this->assertSame(0, $extractor->extract($prose), 'FAQ prose is not parsed: it can name the wrong department');

        $language = KnowledgeFact::query()->where('attribute', KnowledgeFact::LANGUAGE)->sole();
        $this->assertSame('Görsel İletişim Tasarımı', $language->subject);
        $this->assertSame('İngilizce', $language->value);
        $this->assertSame($block->url, $language->source_url);
        $this->assertStringContainsString('Eğitim Dili İngilizce', $language->source_passage);
        $this->assertSame('4 Yıl', KnowledgeFact::query()->where('attribute', KnowledgeFact::DURATION)->value('value'));

        $rows = app(AruverseAgent::class)->tools('görsel iletişim tasarımı eğitim dili')['programs']['gather']();
        $this->assertSame(["- Görsel İletişim Tasarımı: eğitim dili İngilizce; eğitim süresi 4 Yıl (kaynak: {$block->url})"], $rows);
    }

    public function test_an_explicit_turkish_title_marker_is_a_fact_but_its_absence_is_not(): void
    {
        $marked = $this->page('https://prospective.arucad.edu.tr/rt-program/new-media/', 'New Media and Communication (Turkish) – Prospective ARUCAD', 'Programme.');
        $unmarked = $this->page('https://prospective.arucad.edu.tr/rt-program/vcd/', 'Visual Communication Design – Prospective ARUCAD', 'Programme.');

        $this->assertSame(1, app(KnowledgeFactExtractor::class)->extract($marked));
        $this->assertSame(0, app(KnowledgeFactExtractor::class)->extract($unmarked), 'no marker does not mean English');
        $fact = KnowledgeFact::query()->sole();
        $this->assertSame(['New Media and Communication', 'Türkçe'], [$fact->subject, $fact->value]);
        $this->assertSame($marked->title, $fact->source_passage);

        // The evaluation assertion uses the same facts.
        $evaluator = app(AssertionEvaluator::class);
        $wrong = $evaluator->evaluate([['type' => 'response.no_fact_contradiction']],
            ['answer' => 'Most programmes are taught in English, including New Media and Communication.']);
        $right = $evaluator->evaluate([['type' => 'response.no_fact_contradiction']],
            ['answer' => 'New Media and Communication is taught in Turkish; Visual Communication Design in English.']);
        $this->assertSame('generation', $wrong['failure_stage']);
        $this->assertSame([], $right['failed']);
    }

    // --- Grounding -------------------------------------------------------

    public function test_grounding_flags_invented_institutions_and_fact_contradictions(): void
    {
        KnowledgeFact::create(['knowledge_document_id' => 'd', 'subject_type' => 'programme', 'subject' => 'Görsel İletişim Tasarımı',
            'subject_folded' => 'gorsel iletisim tasarimi', 'attribute' => KnowledgeFact::LANGUAGE, 'value' => 'İngilizce',
            'source_url' => 'https://aday.arucad.edu.tr/x/', 'source_passage' => 'Eğitim Dili İngilizce']);
        $grounding = app(AnswerGrounding::class);
        $source = 'ARUCAD programları. Görsel İletişim Tasarımı.';

        $invented = $grounding->ungrounded('Ayrıca Eastern Mediterranean University ile ortak program var.', $source);
        $contradiction = $grounding->ungrounded('Görsel İletişim Tasarımı Türkçe öğretilir.', $source);
        $agrees = $grounding->ungrounded('Görsel İletişim Tasarımı İngilizce öğretilir.', $source);

        $this->assertContains('Eastern Mediterranean University', $invented['names']);
        $this->assertSame([['subject' => 'Görsel İletişim Tasarımı', 'fact' => 'İngilizce']], $contradiction['facts']);
        $this->assertTrue($grounding->isClean($agrees));
    }

    public function test_salvage_removes_one_unsupported_sentence_only_when_enough_remains(): void
    {
        $grounding = app(AnswerGrounding::class);
        $source = 'Burs oranları yüzde elli ve yüzde yüz. Başvuru Ağustos ayında. Belgeler öğrenci işlerine verilir.';
        $answer = 'Burs oranları yüzde elli ve yüzde yüzdür. Başvuru Ağustos ayında yapılır. Belgeler öğrenci işlerine verilir. ЕГЭ sonucu da gerekir.';

        $salvaged = $grounding->salvage($answer, $source);
        $this->assertNotNull($salvaged);
        $this->assertStringNotContainsString('ЕГЭ', $salvaged);
        $this->assertTrue($grounding->isClean($grounding->ungrounded($salvaged, $source)));

        $this->assertNull($grounding->salvage('Başvuru Ağustos ayında. ЕГЭ gerekir.', $source), 'too little left before a retry');
        $this->assertNotNull($grounding->salvage('Başvuru Ağustos ayında yapılır. Belgeler öğrenci işlerine verilir. ЕГЭ gerekir.', $source, true),
            'looser after a failed retry');
    }

    public function test_the_api_salvages_instead_of_regenerating_and_records_it(): void
    {
        $this->actingAsRole('student');
        config(['ai.provider' => 'local', 'ai.fallback' => '', 'ai.providers.local.base_url' => 'http://local.test/v1',
            'ai.providers.local.model' => 'm', 'ai.cache.enabled' => false, 'ai.retries' => 0]);
        $this->page('https://arucad.edu.tr/burslar/', 'Burslar', 'Burs oranları yüzde elli. Başvuru Ağustos ayında yapılır. Belgeler öğrenci işlerine verilir.');
        Http::fake(['local.test/*' => Http::response(['choices' => [['message' => ['content' => 'Burs oranları yüzde elli. Başvuru Ağustos ayında yapılır. Belgeler öğrenci işlerine verilir. Ayrıca YKSX belgesi istenir.'], 'finish_reason' => 'stop']]])]);

        $response = $this->postJson('/api/v1/ai/query', ['prompt' => 'burs başvurusu nasıl yapılır?'])->assertOk();
        $answer = (string) $response->json('data.answer');
        $this->assertSame('local', $response->json('data.aiMode'));

        $this->assertStringNotContainsString('YKSX', $answer);
        $this->assertStringContainsString('Ağustos', $answer);
        Http::assertSentCount(1);   // no second generation
        $this->assertSame('before_retry', $this->trace->get('grounding')['salvaged']);
        $this->assertCount(1, collect($this->trace->stages())->where('stage', 'model.attempt'));
    }

    // --- Health and data warnings ----------------------------------------

    public function test_data_quality_warnings_explain_gaps_without_inventing_data(): void
    {
        AcademicYear::create(['id' => 'y', 'label' => '2025-2026', 'starts_on' => now()->subYears(2)->toDateString(), 'ends_on' => now()->subMonth()->toDateString(), 'is_active' => true]);
        Sport::create(['id' => 's', 'name' => 'Basketbol', 'facility' => 'Spor Salonu']);
        StaffProfile::create(['id' => 'o1', 'name' => 'Mimarlık Danışmanı', 'active' => true]);
        StaffProfile::create(['id' => 'o2', 'name' => 'Öğrenci İşleri', 'active' => true]);

        $areas = array_column(app(AicadHealth::class)->dataWarnings(), 'area');

        // Staging rollout added the always-true "no announcements source" gap and
        // the missing programme aliases (no programme facts AND no aliases here).
        $this->assertEqualsCanonicalizing(['calendar', 'sports', 'directory', 'programmes', 'programmes', 'announcements'], $areas);
        $this->assertTrue(collect(app(AicadHealth::class)->dataWarnings())->every(fn ($w) => ($w['affects'] ?? []) !== []), 'each gap names the fact types it affects');
        $this->assertSame(0, Place::count(), 'diagnostics never create the missing data');
    }

    public function test_the_health_page_and_concept_screen_are_staff_only_and_render(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAsRole('student');
        $this->assertFalse(AicadHealthPage::canAccess());
        $this->assertFalse(AiQueryConceptResource::canViewAny());

        $admin = $this->actingAsRole('platformAdmin');
        RoleGrant::create(['id' => (string) Str::uuid(), 'user_id' => $admin->id, 'role' => 'platformAdmin', 'status' => 'active', 'assigned_by' => 'test']);
        Livewire::test(AicadHealthPage::class)->assertOk()->assertSee(__('panel.aicad_health.data_warnings'));
        Livewire::test(ListAiQueryConcepts::class)->assertOk()->assertSee('academic_calendar');
        $this->assertTrue(AiQueryConceptResource::canCreate());
        $this->assertSame(3, AiQueryConcept::count());
    }
}
