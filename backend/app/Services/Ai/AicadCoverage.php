<?php

namespace App\Services\Ai;

use App\Filament\Resources\AcademicYears\AcademicYearResource;
use App\Filament\Resources\AiEntityAliases\AiEntityAliasResource;
use App\Filament\Resources\Clubs\ClubResource;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\FoodVenues\FoodVenueResource;
use App\Filament\Resources\Places\PlaceResource;
use App\Filament\Resources\ServiceItems\ServiceItemResource;
use App\Filament\Resources\Sports\SportResource;
use App\Models\AcademicYear;
use App\Models\AiEntityAlias;
use App\Models\Club;
use App\Models\Event;
use App\Models\FoodDailyMenu;
use App\Models\FoodVenue;
use App\Models\KnowledgeFact;
use App\Models\OpeningHour;
use App\Models\Place;
use App\Models\ServiceItem;
use App\Models\Sport;
use App\Models\StaffProfile;
use App\Services\Ai\Planning\TaskExecutor;
use Illuminate\Support\Facades\Schema;

/**
 * What AICAD can answer from canonical data today, per capability —
 * computed from the live tables, read-only, counts only (no row content).
 *
 * Two measures are kept apart on purpose:
 *  - CODE coverage: does a SupportedFact type (or deterministic path) carry
 *    this capability at all (`implemented`)?
 *  - DATA completeness: of the records it applies to, how many hold the
 *    value (`complete` / `entities`)?
 * A capability is SUPPORTED only when both hold.
 *
 * When it is not, the gap says who can fix it:
 *   CODE_GAP                  the data exists; AICAD does not use it yet
 *   DATA_GAP                  the field exists; staff have not filled it
 *   SOURCE_GAP                no table, feed or official page holds it
 *   STALE_DATA                the source exists but is out of date
 *   NOT_SUPPORTED_BY_PRODUCT  ARUVERSE deliberately does not hold it
 *
 * gaps() lists the individual records behind each DATA_GAP, with what is
 * missing and where staff edit it — the "what to fill in next" list.
 */
final class AicadCoverage
{
    public const SUPPORTED = 'SUPPORTED';

    public const PARTIALLY_SUPPORTED = 'PARTIALLY_SUPPORTED';

    public const DATA_UNAVAILABLE = 'DATA_UNAVAILABLE';

    public const SOURCE_UNAVAILABLE = 'SOURCE_UNAVAILABLE';

    public const STALE = 'STALE';

    public const NOT_IMPLEMENTED = 'NOT_IMPLEMENTED';

    public const STATUSES = [self::SUPPORTED, self::PARTIALLY_SUPPORTED, self::DATA_UNAVAILABLE, self::SOURCE_UNAVAILABLE, self::STALE, self::NOT_IMPLEMENTED];

    /** Said where no admin screen exists yet: the value has to come another way. */
    public const NO_SCREEN = 'no admin screen yet';

    public function __construct(private readonly AcademicCalendar $calendar, private readonly AppNavigation $navigation) {}

