<?php

namespace Tests\Feature;

use App\Models\AiEntityAlias;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeFact;
use App\Models\Place;
use App\Models\RoleGrant;
use App\Models\ServiceItem;
use App\Models\User;
use App\Services\Ai\AicadReadiness;
use App\Services\Ai\AskDiagnostics;
use App\Services\Ai\AskTrace;
use App\Services\Ai\Facts\ClaimVerifier;
use App\Services\Ai\Facts\SupportedFactsRollout;
use App\Services\Ai\Planning\TaskOrchestrator;
use App\Services\Ai\ProgrammeCatalog;
use App\Support\TextFold;
use Database\Seeders\AiProgrammeAliasSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Staging rollout correctness: cache identity across modes, cohorts and
 * users; staff_only; rollback and restoration; the alias seeder; readiness;
 * fallback and UNCERTAIN counters; diagnostic isolation; no content in
 * aggregates.
 */
class AicadStagingRolloutTest extends TestCase
{
    use RefreshDatabase;

    private const PLANNED = 'Kütüphane açık mı ve nerede?';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config(['knowledge.on_demand_enabled' => false, 'knowledge.embeddings.enabled' => false, 'ai.web_research.enabled' => false,
            'ai.task_planning.enabled' => true, 'ai.evidence.enabled' => true, 'ai.supported_facts.mode' => 'on',
            'ai.campus_timezone' => 'Europe/Nicosia', 'services.routing.base_url' => null,
            'ai.provider' => 'local', 'ai.fallback' => '', 'ai.providers.local.base_url' => 'http://local.test/v1',
            'ai.providers.local.model' => 'm', 'ai.cache.enabled' => true, 'ai.retries' => 0, 'ai.direct_answers' => false]);
        Carbon::setTestNow(Carbon::parse('2026-10-05 11:00:00', 'UTC'));   // Monday 14:00 campus
        $this->app->instance(AskTrace::class, new AskTrace);

        Place::create(['id' => 'meditation', 'name' => 'Meditation', 'category' => 'Library', 'lat' => 35.337, 'lng' => 33.321, 'description' => '', 'distance' => '', 'density' => '', 'street' => '']);
        ServiceItem::create(['id' => 'library', 'title' => 'Kütüphane', 'category' => 'Akademik', 'description' => 'Kitap',
            'contact' => 'k@x', 'building' => 'Meditation', 'hours' => 'Hafta içi 09:00–17:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Every model reply in order; returns nothing — assert with Http::assertSentCount. */
    private function replies(string ...$texts): void
    {
        $sequence = Http::sequence();
        foreach ($texts as $text) {
            $sequence->push(['choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']]]);
        }
        Http::fake(['local.test/*' => $sequence]);
    }

    /** A fresh request lifecycle: new trace and new rollout state, as between two HTTP requests. */
    private function nextRequest(): void
    {
        $this->app->forgetScopedInstances();
        $this->app->instance(AskTrace::class, new AskTrace);
    }

    private function stage(string $name): ?array
    {
        return collect(app(AskTrace::class)->stages())->last(fn ($s) => $s['stage'] === $name)['data'] ?? null;
    }

    private function staff(): User
    {
        Filament::setCurrentPanel('admin');
        $admin = $this->actingAsRole('platformAdmin');
        RoleGrant::create(['id' => (string) Str::uuid(), 'user_id' => $admin->id, 'role' => 'platformAdmin', 'status' => 'active', 'assigned_by' => 'test']);

        return $admin;
    }

    // --- Cache identity -----------------------------------------------------------

    public function test_a_personal_answer_is_never_served_to_another_user(): void
    {
        // The measured leak: student B got student A's department and level from cache.
        $this->replies('Profilinde bölümün Grafik, seviyen 3.', 'Profilinde bölümün Mimarlık, seviyen 1.');

        $this->actingAsRole('student');
        $first = $this->postJson('/api/v1/ai/query', ['prompt' => 'profilim ne durumda?'])->json('data.answer');
        $this->nextRequest();
        $this->actingAsRole('student');
        $second = $this->postJson('/api/v1/ai/query', ['prompt' => 'profilim ne durumda?'])->json('data');

        Http::assertSentCount(2);
        $this->assertNotSame('cached', $second['aiMode']);
        $this->assertStringNotContainsString('Grafik', $second['answer']);
        $this->assertStringContainsString('Grafik', $first);
    }

