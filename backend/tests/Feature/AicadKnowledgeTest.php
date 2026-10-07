<?php

namespace Tests\Feature;

use App\Filament\Pages\AskPlayground;
use App\Filament\Resources\AiEntityAliases\AiEntityAliasResource;
use App\Filament\Resources\KnowledgeDocuments\KnowledgeDocumentResource;
use App\Filament\Resources\KnowledgeSources\KnowledgeSourceResource;
use App\Jobs\CrawlKnowledgeUrlJob;
use App\Models\AiEntityAlias;
use App\Models\AskConversation;
use App\Models\Club;
use App\Models\CrawlSource;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeSource;
use App\Models\Place;
use App\Models\RoleGrant;
use App\Models\ServiceItem;
use App\Services\Agent\AruverseAgent;
use App\Services\Ai\AskDiagnostics;
use App\Services\Ai\AskTrace;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\PlaceResolver;
use App\Services\Ai\QueryPlanner;
use App\Services\Knowledge\KnowledgeBase;
use App\Services\Knowledge\SiteKnowledgeCrawler;
use App\Support\PhraseMatcher;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AICAD Knowledge: routing, entity resolution, aliases, operator-managed
 * sources, the retrieval trace and the admin screens built on them.
 */
class AicadKnowledgeTest extends TestCase
{
    use RefreshDatabase;

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
            'ai.cache.enabled' => false,
            'ai.retries' => 0,
            'ai.web_research.enabled' => false,
            'knowledge.on_demand_enabled' => false,
            'knowledge.embeddings.enabled' => false,
        ]);

        Place::create(['id' => 'titan', 'name' => 'Titan', 'category' => 'Administration', 'lat' => 35.338, 'lng' => 33.322, 'description' => 'Administration', 'distance' => '', 'density' => '', 'street' => '']);
        Place::create(['id' => 'sports-center', 'name' => 'Sports Center', 'category' => 'Sport', 'lat' => 35.339, 'lng' => 33.323, 'description' => 'Indoor courts', 'distance' => '', 'density' => '', 'street' => '']);
        ServiceItem::create(['id' => 'student-affairs', 'title' => 'Öğrenci İşleri (Student Affairs)', 'category' => 'Administrative', 'description' => 'Kayıt ve belge işlemleri', 'contact' => 'registrar@example.edu', 'building' => 'Titan']);
        Club::create(['id' => 'basketball-club', 'name' => 'Basketbol Kulübü', 'category' => 'Sport', 'description' => 'Instagram: @arucadbasket']);
    }

    // --- Phrase matching -------------------------------------------------

    public function test_a_keyword_must_start_a_word(): void
    {
        // The old router matched `iş` inside "işlerine" and `aç` inside "kaç".
        $this->assertNull(PhraseMatcher::position('ogrenci islerine nasil giderim', 'is', 4));
        $this->assertNull(PhraseMatcher::position('kac kisi gelir', 'ac', 4));
        $this->assertNull(PhraseMatcher::position('tuition fees', 'it', 4));
    }

    public function test_the_last_word_of_a_phrase_may_carry_an_ending(): void
    {
        $this->assertNotNull(PhraseMatcher::position('ogrenci islerine nasil giderim', 'ogrenci isleri'));
        $this->assertNotNull(PhraseMatcher::position('kuluplere nasil katilirim', 'kulup', 4));
    }

    public function test_fuzzy_matching_tolerates_one_edit_on_long_words_only(): void
    {
        $this->assertTrue(PhraseMatcher::fuzzy('kutupane nerede', 'kutuphane'));
        $this->assertFalse(PhraseMatcher::fuzzy('spot nerede', 'spor'), 'four-letter words must match exactly');
        $this->assertFalse(PhraseMatcher::fuzzy('kutu nerede', 'kutuphane'));
    }

    // --- Routing ---------------------------------------------------------

    /** @return array<string, list<string>> */
    private function domains(string $question): array
    {
        return array_keys(app(QueryPlanner::class)->plan($question)['domains']);
    }

    public function test_student_affairs_navigation_is_not_routed_to_career(): void
    {
        $domains = $this->domains('öğrenci işlerine nasıl giderim');

        $this->assertContains('services', $domains);
        $this->assertContains('navigation', $domains);
        $this->assertNotContains('career', $domains);
    }

    public function test_substrings_of_other_words_no_longer_select_tools(): void
    {
        $this->assertNotContains('food', $this->domains('kaç kişi gelir'));
        $this->assertNotContains('places', $this->domains('kulübe nasıl katılırım'));
    }

    public function test_questions_without_turkish_letters_route(): void
    {
        $domains = $this->domains('ogrenci isleri nerde');

        $this->assertContains('services', $domains);
        $this->assertContains('places', $domains);
    }

    #[DataProvider('everydayQuestions')]
    public function test_everyday_questions_reach_their_domain(string $question, string $domain): void
    {
        $this->assertContains($domain, $this->domains($question), $question);
    }

    /** @return array<string, array{string, string}> */
    public static function everydayQuestions(): array
    {
        return [
            'gym' => ['gym var mı', 'sports'],
            'fitness' => ['fitness nerede', 'sports'],
            'basket' => ['basket oynayacak yer', 'sports'],
            'clubs' => ['kulüplere nasıl katılırım', 'clubs'],
            'today' => ['bugün ne var', 'events'],
            'tonight' => ['bu akşam bir şey var mı', 'events'],
            'menu' => ['yemek ne çıktı', 'food'],
            'eat en' => ['where can I eat lunch', 'food'],
            'eat ru' => ['где поесть', 'food'],
            'office' => ['hocanın odası nerede', 'staff'],
            'career' => ['kariyerle ilgili ilan var mı', 'career'],
            'internship' => ['staj bulabilir miyim', 'career'],
            'typo' => ['etkinlk var mı', 'events'],
        ];
    }

    public function test_one_question_can_select_several_domains(): void
    {
        $plan = app(QueryPlanner::class)->plan('Basketbol kulübünün instagramı ne ve oraya nasıl giderim?');
        $domains = array_keys($plan['domains']);

        foreach (['clubs', 'sports', 'social', 'navigation'] as $expected) {
            $this->assertContains($expected, $domains);
        }
        $this->assertContains('clubs', $plan['tools']);
        $this->assertContains('places', $plan['tools']);
        $this->assertSame('basketball-club', collect($plan['entities'])->firstWhere('type', 'club')['id'] ?? null);
    }

    public function test_an_unrecognised_question_is_flagged_as_fallback(): void
    {
        $plan = app(QueryPlanner::class)->plan('merhaba');

        $this->assertTrue($plan['fallback']);
        $this->assertSame(['places', 'events', 'services'], $plan['tools']);
        $this->assertFalse(app(QueryPlanner::class)->plan('gym var mı')['fallback']);
    }

    // --- Entity resolution and aliases -----------------------------------

    public function test_an_alias_resolves_to_its_entity_and_reaches_the_operational_resolver(): void
    {
        AiEntityAlias::create(['entity_type' => 'place', 'entity_id' => 'sports-center', 'alias' => 'Spor Salonu']);
        AiEntityAlias::create(['entity_type' => 'place', 'entity_id' => 'sports-center', 'alias' => 'gym']);

        foreach (['spor salonu nerede?', 'gym nerede?'] as $question) {
            $entity = app(EntityResolver::class)->resolve($question)[0] ?? null;
            $this->assertSame('sports-center', $entity['id'] ?? null, $question);
            $this->assertSame('alias', $entity['method']);

            $places = array_map(fn ($m) => $m['place']->id, app(PlaceResolver::class)->mentioned($question));
            $this->assertContains('sports-center', $places, 'aliases must feed the operational place resolver too');
        }
    }

    public function test_an_inactive_alias_is_ignored_and_saving_takes_effect_immediately(): void
    {
        $alias = AiEntityAlias::create(['entity_type' => 'place', 'entity_id' => 'sports-center', 'alias' => 'gym', 'active' => false]);
        $this->assertSame([], app(EntityResolver::class)->resolve('gym nerede'));

        $alias->update(['active' => true]);
        $this->assertSame('sports-center', app(EntityResolver::class)->resolve('gym nerede')[0]['id'] ?? null);
    }

    public function test_a_typo_resolves_fuzzily_and_never_outranks_an_exact_match(): void
    {
        AiEntityAlias::create(['entity_type' => 'place', 'entity_id' => 'sports-center', 'alias' => 'spor merkezi']);

        $typo = app(EntityResolver::class)->resolve('spor merkzi nerede');
        $this->assertSame('sports-center', $typo[0]['id'] ?? null);
        $this->assertSame('fuzzy', $typo[0]['method']);

        // An exact name anywhere in the question suppresses fuzzy matching.
        $exact = app(EntityResolver::class)->resolve('Titan ve spor merkzi');
        $this->assertSame(['titan'], array_column($exact, 'id'));
    }

    public function test_a_resolved_alias_puts_its_place_first_in_the_prompt_rows(): void
    {
        AiEntityAlias::create(['entity_type' => 'place', 'entity_id' => 'sports-center', 'alias' => 'gym']);
        $trace = new AskTrace;
        $trace->setVerbose();
        $this->app->instance(AskTrace::class, $trace);

        app(KnowledgeBase::class); // resolved first so the agent shares this container
        app(AruverseAgent::class)->buildContext('gym nerede');

        $rows = $trace->get('tool.places')['rows'] ?? [];
        $this->assertStringContainsString('Sports Center', $rows[0] ?? '');
    }

    // --- Operator-managed sources ----------------------------------------

    private function allowDomain(): void
    {
        config(['knowledge.verify_public_ip' => false, 'knowledge.crawl_local' => true]);
        CrawlSource::create(['domain' => 'arucad.edu.tr', 'label' => 'ARUCAD', 'access' => 'global', 'enabled' => true]);
    }

    public function test_unsafe_or_foreign_urls_are_rejected(): void
    {
        $this->allowDomain();

        $this->assertNotNull(KnowledgeSource::rejectionFor('not a url'));
        $this->assertNotNull(KnowledgeSource::rejectionFor('http://arucad.edu.tr/burslar/'), 'plain HTTP');
        $this->assertNotNull(KnowledgeSource::rejectionFor('https://example.com/'), 'outside the crawl allow-list');
        $this->assertNotNull(KnowledgeSource::rejectionFor('https://127.0.0.1/'));
        $this->assertNull(KnowledgeSource::rejectionFor('https://arucad.edu.tr/burslar/'));
    }

    public function test_a_private_host_is_rejected_even_inside_the_allow_list(): void
    {
        config(['knowledge.verify_public_ip' => true]);
        CrawlSource::create(['domain' => 'localhost', 'label' => 'x', 'access' => 'global', 'enabled' => true]);

        $this->assertNotNull(KnowledgeSource::rejectionFor('https://localhost/page'));
    }

    public function test_enabled_sources_are_crawl_seeds_and_disabled_ones_are_not(): void
    {
        $this->allowDomain();
        KnowledgeSource::create(['url' => 'https://arucad.edu.tr/gizli-sayfa/#top', 'enabled' => true]);
        KnowledgeSource::create(['url' => 'https://arucad.edu.tr/kapali/', 'enabled' => false]);
        KnowledgeSource::create(['url' => 'https://example.com/elsewhere/', 'enabled' => true]);

        $seeds = app(SiteKnowledgeCrawler::class)->seedUrls();

        $this->assertContains('https://arucad.edu.tr/gizli-sayfa/', $seeds, 'stored without the fragment');
        $this->assertNotContains('https://arucad.edu.tr/kapali/', $seeds);
        $this->assertNotContains('https://example.com/elsewhere/', $seeds, 'a source row cannot widen the allow-list');
    }

    public function test_crawl_now_records_success_and_failure_on_the_source(): void
    {
        $this->allowDomain();
        config(['knowledge.use_sitemaps' => false, 'knowledge.discover_links' => false, 'knowledge.delay_ms' => 0, 'knowledge.verify_ssl' => false]);
        Http::fake([
            'arucad.edu.tr/burslar/' => Http::response('<html><head><title>Burslar</title></head><body><main><h1>Burslar</h1><p>'
                .str_repeat('Burs oranları ve başvuru koşulları bu sayfada açıklanır. ', 20).'</p></main></body></html>', 200, ['Content-Type' => 'text/html']),
            'arucad.edu.tr/yok/' => Http::response('', 404),
        ]);
        $good = KnowledgeSource::create(['url' => 'https://arucad.edu.tr/burslar/']);
        $bad = KnowledgeSource::create(['url' => 'https://arucad.edu.tr/yok/']);

        (new CrawlKnowledgeUrlJob($good->url, $good->id))->handle(app(SiteKnowledgeCrawler::class));
        (new CrawlKnowledgeUrlJob($bad->url, $bad->id))->handle(app(SiteKnowledgeCrawler::class));

        $this->assertSame(KnowledgeSource::STATUS_OK, $good->fresh()->last_crawl_status);
        $this->assertNotNull($good->fresh()->document);
        $this->assertSame(KnowledgeSource::STATUS_FAILED, $bad->fresh()->last_crawl_status);
        $this->assertNotNull($bad->fresh()->last_crawl_error);
    }

    // --- Trace -----------------------------------------------------------

    public function test_the_trace_redacts_personal_fields_and_skips_detail_unless_verbose(): void
    {
        $trace = new AskTrace;
        $trace->record('prompt', ['personal_lines' => ['Randevu: 12:00'], 'nested' => ['email' => 'a@b.c', 'size' => 3], 'system_prompt' => 'rules']);
        $evaluated = false;
        $trace->detail('expensive', function () use (&$evaluated) {
            $evaluated = true;

            return [];
        });

        $data = $trace->get('prompt');
        $this->assertSame('[redacted]', $data['personal_lines']);
        $this->assertSame('[redacted]', $data['nested']['email']);
        $this->assertSame('[redacted]', $data['system_prompt']);
        $this->assertSame(3, $data['nested']['size']);
        $this->assertFalse($evaluated);
        $this->assertNull($trace->get('expensive'));
    }

    public function test_ranking_is_explained_component_by_component(): void
    {
        KnowledgeDocument::create([
            'id' => sha1('https://arucad.edu.tr/burslar/'), 'url' => 'https://arucad.edu.tr/burslar/',
            'domain' => 'arucad.edu.tr', 'title' => 'Burslar ve Ücretler', 'language' => 'tr',
            'content' => 'Burs oranlari ve basvuru kosullari bu sayfada.', 'content_hash' => sha1('b'),
            'content_length' => 46, 'fetched_at' => now(), 'is_stale' => false,
        ]);
        $trace = new AskTrace;
        $trace->setVerbose();
        $this->app->instance(AskTrace::class, $trace);

        app(KnowledgeBase::class)->relevant('burs oranlari');

        $candidate = $trace->get('knowledge.candidates')['candidates'][0];
        $this->assertSame('https://arucad.edu.tr/burslar/', $candidate['url']);
        $this->assertTrue($candidate['selected']);
        $this->assertGreaterThan(0, $candidate['parts']['lexical']);
        $this->assertEqualsWithDelta($candidate['score'], array_sum(array_diff_key($candidate['parts'], ['similarity' => 1])), 0.01);
    }

    // --- Diagnostics -----------------------------------------------------

    public function test_retrieval_mode_traces_every_stage_without_calling_the_model(): void
    {
        Http::fake();

        $report = app(AskDiagnostics::class)->run('Basketbol kulübünün instagramı ne?');

        Http::assertNothingSent();
        $stages = array_column($report['stages'], 'stage');
        foreach (['follow_up', 'operational', 'direct', 'routing', 'entities', 'tool.clubs', 'knowledge.search', 'prompt.budget', 'web_research'] as $expected) {
            $this->assertContains($expected, $stages);
        }
        $this->assertSame('model', $report['result']['would_serve']);
        $clubRows = collect($report['stages'])->firstWhere('stage', 'tool.clubs')['data']['rows'];
        $this->assertStringContainsString('@arucadbasket', implode("\n", $clubRows));
    }

    public function test_retrieval_mode_resolves_a_follow_up_from_earlier_turns(): void
    {
        $report = app(AskDiagnostics::class)->run('oraya nasıl giderim?', AskDiagnostics::MODE_RETRIEVAL, [
            ['role' => 'user', 'content' => 'Titan nerede?'],
        ]);

        $followUp = collect($report['stages'])->firstWhere('stage', 'follow_up')['data'];
        $this->assertTrue($followUp['rewritten']);
        $this->assertStringContainsStringIgnoringCase('titan', $followUp['retrieval_query']);
    }

    public function test_full_mode_runs_the_real_answer_path_and_leaves_no_conversation_behind(): void
    {
        $user = $this->actingAsRole('superAdmin');
        Http::fake(['local.test/*' => Http::response([
            'choices' => [['message' => ['content' => 'Burs bilgileri için ARUCAD sayfasına bakın.'], 'finish_reason' => 'stop']],
        ])]);

        $report = app(AskDiagnostics::class)->run('burs imkanları neler', AskDiagnostics::MODE_FULL, [], false, $user);

        $stages = array_column($report['stages'], 'stage');
        $this->assertContains('model', $stages);
        $this->assertContains('final', $stages);
        $this->assertSame('local', collect($report['stages'])->firstWhere('stage', 'model')['data']['provider']);
        $this->assertNotEmpty($report['result']['answer'] ?? '');
        $this->assertSame(0, AskConversation::query()->count());
    }

    // --- Permissions -----------------------------------------------------

    public function test_students_cannot_open_the_aicad_screens(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAsRole('student');

        $this->assertFalse(AskPlayground::canAccess());
        $this->assertFalse(AiEntityAliasResource::canViewAny());
        $this->assertFalse(KnowledgeSourceResource::canViewAny());
        $this->assertFalse(KnowledgeDocumentResource::canViewAny());
    }

    public function test_a_grant_without_ai_prompt_cannot_open_them_and_a_platform_admin_can(): void
    {
        Filament::setCurrentPanel('admin');
        $user = $this->actingAsRole('analyst');
        RoleGrant::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'role' => 'analyst', 'status' => 'active', 'assigned_by' => 'test']);
        $this->assertFalse(AskPlayground::canAccess());
        $this->assertFalse(AiEntityAliasResource::canCreate());

        $admin = $this->actingAsRole('platformAdmin');
        RoleGrant::create(['id' => (string) Str::uuid(), 'user_id' => $admin->id, 'role' => 'platformAdmin', 'status' => 'active', 'assigned_by' => 'test']);
        $this->assertTrue(AskPlayground::canAccess());
        $this->assertTrue(AiEntityAliasResource::canCreate());
        $this->assertTrue(KnowledgeSourceResource::canCreate());
        $this->assertFalse(KnowledgeDocumentResource::canCreate(), 'crawled pages are written by the crawler only');
    }
}