    /**
     * @return list<array{capability: string, priority: string, fact_types: list<string>, implemented: bool, exists: bool, structured: bool,
     *     authoritative: bool, current: ?bool, entities: ?int, complete: ?int, missing: ?int, status: string, gap: ?string, detail: string,
     *     affects: list<string>, edit: ?array{resource: ?string, where: string}}>
     */
    public function matrix(): array
    {
        $rows = [];
        $add = function (string $capability, string $priority, array $factTypes, bool $implemented, bool $structured, ?bool $current,
            ?int $entities, ?int $complete, string $status, ?string $gap, string $detail, array $affects, ?array $edit = null) use (&$rows): void {
            $rows[] = ['capability' => $capability, 'priority' => $priority, 'fact_types' => $factTypes, 'implemented' => $implemented,
                'exists' => ($complete ?? 0) > 0 || $status === self::SUPPORTED, 'structured' => $structured, 'authoritative' => $status !== self::SOURCE_UNAVAILABLE,
                'current' => $current, 'entities' => $entities, 'complete' => $complete,
                'missing' => $entities === null || $complete === null ? null : $entities - $complete,
                'status' => $status, 'gap' => $status === self::SUPPORTED ? null : $gap, 'detail' => $detail, 'affects' => $affects, 'edit' => $edit];
        };
        // Data completeness decides the status of an implemented capability.
        $ratio = fn (int $have, int $of) => match (true) {
            $of === 0 || $have === 0 => self::DATA_UNAVAILABLE,
            $have < $of => self::PARTIALLY_SUPPORTED,
            default => self::SUPPORTED,
        };
        $edit = fn (?string $resource, string $where) => ['resource' => $resource, 'where' => $where];

        // --- P0 ---------------------------------------------------------------
        $calendar = $this->calendar->current();
        $add('academic_dates', 'P0', ['academic_date'], true, $calendar !== null, $calendar === null ? null : ! $calendar['stale'],
            1, $calendar !== null && ! $calendar['stale'] ? 1 : 0,
            match (true) {
                $calendar === null => self::SOURCE_UNAVAILABLE,
                $calendar['stale'] => self::STALE,
                default => self::SUPPORTED,
            },
            $calendar === null ? 'SOURCE_GAP' : 'STALE_DATA',
            $calendar === null ? 'official academic calendar page not indexed'
                : $calendar['academic_year'].': '.count($calendar['entries']).' dated entries from the official calendar page',
            ['dersler ne zaman başlıyor', 'finaller ne zaman', 'ekle-bırak son gün'],
            $edit(null, 'the official calendar page (arucad.edu.tr/lisans-akademik-takvim) — re-crawled automatically'));

        $add('required_documents', 'P0', ['required_documents'], true, false, null, null, null, self::PARTIALLY_SUPPORTED, 'DATA_GAP',
            'extracted only where an official page lists the documents; no per-office structured list', ['kayıt için hangi belgeler gerekli']);

        $add('admission_requirements', 'P0', [], false, false, null, null, null, self::NOT_IMPLEMENTED, 'SOURCE_GAP',
            'no structured admission criteria; only the international apply page lists them, and they differ by applicant type. The verifier rejects any score claim',
            ['başvuru şartları neler', 'IELTS kaç olmalı']);

        $services = ServiceItem::query()->get(['id', 'contact', 'phone', 'hours']);
        $n = $services->count();
        $emails = $services->filter(fn ($s) => (bool) preg_match('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', (string) $s->contact))->count();
        $phones = $services->filter(fn ($s) => self::hasPhone($s))->count();
        $add('service_contacts', 'P0', ['contact_email', 'contact_phone'], true, true, null, $n * 2, $emails + $phones,
            $emails === 0 && $phones === 0 ? self::DATA_UNAVAILABLE : ($emails + $phones < $n * 2 ? self::PARTIALLY_SUPPORTED : self::SUPPORTED),
            'DATA_GAP', "{$emails}/{$n} services with an e-mail, {$phones}/{$n} with a phone number",
            ['öğrenci işlerinin telefonu ne', 'kütüphanenin maili ne'], $edit(ServiceItemResource::class, 'Services → office → Phone'));

        $hasFacts = Schema::hasTable('knowledge_facts');
        $programmes = $hasFacts ? KnowledgeFact::query()->where('subject_type', KnowledgeFact::SUBJECT_PROGRAMME)->distinct()->count('subject_folded') : 0;
        $languages = $hasFacts ? KnowledgeFact::query()->where('attribute', KnowledgeFact::LANGUAGE)->distinct()->count('subject_folded') : 0;
        $add('programme_language', 'P0', ['program_language'], true, true, null, $programmes, $languages, $ratio($languages, $programmes), 'DATA_GAP',
            "{$languages}/{$programmes} programmes with a language-of-instruction fact", ['mimarlık hangi dilde'],
            $edit(null, 'the official programme pages (re-crawled; facts re-extracted)'));
        $durations = $hasFacts ? KnowledgeFact::query()->where('attribute', KnowledgeFact::DURATION)->distinct()->count('subject_folded') : 0;
        $add('programme_duration', 'P0', ['programme_duration'], true, true, null, $programmes, $durations, $ratio($durations, $programmes), 'DATA_GAP',
            "{$durations}/{$programmes} programmes with a stated duration", ['görsel iletişim tasarımı kaç yıl'],
            $edit(null, 'the official programme pages (re-crawled; facts re-extracted)'));

        // --- P1 ---------------------------------------------------------------
        $venues = FoodVenue::query()->get(['id', 'name', 'hours', 'place_id']);
        $v = $venues->count();
        $structured = OpeningHour::query()->where('subject_type', 'food_venue')->distinct()->count('subject_id');
        $withHours = $venues->filter(fn ($venue) => self::venueHasHours($venue))->count();
        $add('food_opening_hours', 'P1', ['current_opening_hours', 'is_open_now'], true, $structured > 0, null, $v, $withHours,
            $ratio($withHours, $v), 'DATA_GAP', "{$withHours}/{$v} venues with hours ({$structured} as structured weekly rows)",
            ['yemekhane bugün açık mı', 'şu an açık yemek yeri var mı', 'yarın açık mı'], $edit(FoodVenueResource::class, 'Food & Drink → venue → Weekly opening hours'));
        $placed = $venues->filter(fn ($venue) => TaskExecutor::venuePlace($venue)?->lat !== null)->count();
        $canonical = $venues->whereNotNull('place_id')->count();
        $add('food_location', 'P1', ['place_coordinates'], true, $canonical > 0, null, $v, $placed, $ratio($placed, $v), 'DATA_GAP',
            "{$placed}/{$v} venues reach a campus place ({$canonical} by canonical place_id, the rest by name)",
            ['yemekhane nerede', 'en yakın yemek yeri', 'buradan yemekhaneye götür'], $edit(FoodVenueResource::class, 'Food & Drink → venue → Campus place'));
        $menus = FoodDailyMenu::query()->whereDate('menu_date', '>=', now()->toDateString())->count();
        $add('food_menu', 'P1', ['current_menu'], true, true, $menus > 0, null, null, $menus > 0 ? self::SUPPORTED : self::DATA_UNAVAILABLE, 'DATA_GAP',
            "{$menus} daily menus for today or later", ['bugün yemekte ne var'], $edit(null, self::NO_SCREEN.' — daily menus are posted through the food API'));

        $clubs = Club::query()->get(['instagram_url', 'email', 'website', 'place_id']);
        $c = $clubs->count();
        $social = $clubs->filter(fn ($club) => trim((string) $club->instagram_url) !== '')->count();
        $add('club_social_profile', 'P1', ['club_social_profile'], true, true, null, $c, $social, $ratio($social, $c), 'DATA_GAP',
            "{$social}/{$c} clubs with an official Instagram URL", ['fotoğraf kulübünün instagramı ne'], $edit(ClubResource::class, 'Clubs → club → Contact & room → Instagram URL'));
        $clubMail = $clubs->filter(fn ($club) => trim((string) $club->email) !== '')->count();
        $add('club_contacts', 'P1', ['contact_email'], true, true, null, $c, $clubMail, $ratio($clubMail, $c), 'DATA_GAP',
            "{$clubMail}/{$c} clubs with an e-mail", ['X kulübüne nasıl ulaşırım'], $edit(ClubResource::class, 'Clubs → club → Contact & room → E-mail'));
        $rooms = $clubs->whereNotNull('place_id')->count();
        $add('club_rooms', 'P1', ['place_coordinates'], true, true, null, $c, $rooms, $ratio($rooms, $c), 'DATA_GAP',
            "{$rooms}/{$c} clubs with a room / place", ['kulüp odası nerede'], $edit(ClubResource::class, 'Clubs → club → Contact & room → Room / place'));

        $staff = StaffProfile::query()->pluck('name');
        $people = $staff->reject(fn ($name) => AicadHealth::isUnitName((string) $name))->count();
        $add('staff_persons', 'P1', [], false, true, null, $staff->count(), $people, self::NOT_IMPLEMENTED, 'DATA_GAP',
            "{$people}/{$staff->count()} staff records name a person (the rest are offices); no person fact type until names exist",
            ['mimarlık bölüm başkanı kim'], $edit(null, self::NO_SCREEN.' — staff profiles (person records with a source)'));

        $serviceHours = $services->filter(fn ($s) => trim((string) $s->hours) !== '' || OpeningHour::query()->for('service', (string) $s->id)->exists())->count();
        $add('service_opening_hours', 'P1', ['current_opening_hours', 'is_open_now'], true, false, null, $n, $serviceHours,
            $ratio($serviceHours, $n), 'DATA_GAP', "{$serviceHours}/{$n} services with hours", ['öğrenci işleri açık mı'],
            $edit(ServiceItemResource::class, 'Services → office → Hours'));

        $places = Place::query()->get(['lat', 'lng']);
        $located = $places->filter(fn ($p) => $p->lat !== null && $p->lng !== null)->count();
        $add('campus_locations', 'P1', ['place_coordinates'], true, true, null, $places->count(), $located, $ratio($located, $places->count()), 'DATA_GAP',
            "{$located}/{$places->count()} places with coordinates", ['titan binası nerede'], $edit(PlaceResource::class, 'Places → place → coordinates'));
        $routing = filled(config('services.routing.base_url'));
        $add('walking_routes', 'P1', ['route_duration_min', 'route_distance_m'], true, true, null, null, null,
            $routing ? self::SUPPORTED : self::SOURCE_UNAVAILABLE, 'SOURCE_GAP', $routing ? 'routing service configured' : 'no routing service configured (services.routing.base_url)',
            ['buradan kütüphaneye nasıl giderim']);

        $year = AcademicYear::query()->where('is_active', true)->orderByDesc('starts_on')->first();
        $yearStale = $year === null || ($year->ends_on !== null && now()->greaterThan($year->ends_on));
        $add('academic_year_record', 'P1', [], true, true, ! $yearStale, 1, $yearStale ? 0 : 1, $yearStale ? self::STALE : self::SUPPORTED,
            'STALE_DATA', $year === null ? 'no active academic-year record'
                : "active record {$year->label} ends ".substr((string) $year->ends_on, 0, 10).($yearStale ? ' — ended; still used for event year tagging and temporal checks (not for dates)' : ''),
            ['(indirect) new events are tagged with this year; evidence staleness is judged against it'], $edit(AcademicYearResource::class, 'Academic years → add the current year, make it active'));

        // --- P2 ---------------------------------------------------------------
        $add('announcements', 'P2', [], false, false, null, null, null, self::SOURCE_UNAVAILABLE, 'SOURCE_GAP',
            'no announcements table or feed; official notices exist only as crawled pages (not structured, no validity dates)',
            ['bugün kütüphane kapalı mı (duyuru)', 'ders iptali var mı']);
        $events = Event::query()->publiclyListed()->whereDate('event_date', '>=', now()->toDateString())->count();
        $add('events', 'P2', ['current_events'], true, true, $events > 0, null, null, $events > 0 ? self::SUPPORTED : self::DATA_UNAVAILABLE, 'DATA_GAP',
            "{$events} published upcoming events", ['bugün hangi etkinlikler var'], $edit(EventResource::class, 'Events'));
        $sports = Sport::query()->get(['id', 'facility', 'place_id']);
        $linked = $sports->filter(fn ($s) => self::sportPlace($s) !== null)->count();
        $add('sports_facilities', 'P2', ['place_coordinates'], true, true, null, $sports->count(), $linked, $ratio($linked, $sports->count()), 'DATA_GAP',
            "{$linked}/{$sports->count()} teams linked to a campus place ({$sports->pluck('facility')->filter()->unique()->count()} facility names, none of them a place record)",
            ['basketbol takımı nerede antrenman yapıyor'], $edit(SportResource::class, 'Sports → team → Campus place (create the Place first)'));

        $destinations = $this->navigation->destinations();
        $labelled = collect($destinations)->filter(fn ($d) => isset($d['labels']['tr'], $d['labels']['en'], $d['labels']['ru']))->count();
        $add('app_navigation', 'P2', [], true, true, null, count($destinations), $labelled, $ratio($labelled, count($destinations)), 'DATA_GAP',
            "{$labelled}/".count($destinations).' trusted app destinations labelled in tr, en and ru (claims about other screens are rejected)',
            ['kulüplere uygulamada nereden bakarım'], $edit(null, 'config/aicad_navigation.php + Translations'));

        $aliased = collect(AiEntityAlias::query()->where('entity_type', 'service')->where('active', true)->distinct()->pluck('entity_id'))
            ->intersect($services->pluck('id'))->count();
        $add('service_aliases', 'P2', [], true, true, null, $n, $aliased, $ratio($aliased, $n), 'DATA_GAP',
            "{$aliased}/{$n} offices with operator aliases (short and Russian names, beyond the built-in list)",
            ['kariyer merkezinin maili', 'телефон студенческого офиса'], $edit(AiEntityAliasResource::class, 'AICAD → Aliases'));

        return $rows;
    }