    public function test_an_answer_cached_under_one_mode_is_not_served_under_another(): void
    {
        $this->replies('Burs başvuruları Ağustos ayında yapılır.', 'Burs başvuruları Ağustos ayında yapılır (yeni mod).', 'third');
        KnowledgeDocument::create(['id' => sha1('b'), 'url' => 'https://arucad.edu.tr/burslar/', 'domain' => 'arucad.edu.tr', 'title' => 'Burslar', 'language' => 'tr',
            'content' => 'Burs başvuruları Ağustos ayında yapılır.', 'content_clean' => 'Burs başvuruları Ağustos ayında yapılır.',
            'content_folded' => TextFold::fold('Burs başvuruları Ağustos ayında yapılır.'), 'content_hash' => sha1('x'), 'content_length' => 40,
            'fetched_at' => now(), 'is_stale' => false, 'document_status' => 'indexed']);
        $this->actingAsRole('student');   // signed in, but not a personal question: shareable
        $ask = fn () => $this->postJson('/api/v1/ai/query', ['prompt' => 'burs başvurusu ne zaman?'])->json('data.aiMode');

        config(['ai.supported_facts.mode' => 'off']);
        $this->assertSame('local', $ask());
        $this->nextRequest();
        $this->assertSame('cached', $ask(), 'same mode: cached');
        $this->nextRequest();
        config(['ai.supported_facts.mode' => 'on']);
        $this->assertSame('local', $ask(), 'another mode: never the other mode\'s answer');
        $this->nextRequest();
        config(['ai.supported_facts.mode' => 'off']);
        $this->assertSame('cached', $ask(), 'rollback finds its own entry again');
        Http::assertSentCount(2);
    }

    public function test_staff_only_gives_each_cohort_its_own_path_and_never_shares_an_answer(): void
    {
        config(['ai.supported_facts.mode' => 'staff_only']);
        $this->replies('Kütüphane hafta içi 09:00–17:00 açık. Konum: Meditation binası.', 'Kütüphane hafta içi 09:00–17:00 açık, Meditation binasında.',
            'Kütüphane hafta içi 09:00–17:00 açık. Konum: Meditation binası.');

        $this->staff();
        $this->postJson('/api/v1/ai/query', ['prompt' => self::PLANNED])->assertOk();
        $this->assertSame('supported_facts', $this->stage('generation_path')['generation_path']);

        $this->nextRequest();
        $this->actingAsRole('student');
        $student = $this->postJson('/api/v1/ai/query', ['prompt' => self::PLANNED])->json('data');
        $this->assertSame('legacy', $this->stage('generation_path')['generation_path']);
        $this->assertNotSame('cached', $student['aiMode']);
        $this->assertStringContainsString('SORUDAKİ GÖREVLER VE KANITLARI', Http::recorded()[1][0]->data()['messages'][0]['content']);

        $this->nextRequest();
        $this->staff();
        $this->postJson('/api/v1/ai/query', ['prompt' => self::PLANNED])->assertOk();
        Http::assertSentCount(3);   // nothing was served across cohorts from cache
    }

    // --- Rollback and restoration ------------------------------------------------------

