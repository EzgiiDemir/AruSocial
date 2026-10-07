<?php

namespace Tests\Feature;

use App\Filament\Resources\AcademicYears\Pages\CreateAcademicYear;
use App\Filament\Resources\Clubs\Pages\EditClub;
use App\Filament\Resources\FoodVenues\Pages\EditFoodVenue;
use App\Filament\Resources\ServiceItems\Pages\EditServiceItem;
use App\Filament\Resources\Sports\Pages\EditSport;
use App\Models\AcademicYear;
use App\Models\AiEntityAlias;
use App\Models\Club;
use App\Models\FoodVenue;
use App\Models\KnowledgeDocument;
use App\Models\OpeningHour;
use App\Models\Place;
use App\Models\RoleAssignment;
use App\Models\ServiceItem;
use App\Models\Sport;
use App\Models\User;
use App\Services\Ai\AcademicCalendar;
use App\Services\Ai\AicadCoverage;
use App\Services\Ai\AicadHealth;
use App\Services\Ai\DirectAnswer;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Facts\ClaimVerifier;
use App\Services\Ai\Facts\FactSupplement;
use App\Services\Ai\Planning\PlanningResult;
use App\Services\Ai\Planning\TaskOrchestrator;
use App\Support\RequestMemo;
use App\Support\TextFold;
use Database\Seeders\AiCampusAliasSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 4C: planner phrasing concepts, typed conversation references, sports
 * short names, the academic-year screen, and staff edits reaching AICAD on
 * the very next question.
 */
class AicadCoverageClosureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config(['knowledge.on_demand_enabled' => false, 'knowledge.embeddings.enabled' => false, 'ai.web_research.enabled' => false,
            'ai.task_planning.enabled' => true, 'ai.evidence.enabled' => true, 'ai.supported_facts.mode' => 'off',
            'services.routing.base_url' => null]);
        Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00'));   // Tuesday, 13:00 campus time

        Place::create(['id' => 'titan', 'name' => 'Titan', 'category' => 'Academic', 'lat' => 35.338, 'lng' => 33.322,
            'description' => '', 'distance' => '', 'density' => '', 'street' => '']);
        ServiceItem::create(['id' => 'student-affairs', 'title' => 'Öğrenci İşleri (Student Affairs)', 'category' => 'Administrative',
            'description' => 'Kayıt', 'contact' => 'ogrenciisleri@example.edu', 'building' => 'Titan', 'hours' => 'Hafta içi 09:00–17:00']);
        Club::create(['id' => 'club-photography', 'name' => 'Fotoğraf Kulübü', 'category' => 'Sanat', 'description' => '']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Each call is its own request: nothing memoised from the previous one. */
    private function plan(string $q, array $history = [], ?array $location = null): PlanningResult
    {
        app()->forgetInstance(RequestMemo::class);

        return app(TaskOrchestrator::class)->run($q, $history, $location);
    }

    private function types(PlanningResult $r): array
    {
        return array_map(fn ($t) => $t->type, $r->plan?->tasks ?? []);
    }

    private function admin(): void
    {
        $user = User::firstOrCreate(['email' => 'superadmin@arucad.edu.tr'], ['name' => 'Panel', 'password' => bcrypt('x')]);
        RoleAssignment::updateOrCreate(['email' => $user->email], ['role' => 'superAdmin', 'permissions' => null, 'assigned_by' => 'test', 'assigned_at' => now()]);
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
    }

    // --- Planner: "is there any open place to eat" -----------------------------------

    /** @return array<string, array{0: string}> */
    public static function openFoodPlace(): array
    {
        return [
            'tr' => ['açık yemek yeri var mı'],
            'tr, no diacritics' => ['acik yemek yeri var mi'],
            'tr, can I eat somewhere open' => ['bugün açık bir yerde yemek yiyebilir miyim'],
            'en' => ['is there anywhere open to eat'],
            'en, any cafe' => ['any cafe open now'],
            'ru, open cafe' => ['есть открытое кафе'],
            'ru, where can I eat now' => ['где сейчас можно поесть'],
        ];
    }

    #[DataProvider('openFoodPlace')]
    public function test_asking_for_any_open_place_to_eat_starts_the_food_chain(string $q): void
    {
        $this->assertSame(['find_food_places', 'filter_open_now'], $this->types($this->plan($q)));
    }

    public function test_one_named_venue_keeps_its_own_task(): void
    {
        foreach (['yemekhane açık mı', 'yemekhane şu an açık mı', 'is the library open now'] as $q) {
            $this->assertSame(['opening_hours'], $this->types($this->plan($q)), $q);
        }
        $this->assertNotContains('find_food_places', $this->types($this->plan('можно ли записаться в клуб')), '"можно" alone is not about food');
    }

    // --- Typed conversation references -------------------------------------------------

    public function test_a_bare_club_noun_refers_to_the_club_named_before(): void
    {
        $history = [['role' => 'user', 'content' => 'Fotoğraf Kulübü hakkında bilgi ver']];
        foreach (['kulübün instagramı ne ve odası nerede?', 'kulubun instagrami ne ve kulube nasil giderim', 'Bu kulübün instagramı ne ve kulüp odası nerede?'] as $q) {
            $r = $this->plan($q, $history);
            $this->assertSame(['club:club-photography', 'club:club-photography'], array_column($r->finalEntities, 'final'), $q);
        }
        $this->assertSame('Fotoğraf Kulübü hakkında bilgi ver', $history[0]['content'], 'the conversation is never rewritten');
    }

    public function test_a_typed_reference_never_binds_to_another_kind_of_entity(): void
    {
        $r = $this->plan('kulübün instagramı ne ve odası nerede?', [['role' => 'user', 'content' => 'öğrenci işleri nerede']]);

        $this->assertSame([], $r->finalEntities, 'an office is not "the club"');
        $this->assertSame('UNAVAILABLE', $r->facts->plan->outcome);
    }

    public function test_official_english_and_turkish_club_names_resolve_through_the_seeded_aliases(): void
    {
        (new AiCampusAliasSeeder)->run();

        foreach (['Tell me about the photography club', 'Fotoğrafçılık kulübünü göster'] as $prev) {
            $r = $this->plan("what is the club's instagram and where is its room", [['role' => 'user', 'content' => $prev]]);
            $this->assertSame(['club:club-photography', 'club:club-photography'], array_column($r->finalEntities, 'final'), $prev);
        }
        $this->assertSame(['photography club'], AiEntityAlias::query()->where('entity_id', 'club-photography')->where('locale', 'en')->pluck('alias')->all());
    }

    // --- Sports short names ------------------------------------------------------------

    public function test_sports_teams_resolve_by_the_short_forms_of_their_own_names(): void
    {
        Sport::create(['id' => 'sport-basketball', 'name' => 'ARUCAD Basketbol Takımı (Erkek)', 'facility' => 'Spor Salonu']);
        Sport::create(['id' => 'sport-table-tennis', 'name' => 'ARUCAD Masa Tenisi (Erkek ve Kadın)', 'facility' => 'Spor Salonu']);
        Sport::create(['id' => 'sport-tennis', 'name' => 'Tenis (Girne Belediyesi Kortları)', 'facility' => 'Tenis Kortu']);
        $resolve = function (string $q) {
            app()->forgetInstance(RequestMemo::class);

            return collect(app(EntityResolver::class)->resolve($q))->map(fn ($e) => $e['type'].':'.$e['id'])->all();
        };

        $this->assertSame(['basketbol takimi', 'basketbol'], EntityResolver::sportShortNames(TextFold::fold('ARUCAD Basketbol Takımı (Erkek)')));
        $this->assertSame(['sport:sport-basketball'], $resolve('basketbol takımı nerede'));
        $this->assertSame(['sport:sport-table-tennis'], $resolve('masa tenisi nerede oynanıyor'), 'not also "tenis"');
        $this->assertSame(['sport:sport-tennis'], $resolve('tenis nerede'));

        // A facility name is never matched to a place by itself…
        $this->assertSame([], $resolve('spor salonu nerede'));
        // …only through the place staff linked its teams to.
        Sport::query()->update(['place_id' => 'titan']);
        $this->assertContains('place:titan', $resolve('spor salonu nerede'));
        Sport::query()->whereKey('sport-table-tennis')->update(['place_id' => null]);
        $this->assertNotContains('place:titan', $resolve('spor salonu nerede'), 'teams that disagree name no place');
    }

    // --- Staff edits reach AICAD on the next question ---------------------------------

    public function test_staff_edits_in_the_admin_forms_reach_aicad_immediately(): void
    {
        $this->admin();
        FoodVenue::create(['id' => 'yemekhane', 'name' => 'Yemekhane', 'hours' => null]);
        Sport::create(['id' => 'sport-basketball', 'name' => 'ARUCAD Basketbol Takımı (Erkek)', 'facility' => 'Spor Salonu']);
        $facts = fn (PlanningResult $r, string $type) => collect($r->facts->facts())->where('factType', $type)->pluck('normalizedValue')->all();

        $this->assertSame([], $facts($this->plan('öğrenci işleri nerede ve telefonu ne?'), 'contact_phone'));
        Livewire::test(EditServiceItem::class, ['record' => 'student-affairs'])->fillForm(['phone' => '+90 392 000 00 09'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(['903920000009'], $facts($this->plan('öğrenci işleri nerede ve telefonu ne?'), 'contact_phone'));

        $chain = 'Bugün açık olan en yakın yemek yerine götür.';
        $this->assertSame([], $facts($this->plan($chain, [], ['lat' => 35.3, 'lng' => 33.3]), 'is_open_now'));
        Livewire::test(EditFoodVenue::class, ['record' => 'yemekhane'])->fillForm(['place_id' => 'titan', 'openingHours' => array_map(
            fn ($d) => ['day_of_week' => $d, 'opens' => '08:00', 'closes' => '16:00', 'valid_from' => null, 'valid_until' => null], range(1, 5))])
            ->call('save')->assertHasNoFormErrors();
        $after = $this->plan($chain, [], ['lat' => 35.3, 'lng' => 33.3]);
        $this->assertSame(['08:00-16:00'], $facts($after, 'current_opening_hours'));
        $this->assertSame(['true'], array_map(fn ($v) => var_export($v, true), collect($after->facts->facts())->where('factType', 'is_open_now')->pluck('value')->all()));
        $this->assertContains('place:titan', $facts($after, 'place_coordinates'), 'navigation now has its destination');

        $this->assertSame([], $facts($this->plan('fotoğraf kulübünün instagramı ne ve maili ne?'), 'club_social_profile'));
        Livewire::test(EditClub::class, ['record' => 'club-photography'])->fillForm(['instagram_url' => 'https://instagram.com/example_club'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(['https://instagram.com/example_club'], $facts($this->plan('fotoğraf kulübünün instagramı ne ve maili ne?'), 'club_social_profile'));

        $this->assertSame([], $facts($this->plan('basketbol takımı nerede ve maili ne?'), 'place_coordinates'));
        Livewire::test(EditSport::class, ['record' => 'sport-basketball'])->fillForm(['place_id' => 'titan'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(['place:titan'], $facts($this->plan('basketbol takımı nerede ve maili ne?'), 'place_coordinates'));
    }

    // --- Academic year ------------------------------------------------------------------

    public function test_staff_can_add_the_current_year_and_the_stale_one_is_closed(): void
    {
        $this->admin();
        AcademicYear::create(['id' => 'y-old', 'label' => '2025-2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31', 'is_active' => true]);
        $this->assertSame('STALE', collect(app(AicadCoverage::class)->matrix())->firstWhere('capability', 'academic_year_record')['status']);
        $this->assertContains('calendar', array_column(app(AicadHealth::class)->dataWarnings(), 'area'));

        Livewire::test(CreateAcademicYear::class)->fillForm(['label' => '2026–2027 bad', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31'])
            ->call('create')->assertHasFormErrors(['label']);
        Livewire::test(CreateAcademicYear::class)->fillForm(['label' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31', 'is_active' => true])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame(['2026-2027'], AcademicYear::query()->where('is_active', true)->pluck('label')->all(), 'only one year is active');
        $this->assertFalse(AcademicYear::query()->find('y-old')->is_active, 'the old row is closed, not deleted');
        $this->assertSame('SUPPORTED', collect(app(AicadCoverage::class)->matrix())->firstWhere('capability', 'academic_year_record')['status']);
        $this->assertNotContains('academic_year', array_column(app(AicadCoverage::class)->gaps(), 'type'));
    }

    public function test_term_dates_never_come_from_the_academic_year_row(): void
    {
        AcademicYear::create(['id' => 'y-old', 'label' => '2025-2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31', 'is_active' => true]);
        $answer = fn () => app(DirectAnswer::class)->tryAnswer('dersler ne zaman başlıyor', null, now());
        $this->assertNull($answer(), 'no official calendar indexed: no date is taken from the stale row');

        $body = 'Lisans Akademik Takvim 2026-2027 GÜZ DÖNEMİ Ekim 5 2026 Ders Başlangıcı BAHAR DÖNEMİ Şubat 22 2027 Ders Başlangıcı';
        $url = AcademicCalendar::SOURCES[0];
        KnowledgeDocument::create(['id' => sha1($url), 'url' => $url, 'domain' => 'arucad.edu.tr', 'title' => 'Lisans Akademik Takvim', 'language' => 'tr',
            'content' => $body, 'content_clean' => $body, 'content_folded' => TextFold::fold($body), 'content_hash' => sha1($body), 'content_length' => mb_strlen($body),
            'fetched_at' => now(), 'is_stale' => false, 'document_status' => 'indexed']);
        $this->assertStringContainsString('22 Şubat 2027', $answer());
        $this->assertStringNotContainsString('2025', $answer());
    }

    // --- Phase 4D: an unqualified "open" is an open-now claim ----------------------------

    public function test_an_unqualified_open_claim_needs_the_open_now_fact(): void
    {
        // Found by the 4D controller smoke pack: "GARDEN MENÜ adlı açık yemek yeri
        // mevcuttur" passed on the venue-name fact while no venue had hours.
        FoodVenue::create(['id' => 'garden', 'name' => 'Kampüs Kafe', 'hours' => null]);
        $facts = $this->plan('açık yemek yeri var mı')->facts;
        $verifier = app(ClaimVerifier::class);
        $class = fn (string $s) => $verifier->verify($s, $facts)['sentences'][0];

        $this->assertSame(['open_now'], $class('Evet, kampüste Kampüs Kafe adlı açık bir yemek yeri var.')['kinds']);
        $this->assertSame(['open_now'], $class('Kampüs Kafe is open.')['kinds']);
        $this->assertNotSame(ClaimVerifier::UNSUPPORTED_FACTUAL, $class('Kampüs Kafe kayıtlı bir yemek yeri; açık olup olmadığı bilinmiyor.')['class'],
            'saying it is unknown whether it is open is not a claim');

        // With hours and an open-now fact, the same claim is supported.
        foreach (range(1, 5) as $d) {
            OpeningHour::create(['subject_type' => 'food_venue', 'subject_id' => 'garden', 'day_of_week' => $d, 'opens' => '08:00', 'closes' => '16:00']);
        }
        $facts = $this->plan('açık yemek yeri var mı')->facts;
        $this->assertSame([], $verifier->verify('Evet, Kampüs Kafe açık.', $facts)['unsupported'], 'Tuesday 13:00: open now is a fact');

        // Outside an open-status question "açık" is not a status claim.
        $other = $this->plan('kütüphane nerede ve maili ne?')->facts;
        $this->assertSame([], $verifier->verify('Kütüphane tüm öğrencilere açık bir çalışma alanıdır.', $other)['unsupported']);
    }

    public function test_a_partial_answer_names_what_was_not_found_and_restates_the_venue(): void
    {
        FoodVenue::create(['id' => 'garden', 'name' => 'Kampüs Kafe', 'hours' => null]);
        $facts = $this->plan('açık yemek yeri var mı')->facts;
        $supplement = app(FactSupplement::class);

        $this->assertSame('Kayıtlı yemek yeri: Kampüs Kafe.', $supplement->forSkippedTasks($facts, [], 'tr')['text']);
        $this->assertSame('Mevcut verilerde bulunamadı: yerlerin açık olup olmadığı.',
            $supplement->forUnstatedGaps($facts, 'Kayıtlı yemek yeri: Kampüs Kafe.', 'tr'));
        $this->assertSame("Not found in the current data: the places' current opening status.", $supplement->forUnstatedGaps($facts, 'Kampüs Kafe.', 'en'));
        $this->assertSame('', $supplement->forUnstatedGaps($facts, 'Açılış saatleri mevcut verilerde bulunamadı.', 'tr'), 'the answer already says so');
    }

    // --- Staging fixtures ----------------------------------------------------------------

    public function test_staging_fixtures_are_labelled_separate_rows_and_never_run_in_production(): void
    {
        $this->artisan('aicad:staging-fixtures')->assertSuccessful();
        $this->artisan('aicad:staging-fixtures')->assertSuccessful();   // idempotent

        $this->assertSame(['[FIXTURE] Test Office'], ServiceItem::query()->where('id', 'like', 'fixture-%')->pluck('title')->all());
        $this->assertNull(ServiceItem::query()->find('student-affairs')->phone, 'no real record is touched');
        $this->assertSame(5, OpeningHour::query()->where('subject_id', 'fixture-cafe')->count());
        $this->assertStringEndsWith('@example.edu', (string) Club::query()->find('fixture-club')->email);

        $this->artisan('aicad:staging-fixtures', ['--remove' => true])->assertSuccessful();
        $this->assertSame(0, ServiceItem::withTrashed()->where('id', 'like', 'fixture-%')->count() + Place::withTrashed()->where('id', 'like', 'fixture-%')->count());
        $this->assertNotNull(ServiceItem::query()->find('student-affairs'));

        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('aicad:staging-fixtures')->assertFailed();
        $this->assertSame(0, Place::withTrashed()->where('id', 'like', 'fixture-%')->count());
    }

    // --- Coverage links lead to the existing edit screens --------------------------------

    public function test_every_actionable_gap_links_to_its_existing_edit_screen(): void
    {
        $this->admin();
        FoodVenue::create(['id' => 'yemekhane', 'name' => 'Yemekhane', 'hours' => null]);
        Sport::create(['id' => 'sport-basketball', 'name' => 'ARUCAD Basketbol Takımı (Erkek)', 'facility' => 'Spor Salonu']);
        AcademicYear::create(['id' => 'y-old', 'label' => '2025-2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31', 'is_active' => true]);

        $gaps = collect(app(AicadCoverage::class)->gaps())->keyBy('type');
        foreach (['service' => '/admin/service-items/student-affairs/edit', 'food_venue' => '/admin/food-venues/yemekhane/edit', 'club' => '/admin/clubs/club-photography/edit',
            'sport' => '/admin/sports/sport-basketball/edit', 'academic_year' => '/admin/academic-years/y-old/edit'] as $type => $path) {
            $gap = $gaps[$type];
            $this->assertNotNull($gap['resource'], "$type has an edit screen");
            $this->assertStringEndsWith($path, $gap['resource']::getUrl('edit', ['record' => $gap['id']]));
        }
        $this->assertSame(['phone'], $gaps['service']['missing']);
        $this->assertSame(['campus place', 'weekly opening hours', 'daily menu (today or later)'], $gaps['food_venue']['missing']);
        $this->assertSame(['Instagram URL', 'e-mail', 'room / place'], $gaps['club']['missing']);

        $checklist = collect(app(AicadCoverage::class)->checklist());
        $this->assertSame(['A', 'A', 'A', 'A', 'B', 'B', 'B', 'B', 'C', 'C', 'C'], $checklist->pluck('priority')->all());
        $this->assertSame([0, 1, 1], [$checklist[0]['complete'], $checklist[0]['total'], $checklist[0]['missing']], 'office phones');
    }
}