    /** @return array<string, int> status → number of capabilities */
    public function metrics(?array $matrix = null): array
    {
        return array_count_values(array_column($matrix ?? $this->matrix(), 'status')) + array_fill_keys(self::STATUSES, 0);
    }

    /**
     * Code coverage and data completeness, kept apart.
     *
     * @return array{capabilities: int, implemented: int, data_rows: int, data_entities: int, data_complete: int}
     */
    public function summary(?array $matrix = null): array
    {
        $matrix ??= $this->matrix();
        $data = array_filter($matrix, fn ($r) => $r['entities'] !== null && $r['implemented']);

        return ['capabilities' => count($matrix), 'implemented' => count(array_filter($matrix, fn ($r) => $r['implemented'])),
            'data_rows' => count($data), 'data_entities' => (int) array_sum(array_column($data, 'entities')),
            'data_complete' => (int) array_sum(array_column($data, 'complete'))];
    }

    /**
     * Every record with a canonical field AICAD needs and staff have not
     * filled — the actionable list behind the DATA_GAP rows.
     *
     * @return list<array{type: string, id: string, entity: string, missing: list<string>, affects: list<string>, resource: ?string, where: string}>
     */
    public function gaps(): array
    {
        $out = [];
        $push = function (string $type, string $id, string $entity, array $missing, array $affects, ?string $resource, string $where) use (&$out): void {
            if ($missing !== []) {
                $out[] = compact('type', 'id', 'entity', 'missing', 'affects', 'resource', 'where');
            }
        };

        foreach (ServiceItem::query()->orderBy('title')->get(['id', 'title', 'contact', 'phone', 'hours']) as $s) {
            $missing = array_values(array_filter([
                self::hasPhone($s) ? null : 'phone',
                preg_match('/@/', (string) $s->contact) ? null : 'e-mail',
                trim((string) $s->hours) !== '' || OpeningHour::query()->for('service', (string) $s->id)->exists() ? null : 'opening hours',
            ]));
            $push('service', (string) $s->id, (string) $s->title, $missing,
                array_values(array_filter([in_array('phone', $missing, true) || in_array('e-mail', $missing, true) ? 'contact_phone / contact_email' : null,
                    in_array('opening hours', $missing, true) ? 'opening_hours, is_open_now' : null])),
                ServiceItemResource::class, 'Services');
        }
        foreach (FoodVenue::query()->orderBy('name')->get(['id', 'name', 'hours', 'place_id']) as $venue) {
            $missing = array_values(array_filter([
                $venue->place_id === null ? 'campus place' : null,
                self::venueHasHours($venue) ? null : 'weekly opening hours',
                FoodDailyMenu::query()->where('food_venue_id', $venue->id)->whereDate('menu_date', '>=', now()->toDateString())->exists() ? null : 'daily menu (today or later)',
            ]));
            $push('food_venue', (string) $venue->id, (string) $venue->name, $missing,
                ['opening_hours', 'is_open_now', 'nearest_food', 'route_to_food', 'current_menu'], FoodVenueResource::class, 'Food & Drink');
        }
        foreach (Club::query()->orderBy('name')->get(['id', 'name', 'instagram_url', 'email', 'place_id']) as $club) {
            $missing = array_values(array_filter([
                trim((string) $club->instagram_url) === '' ? 'Instagram URL' : null,
                trim((string) $club->email) === '' ? 'e-mail' : null,
                $club->place_id === null ? 'room / place' : null,
            ]));
            $push('club', (string) $club->id, (string) $club->name, $missing, ['club_social', 'club_contact', 'club_location'], ClubResource::class, 'Clubs');
        }
        foreach (Sport::query()->orderBy('name')->get(['id', 'name', 'facility', 'place_id']) as $sport) {
            if (self::sportPlace($sport) === null) {
                $push('sport', (string) $sport->id, $sport->name.' ('.$sport->facility.')', ['campus place'], ['sport_location', 'route'],
                    SportResource::class, 'Sports (create the Place first if the facility has none)');
            }
        }
        $year = AcademicYear::query()->where('is_active', true)->orderByDesc('starts_on')->first();
        if ($year !== null && $year->ends_on !== null && now()->greaterThan($year->ends_on)) {
            $push('academic_year', (string) $year->id, 'Akademik yıl '.$year->label, ['ended '.substr((string) $year->ends_on, 0, 10).' but still active — add the current year and make it active'],
                ['event academic-year tagging', 'evidence staleness checks'], AcademicYearResource::class, 'Academic years');
        }

        return $out;
    }