    public function test_rollback_to_off_and_back_on_keeps_the_response_contract(): void
    {
        $this->replies(...array_fill(0, 3, 'Kütüphane hafta içi 09:00–17:00 açık. Konum: Meditation binası.'));
        $this->actingAsRole('student');
        $keys = [];
        foreach (['on', 'off', 'on'] as $i => $mode) {
            $this->nextRequest();
            config(['ai.supported_facts.mode' => $mode]);
            $data = $this->postJson('/api/v1/ai/query', ['prompt' => self::PLANNED])->assertOk()->json('data');
            $keys[] = array_keys($data);
            $this->assertSame($mode === 'on' ? 'supported_facts' : 'legacy', $this->stage('generation_path')['generation_path'], "step {$i} ({$mode})");
        }
        $this->assertSame($keys[0], $keys[1], 'the Flutter contract is identical in both modes');
        $this->assertSame($keys[0], $keys[2]);

        // Fast paths are untouched by the mode.
        $this->nextRequest();
        config(['ai.supported_facts.mode' => 'off']);
        $this->assertSame('operational', $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane nerede?'])->json('data.aiMode'));
    }

    // --- Seeder ----------------------------------------------------------------------

    public function test_the_programme_alias_seeder_is_idempotent_and_respects_operator_edits(): void
    {
        $this->seed(AiProgrammeAliasSeeder::class);
        $count = AiEntityAlias::query()->where('entity_type', 'programme')->count();
        $this->assertGreaterThan(20, $count);

        $this->seed(AiProgrammeAliasSeeder::class);
        $this->assertSame($count, AiEntityAlias::query()->where('entity_type', 'programme')->count(), 'a rerun adds nothing');

        // An operator renames one official name and deactivates another.
        $vcd = AiEntityAlias::query()->where('entity_id', 'gorsel iletisim tasarimi')->where('locale', 'en')->firstOrFail();
        $vcd->update(['alias' => 'Visual Communication Design (BA)']);
        $ru = AiEntityAlias::query()->where('entity_id', 'gorsel iletisim tasarimi')->where('locale', 'ru')->firstOrFail();
        $ru->update(['active' => false]);

        $this->seed(AiProgrammeAliasSeeder::class);
        $this->assertSame($count, AiEntityAlias::query()->where('entity_type', 'programme')->count(), 'the renamed name is not brought back');
        $this->assertSame('Visual Communication Design (BA)', $vcd->fresh()->alias);
        $this->assertFalse($ru->fresh()->active);
    }

    public function test_a_new_programme_alias_is_visible_immediately(): void
    {
        KnowledgeFact::create(['knowledge_document_id' => sha1('p'), 'subject_type' => KnowledgeFact::SUBJECT_PROGRAMME, 'subject' => 'Seramik',
            'subject_folded' => 'seramik', 'attribute' => KnowledgeFact::LANGUAGE, 'value' => 'İngilizce', 'source_url' => 'https://x/', 'source_passage' => 'x', 'verified_at' => now()]);
        $catalog = app(ProgrammeCatalog::class);
        $this->assertSame([], $catalog->inText('Is Ceramics taught in English?'));

        AiEntityAlias::create(['entity_type' => 'programme', 'entity_id' => 'seramik', 'alias' => 'Ceramics', 'locale' => 'en']);

        $this->assertSame(['seramik'], array_column($catalog->inText('Is Ceramics taught in English?'), 'id'), 'the write invalidated the catalog');
    }

    // --- Readiness -------------------------------------------------------------------

    public function test_readiness_reports_mode_aliases_timezone_and_rollback(): void
    {
        $byName = fn () => collect(app(AicadReadiness::class)->checks(false))->keyBy('check');

        $checks = $byName();
        $this->assertSame('ON (AICAD_SUPPORTED_FACT_GENERATION_ENABLED)', $checks['supportedfacts_mode']['detail']);
        $this->assertSame('fail', $checks['programme_aliases']['status']);
        $this->assertSame('not_ready', AicadReadiness::verdict($checks->values()->all()));
        $this->assertSame('ok', $checks['feature_rollback']['status']);
        $this->assertStringStartsWith('Europe/Nicosia', $checks['campus_timezone']['detail']);

        $this->seed(AiProgrammeAliasSeeder::class);
        config(['ai.campus_timezone' => 'Mars/Olympus']);
        $checks = $byName();
        $this->assertSame('ok', $checks['programme_aliases']['status']);
        $this->assertSame('fail', $checks['campus_timezone']['status']);

        $this->artisan('ask:readiness')->assertExitCode(1);
    }

    public function test_fallback_probes_exercise_the_three_branches_without_counting_them(): void
    {
        $this->seed(AiProgrammeAliasSeeder::class);
        KnowledgeDocument::create(['id' => sha1('k'), 'url' => 'https://arucad.edu.tr/', 'domain' => 'arucad.edu.tr', 'title' => 'ARUCAD', 'language' => 'tr',
            'content' => 'ARUCAD', 'content_clean' => 'ARUCAD', 'content_folded' => 'arucad', 'content_hash' => sha1('k'), 'content_length' => 6,
            'fetched_at' => now(), 'is_stale' => false, 'document_status' => 'indexed']);
        $user = User::factory()->create(['email' => 'probe@arucad.test']);
        $this->replies(...array_fill(0, 3, 'Kütüphane hafta içi 09:00–17:00 açık. Konum: Meditation binası.'));

        $this->artisan('ask:readiness', ['--probe-fallbacks' => true, '--as' => $user->email])
            ->expectsOutputToContain('legacy compatibility fallback')
            ->assertExitCode(0);

        $this->assertSame(0, SupportedFactsRollout::aggregates()['counters']['path_errors'], 'diagnostic probes are not real traffic');
    }

    // --- Failures found with realistic phrasing (classified PLANNER) -----------------------

    public function test_realistic_phrasings_found_in_staging_preparation_are_planned(): void
    {
        $types = fn (string $q, array $h = []) => array_map(fn ($t) => $t->type, app(TaskOrchestrator::class)->run($q, $h, null)->plan?->tasks ?? []);

        // "insta" is the common short form of Instagram.
        $this->assertContains('club_social_profile', $types('kulübün insta hesabı var mı, odaları nerede', [['role' => 'user', 'content' => 'Fotoğraf kulübü ne yapıyor?']]));
        $this->assertNotContains('club_social_profile', $types('enstalasyon sergisi nerede ve kütüphane açık mı'));
        // An open place to eat, without "nearest": find → filter, no ranking.
        $this->assertSame(['find_food_places', 'filter_open_now'], $types('bugün bir şeyler yiyebileceğim açık yer var mı'));
        // One named venue keeps its own tasks.
        $this->assertSame(['opening_hours', 'current_menu'], $types('yemekhane açık mı bugün menüde ne var'));
    }

    public function test_a_question_about_another_day_gets_the_schedule_not_open_now(): void
    {
        $facts = fn (string $q) => array_map(fn ($f) => $f->factType, app(TaskOrchestrator::class)->run($q, [], null)->facts->facts());

        $this->assertContains('is_open_now', $facts('Kütüphane şu an açık mı ve nerede?'));
        foreach (['Kütüphane hafta sonu açık mı ve nerede?', 'yarın kütüphane açık olur mu, nerede', 'Is the library open on Saturday and where is it?',
            'Библиотека открыта в субботу? Где она?'] as $q) {
            $types = $facts($q);
            $this->assertContains('current_opening_hours', $types, $q);
            $this->assertNotContains('is_open_now', $types, $q.' — "now" is not what was asked');
        }
    }

    // --- Failures found in the UNCERTAIN review (classified CLAIM VERIFICATION / GENERATION) ---

    public function test_day_specific_open_closed_claims_are_checked_against_the_schedule(): void
    {
        $facts = app(TaskOrchestrator::class)->run('Kütüphane hafta sonu açık mı ve nerede?', [], null)->facts;
        $verify = fn (string $s) => app(ClaimVerifier::class)->verify($s, $facts);

        // Measured escape: weekday-only hours, answer claimed Saturdays.
        $this->assertSame('day_status', $verify('The library is open on Saturdays.')['unsupported'][0]['kind'] ?? null);
        $this->assertSame('day_status', $verify('Kütüphane cumartesi günleri açıktır.')['unsupported'][0]['kind'] ?? null);
        $this->assertSame('day_status', $verify('Библиотека работает по субботам.')['unsupported'][0]['kind'] ?? null);
        $this->assertSame([], $verify('Kütüphane hafta sonu açık değildir.')['unsupported']);
        $this->assertSame([], $verify('Kütüphane hafta içi açık.')['unsupported']);
        // Frozen Monday: tomorrow is a weekday.
        $this->assertSame([], $verify('Yarın kütüphane açık olacak.')['unsupported']);
        $this->assertNotSame([], $verify('Yarın kütüphane açık olacak.')['claims'], 'attributed to the schedule fact');
    }

    public function test_a_gap_clause_does_not_license_a_generalisation(): void
    {
        $facts = app(TaskOrchestrator::class)->run(self::PLANNED, [], null)->facts;
        $verify = fn (string $s) => app(ClaimVerifier::class)->verify($s, $facts);

        // Measured escape (sanitized): gap clause + "genellikle" in one sentence.
        $this->assertSame('speculation', $verify('Kulübün odası belirtilmemişse, genellikle etkinlikler kampüsün ortak sosyal alanlarında yapılır.')['unsupported'][0]['kind'] ?? null);
        $this->assertSame('speculation', $verify('This is not stated, but clubs usually meet in the main hall.')['unsupported'][0]['kind'] ?? null);
        // A plain gap statement is still presentation.
        $this->assertSame([], $verify('Kulübün odası kayıtlarımızda belirtilmemiş.')['unsupported']);
    }

    public function test_reversed_open_now_wording_and_fact_markup_are_handled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 15:30:00', 'UTC'));   // 18:30 campus: closed
        $facts = app(TaskOrchestrator::class)->run(self::PLANNED, [], null)->facts;
        $this->assertSame('open_now', app(ClaimVerifier::class)->verify('Библиотека — открыта сейчас.', $facts)['unsupported'][0]['kind'] ?? null);

        Carbon::setTestNow(Carbon::parse('2026-10-05 11:00:00', 'UTC'));
        $this->actingAsRole('student');
        $this->replies('[fact_t1_1] Kütüphane hafta içi 09:00–17:00 açık. Konum: Meditation binası; saatler için [fact_t1_1]\'de belirtilenlere bakabilirsin.');
        $answer = (string) $this->postJson('/api/v1/ai/query', ['prompt' => self::PLANNED])->json('data.answer');
        $this->assertStringNotContainsString('[fact_', $answer, 'internal fact ids never reach the student');
        $this->assertStringContainsString('09:00–17:00', $answer);
    }

