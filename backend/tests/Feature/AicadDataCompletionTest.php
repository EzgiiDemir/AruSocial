<?php

namespace Tests\Feature;

use App\Filament\Resources\Clubs\Pages\EditClub;
use App\Filament\Resources\FoodVenues\Pages\EditFoodVenue;
use App\Filament\Resources\ServiceItems\Pages\EditServiceItem;
use App\Filament\Resources\Sports\Pages\EditSport;
use App\Models\AcademicYear;
use App\Models\AiEntityAlias;
use App\Models\Club;
use App\Models\FoodVenue;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeFact;
use App\Models\OpeningHour;
use App\Models\Place;
use App\Models\RoleAssignment;
use App\Models\ServiceItem;
use App\Models\Sport;
use App\Models\User;
use App\Services\Ai\AicadCoverage;
use App\Services\Ai\AskDiagnostics;
use App\Services\Ai\AskOperations;
use App\Services\Ai\DirectAnswer;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Facts\ClaimVerifier;
use App\Services\Ai\Facts\FactStatus;
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
use Tests\TestCase;

/**
 * Phase 4B: once staff fill a canonical field, AICAD answers from it — and
 * only then. Fixtures only: no real-looking campus data is invented.
 */
class AicadDataCompletionTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+90 392 000 00 01';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config(['knowledge.on_demand_enabled' => false, 'knowledge.embeddings.enabled' => false, 'ai.web_research.enabled' => false,
            'ai.task_planning.enabled' => true, 'ai.evidence.enabled' => true, 'ai.supported_facts.mode' => 'off',
            'services.routing.base_url' => null]);
        Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00'));   // Tuesday, 13:00 campus time

        foreach (['titan' => 'Titan', 'meditation' => 'Meditation'] as $id => $name) {
            Place::create(['id' => $id, 'name' => $name, 'category' => 'Academic', 'lat' => 35.338, 'lng' => 33.322,
                'description' => '', 'distance' => '', 'density' => '', 'street' => '']);
        }
        ServiceItem::create(['id' => 'student-affairs', 'title' => 'Öğrenci İşleri (Student Affairs)', 'category' => 'Administrative',
            'description' => 'Kayıt', 'contact' => 'ogrenciisleri@example.edu', 'building' => 'Titan', 'hours' => 'Hafta içi 09:00–17:00']);
        ServiceItem::create(['id' => 'library', 'title' => 'Kütüphane', 'category' => 'Academic', 'description' => 'Kitaplar',
            'contact' => 'kutuphane@example.edu', 'phone' => self::PHONE, 'building' => 'Meditation', 'hours' => 'Hafta içi 09:00–17:00']);
        ServiceItem::create(['id' => 'it', 'title' => 'Bilgi İşlem (IT)', 'category' => 'Support', 'description' => 'Destek',
            'contact' => '', 'phone' => '+90 392 000 00 02', 'building' => 'Titan', 'hours' => null]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plan(string $q, array $history = [], ?array $location = null): PlanningResult
    {
        return app(TaskOrchestrator::class)->run($q, $history, $location);
    }

    /** @return array<string, list<mixed>> fact type → values */
    private function facts(PlanningResult $r): array
    {
        return collect($r->facts->facts())->groupBy('factType')->map(fn ($f) => $f->pluck('value')->all())->all();
    }

    private function admin(): void
    {
        $user = User::firstOrCreate(['email' => 'superadmin@arucad.edu.tr'], ['name' => 'Panel', 'password' => bcrypt('x')]);
        RoleAssignment::updateOrCreate(['email' => $user->email], ['role' => 'superAdmin', 'permissions' => null, 'assigned_by' => 'test', 'assigned_at' => now()]);
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
    }

    // --- Staff can fill the canonical fields ---------------------------------------

    public function test_staff_can_enter_a_service_phone_and_nonsense_is_refused(): void
    {
        $this->admin();
        Livewire::test(EditServiceItem::class, ['record' => 'student-affairs'])
            ->fillForm(['phone' => '+90 (392) 000 00 03'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('+90 (392) 000 00 03', ServiceItem::query()->find('student-affairs')->phone, 'kept exactly as typed');

        foreach (['call us', '12-34', '+90 392 abc'] as $bad) {
            Livewire::test(EditServiceItem::class, ['record' => 'student-affairs'])
                ->fillForm(['phone' => $bad])->call('save')->assertHasFormErrors(['phone']);
        }
    }

    public function test_staff_can_link_a_venue_to_a_place_and_enter_weekly_hours(): void
    {
        $this->admin();
        FoodVenue::create(['id' => 'yemekhane', 'name' => 'Yemekhane', 'hours' => null]);

        Livewire::test(EditFoodVenue::class, ['record' => 'yemekhane'])
            ->fillForm(['place_id' => 'titan', 'openingHours' => [
                ['day_of_week' => 1, 'opens' => '08:00', 'closes' => '16:00', 'valid_from' => null, 'valid_until' => null],
                ['day_of_week' => 2, 'opens' => '08:00', 'closes' => '16:00', 'valid_from' => null, 'valid_until' => null],
            ]])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame('titan', FoodVenue::query()->find('yemekhane')->place_id);
        $rows = OpeningHour::query()->orderBy('day_of_week')->get();
        $this->assertSame([[1, 'food_venue', 'yemekhane'], [2, 'food_venue', 'yemekhane']],
            $rows->map(fn ($r) => [$r->day_of_week, $r->subject_type, $r->subject_id])->all());
        $this->assertSame('Europe/Nicosia', $rows[0]->timezone);
    }

    public function test_staff_can_enter_club_and_sport_canonical_fields(): void
    {
        $this->admin();
        Club::create(['id' => 'photo', 'name' => 'Fotoğraf Kulübü', 'category' => 'Sanat', 'description' => '']);
        Sport::create(['id' => 'basketball', 'name' => 'Basketbol Takımı', 'facility' => 'Spor Salonu']);

        Livewire::test(EditClub::class, ['record' => 'photo'])
            ->fillForm(['instagram_url' => 'not a url'])->call('save')->assertHasFormErrors(['instagram_url']);
        Livewire::test(EditClub::class, ['record' => 'photo'])
            ->fillForm(['instagram_url' => 'https://instagram.com/example_club', 'email' => 'club@example.edu', 'place_id' => 'meditation'])
            ->call('save')->assertHasNoFormErrors();
        Livewire::test(EditSport::class, ['record' => 'basketball'])
            ->fillForm(['place_id' => 'titan'])->call('save')->assertHasNoFormErrors();

        $this->assertSame(['https://instagram.com/example_club', 'club@example.edu', 'meditation'],
            array_values(Club::query()->find('photo')->only(['instagram_url', 'email', 'place_id'])));
        $this->assertSame('titan', Sport::query()->find('basketball')->place_id);
    }

    // --- Service contacts ----------------------------------------------------------

    public function test_a_service_phone_becomes_a_fact_alongside_its_email(): void
    {
        $facts = $this->facts($this->plan('kütüphane nerede ve telefonu ne?'));
        $this->assertSame([self::PHONE], $facts['contact_phone'] ?? null, 'phone and e-mail are two channels, not a conflict');
        $this->assertSame(['kutuphane@example.edu'], $facts['contact_email'] ?? null);

        $emailOnly = $this->facts($this->plan('öğrenci işleri nerede ve telefonu ne?'));
        $this->assertArrayNotHasKey('contact_phone', $emailOnly);
        $this->assertSame(['ogrenciisleri@example.edu'], $emailOnly['contact_email']);

        $phoneOnly = $this->facts($this->plan('bilgi işlem nerede ve maili ne?'));
        $this->assertSame(['+90 392 000 00 02'], $phoneOnly['contact_phone']);
        $this->assertArrayNotHasKey('contact_email', $phoneOnly);
    }

    public function test_the_contact_card_gives_the_recorded_phone_in_every_language(): void
    {
        $card = fn (string $q) => (string) app(AskOperations::class)->resolve($q)['answer'];

        $this->assertSame('Kütüphane — telefon '.self::PHONE.'; e-posta kutuphane@example.edu.', $card('Kütüphanenin telefonu ne?'));
        $this->assertSame('Library — phone '.self::PHONE.'; e-mail kutuphane@example.edu.', $card('What is the library phone number?'));
        $this->assertSame('Библиотека — телефон '.self::PHONE.'; эл. почта kutuphane@example.edu.', $card('Какой телефон у библиотеки?'));
        $this->assertStringContainsString('kayıtlı bir telefon numarası yok', $card('öğrenci işlerinin telefonu ne?'));
    }

    // --- Food venue ----------------------------------------------------------------

    private function venueWithHours(): void
    {
        FoodVenue::create(['id' => 'yemekhane', 'name' => 'Yemekhane', 'place_id' => 'titan', 'hours' => null]);
        foreach (range(1, 5) as $day) {
            OpeningHour::create(['subject_type' => 'food_venue', 'subject_id' => 'yemekhane', 'day_of_week' => $day, 'opens' => '08:00', 'closes' => '16:00']);
        }
    }

    public function test_food_questions_are_answered_once_the_venue_data_exists(): void
    {
        $answer = fn (string $q) => app(DirectAnswer::class)->tryAnswer($q, null, now());
        $this->assertNull($answer('yemekhane bugün açık mı'), 'no hours yet: nothing is stated');
        $this->venueWithHours();

        $this->assertSame('Konum: Yemekhane — Titan.', $answer('yemekhane nerede'));
        $this->assertSame('Açılış saatleri — Yemekhane: Hafta içi 08:00–16:00 — şu an açık (kampüs saatiyle 13:00).', $answer('yemekhane bugün açık mı'));
        $this->assertStringContainsString('şu an açık', $answer('yemekhane şu anda açık mı'));
        $this->assertStringContainsString('yarın açık', $answer('yemekhane yarın açık mı'));
        $this->assertStringContainsString('open now', $answer('is the cafeteria open now'));

        Carbon::setTestNow(Carbon::parse('2026-10-09 15:00:00'));   // Friday 18:00 campus time
        $this->assertStringContainsString('şu an kapalı', $answer('yemekhane şu an açık mı'));
        $this->assertStringContainsString('yarın kapalı', $answer('yemekhane yarın açık mı'), 'Saturday has no row');
    }

    public function test_an_irregular_week_is_described_day_by_day_not_simplified(): void
    {
        $this->venueWithHours();
        OpeningHour::query()->where('day_of_week', 5)->update(['closes' => '12:00']);

        $this->assertStringContainsString('Pazartesi–Perşembe 08:00–16:00; Cuma 08:00–12:00',
            app(DirectAnswer::class)->tryAnswer('yemekhane kaçta açık', null, now()));
    }

    public function test_planned_food_navigation_uses_the_canonical_place(): void
    {
        $this->venueWithHours();

        $route = $this->plan('yemekhane açık mı ve buradan yemekhaneye götür', [], ['lat' => 35.3, 'lng' => 33.3]);
        $this->assertContains('place:titan', collect($route->facts->facts())->where('factType', 'place_coordinates')->pluck('normalizedValue')->all());

        $nearest = $this->plan('Bugün açık olan en yakın yemek yerine götür.', [], ['lat' => 35.3, 'lng' => 33.3]);
        $this->assertSame(FactStatus::SUPPORTED, collect($nearest->facts->requirements)->firstWhere('factType', 'current_opening_hours')->status);
        $this->assertContains('place:titan', collect($nearest->facts->facts())->where('factType', 'place_coordinates')->pluck('normalizedValue')->all());
    }

    public function test_no_menu_is_reported_as_missing_never_inferred(): void
    {
        $this->venueWithHours();
        $r = $this->plan('bugün yemekte ne var ve yemekhane açık mı');

        $menu = collect($r->facts->requirements)->firstWhere('factType', 'current_menu');
        $this->assertNotSame(FactStatus::SUPPORTED, $menu->status);
        $this->assertSame([], $menu->facts);
    }

    // --- Clubs and sports ----------------------------------------------------------

    public function test_club_contacts_and_room_come_from_the_canonical_fields_only(): void
    {
        Club::create(['id' => 'photo', 'name' => 'Fotoğraf Kulübü', 'category' => 'Sanat', 'description' => 'Bizi izleyin: @fake_handle, fake@example.edu',
            'instagram_url' => 'https://instagram.com/example_club', 'email' => 'club@example.edu', 'place_id' => 'meditation']);
        Club::create(['id' => 'chess', 'name' => 'Satranç Kulübü', 'category' => 'Oyun', 'description' => 'İletişim: chess@example.edu']);

        $facts = $this->facts($this->plan('fotoğraf kulübünün instagramı ne ve e-postası ne?'));
        $this->assertSame(['https://instagram.com/example_club'], $facts['club_social_profile']);
        $this->assertSame(['club@example.edu'], $facts['contact_email'], 'the canonical e-mail, not the one in the description');

        $room = $this->plan('Bu kulübün instagramı ne ve kulüp odası nerede?', [['role' => 'user', 'content' => 'Fotoğraf Kulübü ne yapıyor?']]);
        $this->assertContains('place:meditation', collect($room->facts->facts())->where('factType', 'place_coordinates')->pluck('normalizedValue')->all());
        $this->assertArrayHasKey('club_social_profile', $this->facts($room));

        $empty = $this->plan('satranç kulübünün instagramı ne ve e-postası ne?');
        $this->assertSame([], $empty->facts->facts(), 'empty canonical fields are DATA_UNAVAILABLE; the description is never mined');
        $this->assertSame('UNAVAILABLE', $empty->facts->plan->outcome);
    }

    public function test_a_sports_team_is_located_only_through_its_place_link(): void
    {
        Sport::create(['id' => 'basketball', 'name' => 'Basketbol Takımı', 'facility' => 'Spor Salonu']);
        $q = 'basketbol takımı nerede ve maili ne';

        $unlinked = $this->plan($q);
        $this->assertSame([], collect($unlinked->facts->facts())->where('factType', 'place_coordinates')->all(), 'a facility name is never matched to a place');

        Sport::query()->whereKey('basketball')->update(['place_id' => 'titan']);
        Cache::flush();
        $linked = $this->plan($q);
        $this->assertContains('place:titan', collect($linked->facts->facts())->where('factType', 'place_coordinates')->pluck('normalizedValue')->all());
    }

    // --- Programme duration --------------------------------------------------------

    private function programme(string $subject, string $duration, string $language = 'İngilizce', string $page = 'a'): void
    {
        $url = "https://aday.example.edu/{$page}/".TextFold::fold($subject);
        KnowledgeDocument::create(['id' => sha1($url), 'url' => $url, 'domain' => 'aday.example.edu', 'title' => $subject, 'language' => 'tr',
            'content' => "$subject Eğitim Dili $language Eğitim Süresi $duration", 'content_clean' => "$subject Eğitim Dili $language Eğitim Süresi $duration",
            'content_folded' => TextFold::fold("$subject Eğitim Dili $language Eğitim Süresi $duration"), 'content_hash' => sha1($url),
            'content_length' => 60, 'fetched_at' => now(), 'is_stale' => false, 'document_status' => 'indexed']);
        foreach ([KnowledgeFact::LANGUAGE => $language, KnowledgeFact::DURATION => $duration] as $attribute => $value) {
            KnowledgeFact::create(['knowledge_document_id' => sha1($url), 'subject_type' => KnowledgeFact::SUBJECT_PROGRAMME, 'subject' => $subject,
                'subject_folded' => TextFold::fold($subject), 'attribute' => $attribute, 'value' => $value, 'source_url' => $url,
                'source_passage' => "$attribute $value", 'verified_at' => now()]);
        }
    }

    public function test_programme_duration_is_a_validated_fact_from_the_programme_page(): void
    {
        $this->programme('Görsel İletişim Tasarımı', '4 Yıl');
        $this->programme('İngilizce Hazırlık Okulu', '1-2 Yarıyıl');

        $r = $this->plan('Görsel İletişim Tasarımı kaç yıl ve eğitim dili ne?');
        $this->assertSame(['programme_duration', 'program_language'], array_map(fn ($t) => $t->type, $r->plan->tasks));
        $duration = collect($r->facts->facts())->firstWhere('factType', 'programme_duration');
        $this->assertSame('4y', $duration->normalizedValue);
        $this->assertSame('structured_fact', $duration->authorityClass);

        $prep = collect($this->plan('İngilizce Hazırlık Okulu kaç dönem ve hangi dilde?')->facts->facts())->firstWhere('factType', 'programme_duration');
        $this->assertSame('1-2s', $prep->normalizedValue);

        $verifier = app(ClaimVerifier::class);
        $ok = $verifier->verify('Görsel İletişim Tasarımı 4 yıllık bir lisans programıdır.', $r->facts);
        $this->assertSame(ClaimVerifier::SUPPORTED_BY_FACT, $ok['sentences'][0]['class']);
        $wrong = $verifier->verify('Görsel İletişim Tasarımı 5 yıllık bir lisans programıdır.', $r->facts);
        $this->assertSame(['programme_attribute'], $wrong['sentences'][0]['kinds']);
    }

    public function test_programme_duration_is_answered_directly_in_every_language_and_never_inferred(): void
    {
        $this->programme('Görsel İletişim Tasarımı', '4 Yıl');
        $this->programme('Mimarlık', '4 Yıl');
        AiEntityAlias::create(['entity_type' => AiEntityAlias::TYPE_PROGRAMME, 'entity_id' => 'gorsel iletisim tasarimi', 'alias' => 'Visual Communication Design', 'locale' => 'en', 'active' => true]);
        AiEntityAlias::create(['entity_type' => AiEntityAlias::TYPE_PROGRAMME, 'entity_id' => 'mimarlik', 'alias' => 'Архитектура', 'locale' => 'ru', 'active' => true]);
        $answer = fn (string $q) => app(DirectAnswer::class)->tryAnswer($q, null, now());

        $this->assertStringStartsWith('Görsel İletişim Tasarımı programının eğitim süresi 4 yıl.', $answer('Görsel İletişim Tasarımı kaç yıl?'));
        $this->assertStringStartsWith('The Visual Communication Design programme takes 4 years.', $answer('How long is the Visual Communication Design programme?'));
        $this->assertStringStartsWith('Срок обучения по программе «Архитектура» — 4 года.', $answer('Сколько лет учиться на архитектуре?'));

        // Not inferred: no stated duration, an unknown programme, or two pages that disagree.
        KnowledgeFact::query()->where('subject_folded', 'mimarlik')->where('attribute', KnowledgeFact::DURATION)->delete();
        $this->assertNull($answer('Mimarlık kaç yıl?'));
        $this->assertNull($answer('Grafik Tasarım kaç yıl?'));
        $this->programme('Görsel İletişim Tasarımı', '5 Yıl', page: 'b');
        $this->assertNull($answer('Görsel İletişim Tasarımı kaç yıl?'));
        $conflict = collect($this->plan('Görsel İletişim Tasarımı kaç yıl ve eğitim dili ne?')->facts->requirements)->firstWhere('factType', 'programme_duration');
        $this->assertSame(FactStatus::CONFLICTING, $conflict->status);
    }

    // --- Aliases -------------------------------------------------------------------

    public function test_service_aliases_seed_additively_and_resolve_short_and_russian_names(): void
    {
        ServiceItem::create(['id' => 'career', 'title' => 'Kariyer ve Mezun Ofisi', 'category' => 'Support', 'description' => '', 'contact' => 'c@example.edu', 'building' => 'Titan']);
        AiEntityAlias::create(['entity_type' => AiEntityAlias::TYPE_SERVICE, 'entity_id' => 'career', 'alias' => 'operator name', 'locale' => 'en', 'active' => true]);
        $resolve = fn (string $q) => collect(app(EntityResolver::class)->resolve($q))->where('type', 'service')->pluck('id')->all();
        $this->assertSame([], $resolve('kariyer merkezinin maili'));

        (new AiCampusAliasSeeder)->run();
        $count = AiEntityAlias::query()->count();
        (new AiCampusAliasSeeder)->run();

        $this->assertSame($count, AiEntityAlias::query()->count(), 'a rerun adds nothing');
        $this->assertSame(['operator name'], AiEntityAlias::query()->where('entity_id', 'career')->where('locale', 'en')->pluck('alias')->all(),
            'a locale an operator already manages is left alone');
        app()->forgetInstance(RequestMemo::class);
        $this->assertSame(['career'], $resolve('kariyer merkezinin maili'));
        $this->assertSame(['student-affairs'], $resolve('телефон студенческого офиса'));
    }

    // --- Stale academic year -----------------------------------------------------------

    public function test_an_ended_academic_year_never_reaches_the_prompt_as_current(): void
    {
        AcademicYear::create(['id' => 'y', 'label' => '2025-2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31', 'is_active' => true]);

        $report = app(AskDiagnostics::class)->run('akademik takvimde kulüp haftası var mı');
        $rows = implode("\n", collect($report['stages'])->firstWhere('stage', 'tool.calendar')['data']['rows'] ?? []);

        $this->assertStringContainsString('2025-2026 (SONA ERDİ — güncel yıl değil)', $rows);
        $this->assertStringNotContainsString('(aktif)', $rows);
    }

    // --- Coverage measures the data, separately from the code ------------------------

    public function test_the_gap_list_names_what_to_fill_and_shrinks_when_it_is_filled(): void
    {
        Club::create(['id' => 'photo', 'name' => 'Fotoğraf Kulübü', 'category' => 'Sanat', 'description' => '']);
        AcademicYear::create(['id' => 'y', 'label' => '2025-2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31', 'is_active' => true]);
        $coverage = app(AicadCoverage::class);

        $gaps = collect($coverage->gaps())->keyBy(fn ($g) => $g['type'].':'.$g['id']);
        $this->assertSame(['Instagram URL', 'e-mail', 'room / place'], $gaps['club:photo']['missing']);
        $this->assertSame(['phone'], $gaps['service:student-affairs']['missing']);
        $this->assertArrayNotHasKey('service:library', $gaps->all(), 'a complete office is not listed');
        $this->assertStringContainsString('still active', $gaps['academic_year:y']['missing'][0]);

        $matrix = collect($coverage->matrix())->keyBy('capability');
        $this->assertSame([4, 6], [$matrix['service_contacts']['complete'], $matrix['service_contacts']['entities']], '2 e-mails + 2 phones of 3 offices x 2 channels');
        $this->assertSame([true, 0, 1], [$matrix['club_social_profile']['implemented'], $matrix['club_social_profile']['complete'], $matrix['club_social_profile']['entities']],
            'implemented in code, empty in data');
        $this->assertSame('STALE', $matrix['academic_year_record']['status']);

        Club::query()->whereKey('photo')->update(['instagram_url' => 'https://instagram.com/x', 'email' => 'c@example.edu', 'place_id' => 'titan']);
        $this->assertArrayNotHasKey('club:photo', collect($coverage->gaps())->keyBy(fn ($g) => $g['type'].':'.$g['id'])->all());
        $this->assertSame('SUPPORTED', collect($coverage->matrix())->firstWhere('capability', 'club_social_profile')['status']);
    }
}