    /**
     * The data-completion checklist, ordered by impact on AICAD answers:
     * A = everyday questions blocked today, B = clubs and sports, C = content
     * that needs a source or a screen first. Counts come from the matrix.
     *
     * @return list<array{priority: string, item: string, total: ?int, complete: ?int, missing: ?int, affects: list<string>, edit: ?array{resource: ?string, where: string}}>
     */
    public function checklist(?array $matrix = null): array
    {
        $rows = collect($matrix ?? $this->matrix())->keyBy('capability');
        $services = ServiceItem::query()->get(['contact', 'phone']);
        $phones = $services->filter(fn ($s) => self::hasPhone($s))->count();
        $item = fn (string $priority, string $label, ?int $total, ?int $complete, array $affects, ?array $edit) => [
            'priority' => $priority, 'item' => $label, 'total' => $total, 'complete' => $complete,
            'missing' => $total === null || $complete === null ? null : $total - $complete, 'affects' => $affects, 'edit' => $edit];
        $from = fn (string $capability, string $priority, string $label, array $affects) => $item($priority, $label,
            $rows[$capability]['entities'] ?? null, $rows[$capability]['complete'] ?? null, $affects, $rows[$capability]['edit'] ?? null);

        return [
            $item('A', 'Office phone numbers', $services->count(), $phones, ['contact_phone'], ['resource' => ServiceItemResource::class, 'where' => 'Services → office → Phone']),
            $from('food_location', 'A', 'Food venue → campus place', ['food_location', 'nearest_food', 'route_to_food']),
            $from('food_opening_hours', 'A', 'Food venue weekly opening hours', ['opening_hours', 'is_open_now']),
            $from('academic_year_record', 'A', 'Current academic year (2026–2027) active', ['event year tagging', 'evidence staleness']),
            $from('club_social_profile', 'B', 'Club Instagram URLs', ['club_social_profile']),
            $from('club_contacts', 'B', 'Club e-mails', ['contact_email (club)']),
            $from('club_rooms', 'B', 'Club meeting places', ['club_location', 'route_to_club']),
            $from('sports_facilities', 'B', 'Sports team → campus place', ['sport_location', 'route']),
            $from('staff_persons', 'C', 'Staff person records (with a source)', ['staff_person (no fact type yet)']),
            $from('service_aliases', 'C', 'Office aliases', ['entity resolution']),
            $item('C', 'Daily menus and events', null, null, ['current_menu', 'current_events'], ['resource' => null, 'where' => self::NO_SCREEN.' for menus (food API); Events for events']),
        ];
    }

    public static function hasPhone(ServiceItem $service): bool
    {
        return trim((string) $service->phone) !== '' || (bool) preg_match('/\+?\d[\d\s().-]{6,}\d/u', (string) $service->contact);
    }

    private static function venueHasHours(FoodVenue $venue): bool
    {
        return trim((string) $venue->hours) !== '' || OpeningHour::query()->for('food_venue', (string) $venue->id)->exists();
    }

    private static function sportPlace(Sport $sport): ?Place
    {
        return $sport->place_id === null ? null : Place::query()->find($sport->place_id);
    }
}