    // --- Counters ---------------------------------------------------------------------

    public function test_uncertain_ai_unavailable_and_data_unavailable_counters_carry_no_content(): void
    {
        $this->actingAsRole('student');
        $this->replies('Kütüphane hafta içi 09:00–17:00 açık. Kütüphane öğrenciler için önemli bir yerdir. Konum: Meditation binası.');
        $this->postJson('/api/v1/ai/query', ['prompt' => self::PLANNED])->assertOk();

        $agg = SupportedFactsRollout::aggregates();
        $this->assertSame(1, $agg['counters']['responses_with_uncertain']);
        $this->assertSame(1, $agg['counters']['uncertain_sentences']);
        $this->assertSame(['tr' => 1], $agg['uncertain_by_language']);
        $this->assertSame(['opening_hours' => 1, 'location' => 1], $agg['uncertain_by_task_type']);
        $this->assertNotNull($agg['rates']['uncertain_sentence_rate']);
        $json = json_encode($agg);
        $this->assertStringNotContainsString('önemli', $json);
        $this->assertStringNotContainsString('Kütüphane', $json, 'no question or answer text in aggregates');

        // The model is down for a planned question.
        $this->nextRequest();
        Http::fake(['local.test/*' => Http::response('down', 500)]);
        $this->postJson('/api/v1/ai/query', ['prompt' => self::PLANNED])->assertOk();
        $this->assertSame(1, SupportedFactsRollout::aggregates()['counters']['ai_unavailable']);
    }

    public function test_diagnostic_runs_never_enter_the_aggregates_and_never_leak_state(): void
    {
        $user = User::factory()->create();
        $this->replies('Kütüphane hafta içi 09:00–17:00 açık. Kütüphaneler genellikle sessizdir.', 'Kütüphane hafta içi 09:00–17:00 açık. Konum: Meditation binası.');

        $first = app(AskDiagnostics::class)->run(self::PLANNED, AskDiagnostics::MODE_FULL, [], false, $user);
        $second = app(AskDiagnostics::class)->run(self::PLANNED, AskDiagnostics::MODE_FULL, [], false, $user);
        $path = fn (array $r) => collect($r['stages'])->last(fn ($s) => $s['stage'] === 'generation_path')['data'];

        $this->assertSame(1, $path($first)['speculative_claims_removed']);
        $this->assertArrayNotHasKey('speculative_claims_removed', array_filter($path($second)), 'the second run starts clean');
        $this->assertSame(0, SupportedFactsRollout::aggregates()['counters']['supported_facts_requests']);
    }
}
