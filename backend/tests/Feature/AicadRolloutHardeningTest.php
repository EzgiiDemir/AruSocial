<?php

namespace Tests\Feature;

use App\Filament\Pages\AicadHealthPage;
use App\Models\AiEntityAlias;
use App\Models\Club;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeFact;
use App\Models\Place;
use App\Models\RoleGrant;
use App\Models\ServiceItem;
use App\Services\Ai\AskTrace;
use App\Services\Ai\Facts\AnswerPlan;
use App\Services\Ai\Facts\ClaimVerifier;
use App\Services\Ai\Facts\FactPromptBlock;
use App\Services\Ai\Facts\FactStatus;
use App\Services\Ai\Facts\FactSupplement;
use App\Services\Ai\Facts\SupportedFactsRollout;
use App\Services\Ai\Planning\OpeningHours;
use App\Services\Ai\Planning\PlanningResult;
use App\Services\Ai\Planning\TaskOrchestrator;
use App\Support\TextFold;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 3C.1: rollout hardening of the SupportedFacts path — opening hours
 * vs "open now" in campus time, speculative and negative claims, claim
 * classes and coverage after removal, multilingual programme names, the
 * technical fallback and its limits, the rollout modes, trace signals and
 * aggregates. Every time-dependent test freezes the clock.
 */
class AicadRolloutHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const ACCEPTANCE = 'Öğrenci işleri bugün açık mı, hangi belgeleri götürmeliyim ve buradan nasıl giderim?';

    private AskTrace $trace;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config(['knowledge.on_demand_enabled' => false, 'knowledge.embeddings.enabled' => false, 'ai.web_research.enabled' => false,
            'ai.task_planning.enabled' => true, 'ai.evidence.enabled' => true, 'ai.supported_facts.mode' => 'on',
            'ai.campus_timezone' => 'Europe/Nicosia', 'services.routing.base_url' => null]);
        // Monday 5 October 2026, 14:00 in Kyrenia (UTC+3).
        Carbon::setTestNow(Carbon::parse('2026-10-05 11:00:00', 'UTC'));
        $this->trace = new AskTrace;
        $this->trace->setVerbose();
        $this->app->instance(AskTrace::class, $this->trace);

        Place::create(['id' => 'titan', 'name' => 'Titan', 'category' => 'Admin', 'lat' => 35.338, 'lng' => 33.322, 'description' => '', 'distance' => '', 'density' => '', 'street' => '']);
        Place::create(['id' => 'meditation', 'name' => 'Meditation', 'category' => 'Library', 'lat' => 35.337, 'lng' => 33.321, 'description' => '', 'distance' => '', 'density' => '', 'street' => '']);
        ServiceItem::create(['id' => 'student-affairs', 'title' => 'Öğrenci İşleri (Student Affairs)', 'category' => 'Administrative',
            'description' => 'Kayıt', 'contact' => 'r@x', 'building' => 'Titan', 'hours' => 'Hafta içi 09:00–17:00']);
        ServiceItem::create(['id' => 'library', 'title' => 'Kütüphane', 'category' => 'Akademik', 'description' => 'Kitap',
            'contact' => 'k@x', 'building' => 'Meditation', 'hours' => 'Hafta içi 09:00–17:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plan(string $q, array $history = []): PlanningResult
    {
        return app(TaskOrchestrator::class)->run($q, $history, null);
    }

    private function factOf(PlanningResult $r, string $type)
    {
        return collect($r->facts->facts())->firstWhere('factType', $type);
    }

    private function stage(string $name): ?array
    {
        return collect($this->trace->stages())->last(fn ($s) => $s['stage'] === $name)['data'] ?? null;
    }

    private function useModel(string ...$replies): void
    {
        config(['ai.provider' => 'local', 'ai.fallback' => '', 'ai.providers.local.base_url' => 'http://local.test/v1',
            'ai.providers.local.model' => 'm', 'ai.cache.enabled' => false, 'ai.retries' => 0]);
        $sequence = Http::sequence();
        foreach ($replies as $reply) {
            $sequence->push(['choices' => [['message' => ['content' => $reply], 'finish_reason' => 'stop']]]);
        }
        Http::fake(['local.test/*' => $sequence]);
    }

    private function document(string $url, string $title, string $body): void
    {
        KnowledgeDocument::create(['id' => sha1($url), 'url' => $url, 'domain' => 'arucad.edu.tr', 'title' => $title, 'language' => 'tr',
            'content' => $body, 'content_clean' => $body, 'content_folded' => TextFold::fold($body), 'content_hash' => sha1($body),
            'content_length' => mb_strlen($body), 'fetched_at' => now(), 'is_stale' => false, 'document_status' => 'indexed']);
    }

    private function programmeFact(string $subject, string $value, string $url): void
    {
        $this->document($url, $subject, "{$subject} Eğitim Dili {$value}");
        KnowledgeFact::create(['knowledge_document_id' => sha1($url), 'subject_type' => KnowledgeFact::SUBJECT_PROGRAMME, 'subject' => $subject,
            'subject_folded' => TextFold::fold($subject), 'attribute' => KnowledgeFact::LANGUAGE, 'value' => $value, 'source_url' => $url,
            'source_passage' => "Eğitim Dili {$value}", 'verified_at' => now()]);
    }

    // --- 1 / 15. Opening hours vs open now, in campus time --------------------------

    public function test_opening_hours_and_open_now_are_separate_facts_evaluated_in_campus_time(): void
    {
        $cases = [
            'during the interval (09:30 campus)' => ['2026-10-05 06:30:00', true],
            'before opening (08:30 campus)' => ['2026-10-05 05:30:00', false],
            'after closing (17:30 campus)' => ['2026-10-05 14:30:00', false],
            'weekday hours on a Saturday' => ['2026-10-10 09:00:00', false],
            // 21:30 UTC on Friday is already Saturday 00:30 in Kyrenia.
            'date boundary: Friday UTC, Saturday campus' => ['2026-10-09 21:30:00', false],
        ];
        foreach ($cases as $label => [$utc, $open]) {
            Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
            $r = $this->plan(self::ACCEPTANCE);

            $hours = $this->factOf($r, 'current_opening_hours');
            $this->assertSame(['opens' => '09:00', 'closes' => '17:00', 'days' => 'weekdays'], array_intersect_key($hours->value, array_flip(['opens', 'closes', 'days'])), $label);
            $now = $this->factOf($r, 'is_open_now');
            $this->assertNotNull($now, $label);
            $this->assertSame($open, $now->value, $label);
            $this->assertSame('Europe/Nicosia', $now->qualifiers['timezone'], $label);
            $this->assertNotEmpty($now->qualifiers['evaluated_at'], $label);
        }
        // The UTC clock alone would have called 08:30 campus time (05:30 UTC) and 06:30 UTC differently from campus reality.
        $this->assertNull(OpeningHours::evaluate('09:00–17:00', Carbon::parse('2026-10-05 08:00:00'))['open'], 'no day coverage');
    }

    public function test_no_day_coverage_or_unreadable_hours_create_no_open_now_fact(): void
    {
        ServiceItem::query()->whereKey('student-affairs')->update(['hours' => '09:00–17:00']);
        $r = $this->plan(self::ACCEPTANCE);
        $this->assertNotNull($this->factOf($r, 'current_opening_hours'), 'the schedule itself is still a fact');
        $this->assertNull($this->factOf($r, 'is_open_now'), 'a range without days says nothing about today');

        ServiceItem::query()->whereKey('student-affairs')->update(['hours' => 'Randevu ile']);
        $r = $this->plan(self::ACCEPTANCE);
        $this->assertNull($this->factOf($r, 'current_opening_hours'));
        $this->assertNull($this->factOf($r, 'is_open_now'));
        $this->assertSame(FactStatus::INSUFFICIENT, collect($r->facts->requirements)->firstWhere('factType', 'current_opening_hours')->status);
    }

    public function test_open_now_wording_must_match_the_is_open_now_fact(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 15:00:00', 'UTC'));   // 18:00 campus: closed
        $r = $this->plan(self::ACCEPTANCE);
        $v = app(ClaimVerifier::class);
        $kind = fn (string $s) => $v->verify($s, $r->facts)['unsupported'][0]['kind'] ?? 'supported';

        // The hours being 09:00–17:00 does not make "open now" true at 18:00.
        $this->assertSame('open_now', $kind('Öğrenci İşleri şu anda açık, hafta içi 09:00–17:00 çalışıyor.'));
        $this->assertSame('open_now', $kind('Student Affairs is currently open.'));
        $this->assertSame('open_now', $kind('Сейчас открыто.'));
        $this->assertSame('supported', $kind('Öğrenci İşleri şu an kapalı.'));
        $this->assertSame('supported', $kind('It is currently closed.'));
        $this->assertSame('supported', $kind('Сейчас закрыто.'));

        // Measured on the real model: the evaluation time echoed back is part of the open-now fact.
        $this->assertSame('supported', $kind('Öğrenci İşleri, bugün saat 18:00 itibariyle kapalı.'));
        $this->assertSame('time', $kind('Öğrenci İşleri saat 18:30 itibariyle kapalı.'));

        ServiceItem::query()->whereKey('student-affairs')->update(['hours' => '09:00–17:00']);
        $noOpenNow = $this->plan(self::ACCEPTANCE);
        $this->assertSame('open_now', $v->verify('Şu an kapalı.', $noOpenNow->facts)['unsupported'][0]['kind'], 'no is_open_now fact supports either wording');
    }

    // --- 2 / 3 / 10. Speculation, presentation, negatives -------------------------------

    public function test_claims_are_classified_and_speculation_is_unsupported(): void
    {
        $r = $this->plan(self::ACCEPTANCE);
        $classes = collect(app(ClaimVerifier::class)->verify(implode(' ', [
            'Tabii.',
            'Öğrenci İşleri hafta içi 09:00–17:00 açık.',
            'Kulüp odaları genellikle fakülte binalarında olur.',
            'Bu konuda doğrulanmış bilgi bulamıyorum.',
            'İstersen kulübün doğrulanmış konum bilgisini kontrol edebilirim.',
            'Kayıt işlemleri muhtemelen Titan binasında yapılır.',
            'Kütüphane öğrenciler için önemli bir yerdir.',
        ]), $r->facts)['sentences'])->pluck('class', 'text');

        $this->assertSame(ClaimVerifier::PRESENTATION_ONLY, $classes['Tabii.']);
        $this->assertSame(ClaimVerifier::SUPPORTED_BY_FACT, $classes['Öğrenci İşleri hafta içi 09:00–17:00 açık.']);
        $this->assertSame(ClaimVerifier::UNSUPPORTED_FACTUAL, $classes['Kulüp odaları genellikle fakülte binalarında olur.']);
        $this->assertSame(ClaimVerifier::PRESENTATION_ONLY, $classes['Bu konuda doğrulanmış bilgi bulamıyorum.']);
        $this->assertSame(ClaimVerifier::PRESENTATION_ONLY, $classes['İstersen kulübün doğrulanmış konum bilgisini kontrol edebilirim.']);
        // Naming Titan (a fact) attributes the sentence to the destination fact; the hedge does not invent a value there.
        $this->assertSame(ClaimVerifier::SUPPORTED_BY_FACT, $classes['Kayıt işlemleri muhtemelen Titan binasında yapılır.']);
        $this->assertSame(ClaimVerifier::UNCERTAIN, $classes['Kütüphane öğrenciler için önemli bir yerdir.'], 'recorded, not deleted');

        foreach (['Club rooms are usually in faculty buildings.', 'Клубы обычно находятся в здании факультета.'] as $s) {
            $this->assertSame('speculation', app(ClaimVerifier::class)->verify($s, $r->facts)['unsupported'][0]['kind'], $s);
        }
    }

    public function test_absolute_negatives_need_an_authoritative_source_and_not_found_is_said_as_such(): void
    {
        $r = $this->plan('Veteriner Hekimlik bölümü İngilizce mi ve öğrenci işleri nerede?');
        $v = app(ClaimVerifier::class);

        foreach (["ARUCAD'de Veteriner Hekimlik bölümü bulunmamaktadır.", 'ARUCAD does not offer Veterinary Medicine.', 'Такой программы нет такой в ARUCAD, она отсутствует.'] as $s) {
            $this->assertSame('negative_claim', $v->verify($s, $r->facts)['unsupported'][0]['kind'] ?? null, $s);
        }
        $this->assertSame([], $v->verify('Mevcut verilerde Veteriner Hekimlik bölümü bulunamadı.', $r->facts)['unsupported']);
        $this->assertSame([], $v->verify('Veterinary Medicine was not found in our current data.', $r->facts)['unsupported']);
        // Measured on the real model: a statement about the sources is a gap, not a denial.
        $this->assertSame([], $v->verify('Gerekli belgelerle ilgili resmi kaynaklarda açık bir bilgi bulunmuyor.', $r->facts)['unsupported']);
        $this->assertSame('negative_claim', $v->verify('Veterinary Medicine is not offered at ARUCAD.', $r->facts)['unsupported'][0]['kind']);

        $task = collect($r->facts->plan->tasks)->firstWhere('task_type', 'program_language');
        $this->assertSame(AnswerPlan::NOT_FOUND_IN_CURRENT_DATA, $task['absence']);
        $this->assertNotSame(AnswerPlan::AUTHORITATIVELY_NOT_OFFERED, $task['absence']);
        $this->assertStringContainsString('Mevcut verilerde bulunamadı', app(FactPromptBlock::class)->build($r->facts)['text']);
    }

    public function test_floor_and_room_claims_need_a_fact(): void
    {
        $r = $this->plan(self::ACCEPTANCE);
        $this->assertSame('location', app(ClaimVerifier::class)->verify('Öğrenci İşleri Titan binasının 2. katında.', $r->facts)['unsupported'][0]['kind']);
        $this->assertSame('location', app(ClaimVerifier::class)->verify('The club room is room 104.', $r->facts)['unsupported'][0]['kind']);
    }

    // --- 4. Multilingual programme names -------------------------------------------------

    public function test_programme_names_in_three_languages_resolve_to_one_canonical_programme(): void
    {
        $this->programmeFact('Görsel İletişim Tasarımı', 'İngilizce', 'https://aday.arucad.edu.tr/rt-program/gorsel-iletisim-tasarimi/');
        $this->programmeFact('Visual Communication Design', 'İngilizce', 'https://prospective.arucad.edu.tr/rt-program/visual-communication-design/');
        AiEntityAlias::create(['entity_type' => 'programme', 'entity_id' => 'gorsel iletisim tasarimi', 'alias' => 'Visual Communication Design', 'locale' => 'en']);
        AiEntityAlias::create(['entity_type' => 'programme', 'entity_id' => 'gorsel iletisim tasarimi', 'alias' => 'Визуальный Дизайн и Коммуникация', 'locale' => 'ru']);

        foreach ([
            'Görsel İletişim Tasarımı İngilizce mi ve öğrenci işleri nerede?',
            'Is Visual Communication Design taught in English and where is student affairs?',
            'Визуальный Дизайн и Коммуникация: обучение на английском? И где студенческий отдел?',
        ] as $q) {
            $r = $this->plan($q);
            $languages = collect($r->facts->facts())->where('factType', 'program_language')->values();
            $this->assertCount(1, $languages, $q.' — one fact, not one per language');
            $this->assertSame('gorsel iletisim tasarimi', $languages[0]->subject['id'], $q);
            $this->assertSame('en', $languages[0]->normalizedValue, $q);
            $this->assertGreaterThanOrEqual(2, count($languages[0]->evidenceIds), $q.' — both pages back the one fact');
        }

        // Not an ARUCAD programme name: not found, never guessed onto a near programme.
        $r = $this->plan('Is Graphic Design taught in English and where is student affairs?');
        $this->assertNull($this->factOf($r, 'program_language'));
    }

    // --- 11 / 13. Citations and coverage after removal (API) ------------------------------

    public function test_a_citation_never_outlives_the_claim_it_backed(): void
    {
        $url = 'https://arucad.edu.tr/ogrenci-isleri/kayit/';
        $this->document($url, 'Öğrenci İşleri Kayıt', 'Öğrenci İşleri kayıt için gerekli belgeler: kimlik fotokopisi, iki adet vesikalık fotoğraf ve lise diploması.');
        $this->actingAsRole('student');
        // Both drafts state the documents with an invented one, so the documents sentence is removed.
        $draft = 'Öğrenci İşleri şu an açık, hafta içi 09:00–17:00 çalışıyor. Gerekli belgeler: kimlik fotokopisi, lise diploması ve pasaport. Konumunu paylaşırsan rotayı çıkarabilirim.';
        $this->useModel($draft, $draft);

        $response = $this->postJson('/api/v1/ai/query', ['prompt' => self::ACCEPTANCE])->assertOk();

        $verification = $this->stage('claim_verification');
        $this->assertSame('removal_drops_task_coverage', $verification['regeneration_reason'], 'a document list cannot be restated by a template');
        Http::assertSentCount(2);   // the single regeneration, never a third call
        $this->assertStringNotContainsString('pasaport', (string) $response->json('data.answer'));
        $titles = array_column((array) $response->json('data.sources'), 'title');
        $this->assertNotContains('Öğrenci İşleri Kayıt', $titles, 'the documents claim was removed, so its source is not cited');
        $this->assertContains('Kampüs hizmetleri', $titles);
        $this->assertSame(0, $this->stage('generation_fact_usage')['citation_invariant_violations']);
        $this->assertLessThan($verification['coverage_generated']['covered'], $verification['coverage_after_removal']['covered']);
    }

    public function test_a_contradiction_regenerates_once_and_never_more(): void
    {
        $this->actingAsRole('student');
        // 14:00 campus: open. Both drafts say closed.
        $wrong = 'Öğrenci İşleri şu an kapalı. Hafta içi 09:00–17:00 çalışıyor. Konumunu paylaşırsan rotayı çıkarabilirim.';
        $this->useModel($wrong, $wrong, $wrong);

        $response = $this->postJson('/api/v1/ai/query', ['prompt' => self::ACCEPTANCE])->assertOk();

        Http::assertSentCount(2);
        $this->assertSame('contradicts_supported_fact', $this->stage('claim_verification')['regeneration_reason']);
        $this->assertStringNotContainsString('şu an kapalı', (string) $response->json('data.answer'));
        $this->assertStringContainsString('09:00–17:00', (string) $response->json('data.answer'));
    }

    // --- 14. Deterministic restatement ---------------------------------------------------

    public function test_restatement_uses_only_supported_fact_values_and_approved_templates(): void
    {
        $r = $this->plan('Kütüphane açık mı ve nerede?');
        $supplement = app(FactSupplement::class);

        $this->assertSame('Çalışma saatleri: Hafta içi 09:00–17:00. Şu an açık. Konum: Meditation binası.', $supplement->forSkippedTasks($r->facts, [], 'tr')['text']);
        $this->assertSame('Opening hours: Hafta içi 09:00–17:00. It is open now. Location: the Meditation building.', $supplement->forSkippedTasks($r->facts, [], 'en')['text']);
        $this->assertSame('Часы работы: Hafta içi 09:00–17:00. Сейчас открыто. Местоположение: здание Meditation.', $supplement->forSkippedTasks($r->facts, [], 'ru')['text']);
        $used = array_map(fn ($f) => $f->id, $r->facts->facts());
        $this->assertSame('', $supplement->forSkippedTasks($r->facts, $used, 'tr')['text'], 'nothing is restated when the answer used the facts');
    }

    // --- 9. Fallback safety -------------------------------------------------------------

    public function test_a_technical_failure_with_every_fact_in_hand_falls_back_to_the_legacy_path(): void
    {
        $this->app->bind(FactPromptBlock::class, fn () => throw new RuntimeException('fact block broke'));
        $this->actingAsRole('student');
        $this->useModel('Kütüphane hafta içi 09:00–17:00 açık ve Meditation binasında.');

        $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane açık mı ve nerede?'])->assertOk();

        Http::assertSentCount(1);
        $this->assertStringContainsString('SORUDAKİ GÖREVLER VE KANITLARI', Http::recorded()[0][0]->data()['messages'][0]['content']);
        $this->assertSame(SupportedFactsRollout::FALLBACK_PATH_ERROR, $this->stage('supported_facts_fallback')['reason']);
        $path = $this->stage('generation_path');
        $this->assertSame('legacy', $path['generation_path']);
        $this->assertSame(SupportedFactsRollout::FALLBACK_PATH_ERROR, $path['fallback_reason']);
        $this->assertSame(1, SupportedFactsRollout::aggregates()['counters']['path_error_legacy_fallbacks']);
    }

    public function test_a_technical_failure_with_missing_data_never_reaches_a_looser_prompt(): void
    {
        $this->app->bind(FactPromptBlock::class, fn () => throw new RuntimeException('fact block broke'));
        Club::create(['id' => 'photo', 'name' => 'Fotoğraf Kulübü', 'category' => 'Sanat', 'description' => 'Fotoğraf çekimleri yapar.']);
        $this->actingAsRole('student');
        $this->useModel('Kulübün Instagram hesabı https://instagram.com/arucadphoto adresinde.');

        $response = $this->postJson('/api/v1/ai/query', ['prompt' => 'Bu kulübün instagramı ne ve kulüp odası nerede?', 'messages' => [
            ['role' => 'user', 'content' => 'Fotoğraf Kulübü ne yapıyor?'], ['role' => 'assistant', 'content' => 'Fotoğraf çekimleri yapar.'],
            ['role' => 'user', 'content' => 'Bu kulübün instagramı ne ve kulüp odası nerede?']]])->assertOk();

        Http::assertNothingSent();
        $this->assertStringNotContainsString('instagram.com', (string) $response->json('data.answer'));
        $this->assertSame(SupportedFactsRollout::FALLBACK_PATH_ERROR, $this->stage('fallback')['cause']);
        $this->assertSame('deterministic_fallback', $this->stage('generation_path')['generation_path']);
    }

    public function test_a_verification_failure_withholds_the_unverified_answer(): void
    {
        $this->app->bind(ClaimVerifier::class, fn () => throw new RuntimeException('verifier broke'));
        $this->actingAsRole('student');
        $this->useModel('Öğrenci İşleri hafta içi 09:00–17:00 açık. Belge olarak pasaport getir.');

        $response = $this->postJson('/api/v1/ai/query', ['prompt' => self::ACCEPTANCE])->assertOk();

        $this->assertStringNotContainsString('pasaport', (string) $response->json('data.answer'));
        $this->assertSame('claim_verification', $this->stage('supported_facts_fallback')['stage']);
        $this->assertSame('FAILED', $this->stage('generation_path')['request_outcome']);
    }

    // --- 8. Rollout modes -------------------------------------------------------------------

    public function test_staff_only_mode_uses_existing_back_office_access(): void
    {
        config(['ai.supported_facts.mode' => 'staff_only']);
        $this->useModel('Kütüphane hafta içi 09:00–17:00 açık. Konum: Meditation binası.', 'Kütüphane hafta içi 09:00–17:00 açık. Konum: Meditation binası.');

        $this->actingAsRole('student');
        $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane açık mı ve nerede?'])->assertOk();
        $this->assertSame('legacy', $this->stage('generation_path')['generation_path']);
        $this->assertNotNull($this->stage('shadow_fact_check'), 'the legacy answer is shadow-checked, without a second model call');

        $this->app->forgetScopedInstances();
        $this->trace = new AskTrace;
        $this->app->instance(AskTrace::class, $this->trace);
        Filament::setCurrentPanel('admin');
        $admin = $this->actingAsRole('platformAdmin');
        RoleGrant::create(['id' => (string) Str::uuid(), 'user_id' => $admin->id, 'role' => 'platformAdmin', 'status' => 'active', 'assigned_by' => 'test']);
        $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane açık mı ve nerede?'])->assertOk();
        $this->assertSame('supported_facts', $this->stage('generation_path')['generation_path']);
        Http::assertSentCount(2);
    }

    public function test_mode_parsing_keeps_true_and_false_working(): void
    {
        foreach (['on' => 'on', 'true' => 'on', '1' => 'on', 'staff_only' => 'staff_only', 'off' => 'off', 'false' => 'off', 'nonsense' => 'off'] as $raw => $mode) {
            putenv("AICAD_SUPPORTED_FACT_GENERATION_ENABLED={$raw}");
            $config = require config_path('ai.php');
            $this->assertSame($mode, $config['supported_facts']['mode'], $raw);
        }
        putenv('AICAD_SUPPORTED_FACT_GENERATION_ENABLED');
    }

    // --- 5 / 6. Trace signals and rollout aggregates ---------------------------------------

    public function test_trace_signals_and_aggregates_are_recorded_without_content(): void
    {
        $this->actingAsRole('student');
        $this->useModel('Öğrenci İşleri şu an açık, hafta içi 09:00–17:00 çalışıyor. Kulüp odaları genellikle fakülte binalarında olur. Konumunu paylaşırsan rotayı çıkarabilirim.');

        $this->postJson('/api/v1/ai/query', ['prompt' => self::ACCEPTANCE])->assertOk();

        $path = $this->stage('generation_path');
        foreach (['supported_facts_enabled', 'generation_path', 'generation_attempts', 'regeneration_reason', 'unsupported_claims_removed',
            'final_claim_count', 'supported_claim_count', 'presentation_only_claim_count', 'request_outcome'] as $key) {
            $this->assertArrayHasKey($key, $path + ['regeneration_reason' => null], $key);
        }
        $this->assertTrue($path['supported_facts_enabled']);
        $this->assertSame(1, $path['generation_attempts']);
        $this->assertSame(1, $path['speculative_claims_removed']);
        $this->assertSame('PARTIAL', $path['request_outcome']);

        $agg = SupportedFactsRollout::aggregates();
        $this->assertSame(1, $agg['counters']['supported_facts_requests']);
        $this->assertSame(1, $agg['counters']['speculative_claims_removed']);
        $this->assertSame(1, $agg['counters']['outcome_partial']);
        $this->assertSame(1, $agg['latency_ms']['n']);
        $this->assertStringNotContainsString('Öğrenci', json_encode($agg), 'aggregates carry no content');
    }

    public function test_the_health_page_shows_the_rollout_section(): void
    {
        Filament::setCurrentPanel('admin');
        $admin = $this->actingAsRole('platformAdmin');
        RoleGrant::create(['id' => (string) Str::uuid(), 'user_id' => $admin->id, 'role' => 'platformAdmin', 'status' => 'active', 'assigned_by' => 'test']);
        app(SupportedFactsRollout::class)->bump('supported_facts_requests');

        Livewire::test(AicadHealthPage::class)->assertOk()
            ->assertSee(__('panel.aicad_health.rollout'))
            ->assertSee(__('panel.aicad_health.rollout_path_errors'));
    }
    // --- Phase 4D staging-smoke findings ----------------------------------------------

    public function test_a_plan_with_no_supported_fact_is_answered_without_the_model(): void
    {
        // 4D smoke pack: with nothing verified for any task, the model invented
        // "@arucad_photography", a reference to it, and a guessed room.
        Club::create(['id' => 'photo', 'name' => 'Fotoğraf Kulübü', 'category' => 'Sanat', 'description' => '']);
        $this->actingAsRole('student');
        $this->useModel('Kulübün resmi Instagram hesabı @arucad_photography. Kulüp odası Art Rooms binasında.');

        $response = $this->postJson('/api/v1/ai/query', ['prompt' => 'Bu kulübün instagramı ne ve kulüp odası nerede?', 'messages' => [
            ['role' => 'user', 'content' => 'Fotoğraf Kulübü ne yapıyor?'], ['role' => 'assistant', 'content' => 'Fotoğraf çekimleri yapar.'],
            ['role' => 'user', 'content' => 'Bu kulübün instagramı ne ve kulüp odası nerede?']]])->assertOk();

        Http::assertNothingSent();
        $answer = (string) $response->json('data.answer');
        $this->assertSame('deterministic', $response->json('data.aiMode'));
        $this->assertStringContainsString('mevcut verilerde bulamadım', $answer);
        $this->assertStringContainsString('kulübün resmî sosyal medya hesabı', $answer);
        $this->assertStringNotContainsString('@arucad', $answer);
        $this->assertSame([], $response->json('data.sources'));
        $this->assertSame(0, $this->stage('claim_verification')['final_unsupported']);
        $this->assertTrue($this->stage('claim_verification')['deterministic_absence']);
        $this->assertSame(1, SupportedFactsRollout::aggregates()['counters']['deterministic_absence_answers']);
    }

    public function test_a_partial_plan_still_generates_from_its_facts(): void
    {
        Club::create(['id' => 'photo', 'name' => 'Fotoğraf Kulübü', 'category' => 'Sanat', 'description' => '']);
        $this->actingAsRole('student');
        $this->useModel('Öğrenci İşleri hafta içi 09:00–17:00 açık.');

        $this->postJson('/api/v1/ai/query', ['prompt' => 'Öğrenci işleri ne zaman açık ve fotoğraf kulübünün instagramı ne?'])->assertOk();

        Http::assertSentCount(1);
        $this->assertSame(0, SupportedFactsRollout::aggregates()['counters']['deterministic_absence_answers']);
    }

    public function test_a_club_named_like_a_document_is_not_a_document_claim_and_foreign_script_is_caught(): void
    {
        Club::create(['id' => 'photo', 'name' => 'Fotoğraf Kulübü', 'category' => 'Sanat', 'description' => '']);
        $facts = $this->plan('Bu kulübün instagramı ne ve kulüp odası nerede?', [['role' => 'user', 'content' => 'Fotoğraf Kulübü ne yapıyor?']])->facts;
        $verifier = app(ClaimVerifier::class);

        $gap = $verifier->verify('Fotoğraf kulübünün resmî Instagram hesabı ve oda konumu mevcut verilerde bulunamadı.', $facts);
        $this->assertSame([], $gap['unsupported'], 'an honest gap about the Fotoğraf Kulübü is not a "photo" document claim');
        $this->assertSame(['script'], $verifier->verify('Öğrenciler 摄影作品 paylaşabilir.', $facts)['sentences'][0]['kinds']);

        // Where documents ARE asked, the document check still applies.
        $documents = $this->plan('Öğrenci işleri bugün açık mı, hangi belgeleri götürmeliyim?')->facts;
        $this->assertContains('document', $verifier->verify('Kayıt için iki fotoğraf getirmelisin.', $documents)['sentences'][0]['kinds']);
    }
}
