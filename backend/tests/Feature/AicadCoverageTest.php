<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\FoodVenue;
use App\Models\KnowledgeDocument;
use App\Models\OpeningHour;
use App\Models\Place;
use App\Models\ServiceItem;
use App\Models\Translation;
use App\Models\TranslationKey;
use App\Services\Ai\AcademicCalendar;
use App\Services\Ai\AicadCoverage;
use App\Services\Ai\AppNavigation;
use App\Services\Ai\AskOperations;
use App\Services\Ai\DirectAnswer;
use App\Services\Ai\Facts\ClaimVerifier;
use App\Services\Ai\Facts\FactStatus;
use App\Services\Ai\Planning\PlanningResult;
use App\Services\Ai\Planning\TaskExecutor;
use App\Services\Ai\Planning\TaskOrchestrator;
use App\Support\TextFold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 4A: canonical coverage — academic dates from the official calendar,
 * contact details from canonical records, trusted app navigation, and the
 * factual categories the verifier never leaves "uncertain".
 */
class AicadCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** The official calendar page's layout: "<Month> <day>[-<day>] <year> <event>" under term headings. */
    private const CALENDAR = 'Lisans Akademik Takvim - ARUCAD 2026-2027 Güz Dönemi 2026-2027 Bahar Dönemi GÜZ DÖNEMİ '
        .'Haziran 10 2026 Önceki Yılın Yaz Okulu Sınavları '
        .'Eylül 16-18 2026 Çevrimiçi Ders Kayıtları Başlangıcı Ekim 1-2 2026 Oryantasyon '
        .'Ekim 5 2026 Ders Başlangıcı Geç Kayıt Başlangıcı (Cezalı) Ekim 23 2026 Ders Ekleme – Bırakma için Son Gün '
        .'Ocak 13-20 2027 Güz Dönemi Dönem Sonu Sınavları BAHAR DÖNEMİ Şubat 22 2027 Ders Başlangıcı Mayıs 20 2027 Son Ders Günü';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config(['knowledge.on_demand_enabled' => false, 'knowledge.embeddings.enabled' => false, 'ai.web_research.enabled' => false,
            'ai.task_planning.enabled' => true, 'ai.evidence.enabled' => true, 'ai.supported_facts.mode' => 'off',
            'services.routing.base_url' => null]);
        Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00'));   // the day after the fall term began

        $url = AcademicCalendar::SOURCES[0];
        KnowledgeDocument::create(['id' => sha1($url), 'url' => $url, 'domain' => 'arucad.edu.tr', 'title' => 'Lisans Akademik Takvim - ARUCAD',
            'language' => 'tr', 'content' => self::CALENDAR, 'content_clean' => self::CALENDAR, 'content_folded' => TextFold::fold(self::CALENDAR),
            'content_hash' => sha1(self::CALENDAR), 'content_length' => mb_strlen(self::CALENDAR), 'fetched_at' => now(), 'is_stale' => false,
            'document_status' => 'indexed']);
        Place::create(['id' => 'meditation', 'name' => 'Meditation', 'category' => 'Academic', 'lat' => 35.338, 'lng' => 33.322,
            'description' => '', 'distance' => '', 'density' => '', 'street' => '']);
        ServiceItem::create(['id' => 'library', 'title' => 'Kütüphane', 'category' => 'Academic', 'description' => 'Kitaplar',
            'contact' => 'kutuphane@arucad.edu.tr', 'building' => 'Meditation', 'hours' => 'Hafta içi 09:00–17:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plan(string $q): PlanningResult
    {
        return app(TaskOrchestrator::class)->run($q, [], null);
    }

    private function answer(string $q): ?string
    {
        return app(DirectAnswer::class)->tryAnswer($q, null, now());
    }

    // --- The official academic calendar -------------------------------------------

    public function test_the_calendar_is_parsed_deterministically_by_term_and_kind(): void
    {
        $calendar = app(AcademicCalendar::class)->current();

        $this->assertSame('2026-2027', $calendar['academic_year']);
        $this->assertFalse($calendar['stale']);
        $starts = app(AcademicCalendar::class)->ofKind($calendar, 'classes_start');
        $this->assertSame([['fall', '2026-10-05'], ['spring', '2027-02-22']], array_map(fn ($e) => [$e['term'], $e['start']], $starts['entries']));
        $this->assertSame('2027-02-22', $starts['next']['start'], 'the fall start is past');
        $this->assertSame('2026-10-05', $starts['recent']['start'], 'a term that began yesterday is still part of the answer');
        $finals = app(AcademicCalendar::class)->ofKind($calendar, 'final_exams')['next'];
        $this->assertSame(['2027-01-13', '2027-01-20'], [$finals['start'], $finals['end']]);
        $this->assertSame('2026-10-23', app(AcademicCalendar::class)->ofKind($calendar, 'add_drop_deadline')['next']['start']);
        $this->assertFalse(collect($calendar['entries'])->contains(fn ($e) => str_starts_with($e['start'], '2026-06')),
            'a previous year\'s row is not this year\'s date');
    }

    public function test_academic_dates_are_answered_in_every_language_without_the_model(): void
    {
        $tr = $this->answer('dersler ne zaman başlıyor');
        $this->assertStringContainsString('22 Şubat 2027', $tr);
        $this->assertStringContainsString('5 Ekim 2026', $tr, 'the term that just began is mentioned');
        $this->assertStringContainsString('22 February 2027', $this->answer('when does the semester start'));
        $this->assertStringContainsString('22 февраля 2027', $this->answer('когда начинаются занятия'));
        $this->assertStringContainsString('13 Ocak 2027 – 20 Ocak 2027', $this->answer('final sınavları ne zaman'));
        $this->assertStringContainsString('23 October 2026', $this->answer('when is the add drop deadline'));
    }

    public function test_calendar_wording_about_other_things_is_not_a_calendar_answer(): void
    {
        $this->assertNull($this->answer('Kulüp etkinlikleri ne zaman başlıyor?'));
        $this->assertNull($this->answer('yurt başvurusu ne zaman başlıyor'));
    }

    public function test_an_ended_calendar_is_stale_and_never_presented_as_current(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-09-15 10:00:00'));

        $this->assertTrue(app(AcademicCalendar::class)->current()['stale']);
        $answer = $this->answer('dersler ne zaman başlıyor');
        $this->assertStringContainsString('2026–2027', $answer);
        $this->assertStringContainsString('henüz yayımlanmadı', $answer);
        $this->assertStringNotContainsString('22 Şubat', $answer);
        $this->assertStringContainsString("the current year's dates are not available yet", $this->answer('when does the semester start'));

        $r = $this->plan('dersler ne zaman başlıyor ve kütüphanenin maili ne?');
        $dates = collect($r->facts->requirements)->firstWhere('factType', 'academic_date');
        $this->assertNotSame(FactStatus::SUPPORTED, $dates->status, 'stale evidence is not a current fact');
        $this->assertSame([], $dates->facts);
    }

    // --- Contacts -------------------------------------------------------------------

    public function test_contact_details_come_verbatim_from_the_canonical_record(): void
    {
        $card = fn (string $q) => (string) app(AskOperations::class)->resolve($q)['answer'];

        $this->assertSame('Kütüphane — e-posta kutuphane@arucad.edu.tr. Bu birim için kayıtlı bir telefon numarası yok.', $card('Kütüphanenin telefonu ne?'));
        $this->assertSame('Kütüphane — e-posta kutuphane@arucad.edu.tr. Bu birim için kayıtlı bir telefon numarası yok.', $card('kütüphaneyi nasıl arayabilirim'));
        $this->assertSame('Library — e-mail kutuphane@arucad.edu.tr.', $card('What is the library email?'));
        $this->assertSame('Библиотека — эл. почта kutuphane@arucad.edu.tr.', $card('Как связаться с библиотекой?'));
        $this->assertStringContainsString('Meditation', $card('Kütüphane nerede?'), 'a location question keeps the location card');
        $this->assertSame('Kütüphane — e-posta kutuphane@arucad.edu.tr. Bu birim için kayıtlı bir sorumlu kişi adı yok.',
            $card('kütüphane müdürü kim ve kütüphanenin maili ne'), 'the person part is a stated gap, never a name');

        ServiceItem::query()->whereKey('library')->update(['contact' => 'Tel: +90 392 000 00 00, kutuphane@arucad.edu.tr']);
        $this->assertSame('Kütüphane — telefon +90 392 000 00 00; e-posta kutuphane@arucad.edu.tr.', $card('Kütüphanenin telefonu ne?'));
    }

    public function test_planned_dates_and_contacts_become_supported_facts(): void
    {
        $r = $this->plan('dersler ne zaman başlıyor ve kütüphanenin maili ne?');

        $this->assertSame(['academic_dates', 'contact_details'], array_column(array_map(fn ($t) => (array) $t, $r->plan->tasks), 'type'));
        $date = collect($r->facts->requirements)->firstWhere('factType', 'academic_date');
        $this->assertSame(FactStatus::SUPPORTED, $date->status);
        $fact = $date->facts[0];
        $this->assertSame('classes_start:2027-02-22..2027-02-22', $fact->normalizedValue);
        $this->assertSame('2026-10-05', $fact->value['recent']['start'], 'the term that just began is context, not a conflicting value');
        $this->assertSame(AcademicCalendar::SOURCES[0], $fact->provenance[0]['url']);
        $this->assertStringContainsString('Bahar dönemi', $fact->display());

        $contact = collect($r->facts->requirements)->firstWhere('factType', 'contact_details');
        $this->assertSame(FactStatus::SUPPORTED, $contact->status);
        $this->assertSame(['contact_email' => 'kutuphane@arucad.edu.tr'], collect($contact->facts)->mapWithKeys(fn ($f) => [$f->factType => $f->value])->all());
    }

    public function test_a_record_without_a_phone_yields_no_phone_fact(): void
    {
        $r = $this->plan('dersler ne zaman başlıyor ve kütüphanenin telefonu ne?');

        $contact = collect($r->facts->requirements)->firstWhere('factType', 'contact_details');
        $this->assertFalse(collect($contact->facts)->contains(fn ($f) => $f->factType === 'contact_phone'));
    }

    // --- Factual categories are never "uncertain" -----------------------------------

    /** @return array<string, array{0: string, 1: ?string}> sentence → the unsupported kind, or null when supported */
    public static function factualSentences(): array
    {
        return [
            'supported date' => ['Bahar dönemi dersleri 22 Şubat 2027 tarihinde başlar.', null],
            'supported recent term' => ['Güz dönemi dersleri 5 Ekim 2026 tarihinde başladı.', null],
            'supported date, numeric form' => ['Dersler 05.10.2026 tarihinde başladı.', null],
            'supported date, russian' => ['Занятия начинаются 22 февраля 2027 года.', null],
            'invented date' => ['Bahar dönemi dersleri 1 Mart 2027 tarihinde başlar.', 'date'],
            'invented date, english' => ['Classes start on March 1.', 'date'],
            'supported email' => ['Kütüphanenin e-postası kutuphane@arucad.edu.tr adresidir.', null],
            'invented email' => ['Kütüphaneye bilgi@arucad.edu.tr adresinden yazabilirsin.', 'contact'],
            'invented phone' => ['Kütüphanenin telefonu +90 392 650 65 99.', 'contact'],
            'titled person' => ['Kütüphane müdürü Prof. Dr. Ayşe Yılmaz’dır.', 'person'],
            'programme length' => ['Grafik Tasarım programı 4 yıllık bir lisans programıdır.', 'programme_attribute'],
            'admission score' => ['Başvuru için IELTS 6.0 gerekir.', 'requirement'],
            'negative, turkish' => ['ARUCAD’da tıp programı bulunmamaktadır.', 'negative_claim'],
            'negative, english' => ['ARUCAD does not offer a medicine programme.', 'negative_claim'],
            'negative, russian' => ['ARUCAD не предлагает такую программу.', 'negative_claim'],
            'invented screen' => ['Ayarlar sekmesinden Bildirimler ekranına gidebilirsin.', 'ui_destination'],
            'invented screen, russian' => ['Откройте вкладку «Настройки» в приложении.', 'ui_destination'],
            'trusted screen' => ['Keşfet sekmesindeki Kulüpler bölümüne bakabilirsin.', null],
            'a web page is not a screen' => ['Ayrıntılar web sitesindeki Duyurular sayfasında.', null],
        ];
    }

    #[DataProvider('factualSentences')]
    public function test_factual_categories_are_checked_against_the_facts(string $sentence, ?string $kind): void
    {
        $this->navigationLabels();
        $facts = $this->plan('dersler ne zaman başlıyor ve kütüphanenin maili ne?')->facts;

        $result = app(ClaimVerifier::class)->verify($sentence, $facts);

        $this->assertCount(1, $result['sentences'], 'one sentence: '.$sentence);
        if ($kind === null) {
            $this->assertNotSame(ClaimVerifier::UNSUPPORTED_FACTUAL, $result['sentences'][0]['class'], json_encode($result['unsupported']));
        } else {
            $this->assertSame(ClaimVerifier::UNSUPPORTED_FACTUAL, $result['sentences'][0]['class']);
            $this->assertContains($kind, $result['sentences'][0]['kinds']);
        }
    }

    public function test_a_reported_gap_is_not_a_negative_claim_in_any_language(): void
    {
        $facts = $this->plan('dersler ne zaman başlıyor ve kütüphanenin maili ne?')->facts;
        foreach (['Bu bilgiyi resmi kaynaklarda bulamadım.', 'I could not find this in the official sources.', 'В источниках нет данных об этом.'] as $gap) {
            $result = app(ClaimVerifier::class)->verify($gap, $facts);
            $this->assertSame(ClaimVerifier::PRESENTATION_ONLY, $result['sentences'][0]['class'], $gap);
        }
    }

    // --- Canonical food and club fields ----------------------------------------------

    public function test_structured_weekly_hours_decide_open_now_and_are_summarised_only_when_exact(): void
    {
        FoodVenue::create(['id' => 'cafe', 'name' => 'Kampüs Kafe', 'hours' => null]);
        foreach (range(1, 5) as $day) {
            OpeningHour::create(['subject_type' => 'food_venue', 'subject_id' => 'cafe', 'day_of_week' => $day, 'opens' => '08:00', 'closes' => '16:00']);
        }

        $this->assertSame('Hafta içi 08:00–16:00', OpeningHour::summary('food_venue', 'cafe', now()));
        $this->assertTrue(OpeningHour::openAt('food_venue', 'cafe', now()), 'Tuesday 13:00 campus time');
        $this->assertFalse(OpeningHour::openAt('food_venue', 'cafe', Carbon::parse('2026-10-10 10:00:00')), 'a day without a row is closed');
        $this->assertNull(OpeningHour::openAt('service', 'library', now()), 'no rows: the free-text hours decide');

        // A short Friday cannot be said as "weekdays 08:00–16:00".
        OpeningHour::query()->where('day_of_week', 5)->update(['closes' => '12:00']);
        $this->assertNull(OpeningHour::summary('food_venue', 'cafe', now()));

        // A timetable whose validity ended is not in force.
        OpeningHour::query()->update(['valid_until' => '2026-09-30']);
        $this->assertNull(OpeningHour::openAt('food_venue', 'cafe', now()));
    }

    public function test_planned_food_hours_and_place_come_from_the_canonical_fields(): void
    {
        FoodVenue::create(['id' => 'cafe', 'name' => 'Kafeterya', 'place_id' => 'meditation', 'hours' => null]);
        foreach (range(1, 5) as $day) {
            OpeningHour::create(['subject_type' => 'food_venue', 'subject_id' => 'cafe', 'day_of_week' => $day, 'opens' => '08:00', 'closes' => '16:00']);
        }

        $r = $this->plan('Bugün açık olan en yakın yemek yerine götür.');
        $hours = collect($r->facts->requirements)->firstWhere('factType', 'current_opening_hours');
        $this->assertSame(FactStatus::SUPPORTED, $hours->status);
        $this->assertSame('08:00-16:00', collect($hours->facts)->firstWhere('factType', 'current_opening_hours')->normalizedValue);
        $this->assertSame('meditation', TaskExecutor::venuePlace(FoodVenue::query()->find('cafe'))->id,
            'the canonical place, though the venue name does not contain it');
    }

    public function test_club_profiles_and_rooms_come_only_from_canonical_fields(): void
    {
        $history = [['role' => 'user', 'content' => 'Fotoğraf Kulübü ne yapıyor?']];
        $question = 'Bu kulübün instagramı ne ve kulüp odası nerede?';
        Club::create(['id' => 'photo', 'name' => 'Fotoğraf Kulübü', 'category' => 'Sanat', 'description' => 'Bizi takip edin: @photo_fake_handle']);

        $r = app(TaskOrchestrator::class)->run($question, $history, null);
        $this->assertSame(FactStatus::UNSUPPORTED, collect($r->facts->requirements)->firstWhere('factType', 'club_social_profile')->status,
            'a handle in the description is not a verified account');

        Club::query()->whereKey('photo')->update(['instagram_url' => 'https://instagram.com/arucadphoto', 'place_id' => 'meditation']);
        Cache::flush();
        $r = app(TaskOrchestrator::class)->run($question, $history, null);
        $social = collect($r->facts->requirements)->firstWhere('factType', 'club_social_profile');
        $this->assertSame(FactStatus::SUPPORTED, $social->status);
        $this->assertSame('https://instagram.com/arucadphoto', $social->facts[0]->value);
        $room = collect($r->facts->requirements)->firstWhere('factType', 'place_coordinates');
        $this->assertSame('place:meditation', $room->facts[0]->normalizedValue);
    }

    // --- Coverage diagnostic ---------------------------------------------------------

    public function test_the_coverage_matrix_classifies_each_gap_without_writing_anything(): void
    {
        Club::create(['id' => 'photo', 'name' => 'Fotoğraf Kulübü', 'category' => 'Sanat', 'description' => '@not_trusted']);
        $before = [Place::count(), OpeningHour::count(), Club::query()->whereNotNull('instagram_url')->count()];

        $coverage = app(AicadCoverage::class);
        $matrix = collect($coverage->matrix())->keyBy('capability');

        $this->assertSame(AicadCoverage::SUPPORTED, $matrix['academic_dates']['status']);
        $this->assertNull($matrix['academic_dates']['gap']);
        $this->assertSame(['PARTIALLY_SUPPORTED', 'DATA_GAP'], [$matrix['service_contacts']['status'], $matrix['service_contacts']['gap']],
            'e-mail recorded, no phone');
        $this->assertSame(['DATA_UNAVAILABLE', 'DATA_GAP'], [$matrix['club_social_profile']['status'], $matrix['club_social_profile']['gap']],
            'a description handle does not count');
        $this->assertSame(['SOURCE_UNAVAILABLE', 'SOURCE_GAP'], [$matrix['announcements']['status'], $matrix['announcements']['gap']]);
        $this->assertSame('NOT_IMPLEMENTED', $matrix['admission_requirements']['status']);
        $this->assertSame(count($matrix), array_sum($coverage->metrics()));
        $this->assertSame($before, [Place::count(), OpeningHour::count(), Club::query()->whereNotNull('instagram_url')->count()]);

        Carbon::setTestNow(Carbon::parse('2027-09-15 10:00:00'));
        $stale = collect(app(AicadCoverage::class)->matrix())->firstWhere('capability', 'academic_dates');
        $this->assertSame(['STALE', 'STALE_DATA'], [$stale['status'], $stale['gap']]);
    }

    // --- Trusted navigation ---------------------------------------------------------

    public function test_navigation_labels_come_from_the_app_translations(): void
    {
        $this->navigationLabels();
        $nav = app(AppNavigation::class);

        $this->assertSame('Keşfet → Kulüpler', $nav->destinations()['clubs']['path']['tr']);
        $this->assertSame('Discover → Clubs', $nav->destinations()['clubs']['path']['en']);
        $this->assertSame('clubs', $nav->names(TextFold::fold('open the Clubs tab')));
        $this->assertNull($nav->names(TextFold::fold('Ayarlar sekmesi')));
        $this->assertStringContainsString('Keşfet → Kulüpler', $nav->promptLine());
        $this->assertSame([], $nav->destinations()['food']['labels'], 'an untranslated key has no label rather than a guessed one');
    }

    private function navigationLabels(): void
    {
        foreach (['nav_explore' => ['Keşfet', 'Discover', 'Обзор'], 'discover_clubs' => ['Kulüpler', 'Clubs', 'Клубы']] as $key => $labels) {
            $row = TranslationKey::query()->create(['key' => $key]);
            foreach (array_combine(['tr', 'en', 'ru'], $labels) as $locale => $label) {
                Translation::query()->create(['translation_key_id' => $row->id, 'locale' => $locale, 'published' => $label, 'published_at' => now()]);
            }
        }
    }
}
