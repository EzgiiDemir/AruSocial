<?php

namespace App\Services\Ai\Evidence;

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
use App\Services\Ai\AcademicCalendar;
use App\Services\Ai\Planning\EntityResolution;
use App\Services\Ai\Planning\EvidenceRequirement;
use App\Services\Ai\Planning\OpeningHours;
use App\Services\Ai\Planning\PlanningResult;
use App\Services\Ai\Planning\ProviderRegistry;
use App\Services\Ai\Planning\ResolutionStatus;
use App\Services\Ai\Planning\Task;
use App\Services\Ai\Planning\TaskExecutionState;
use App\Services\Ai\Planning\TaskExecutor;
use App\Services\Ai\Planning\TaskState;
use App\Services\Ai\ProgrammeCatalog;
use App\Services\Knowledge\KnowledgeBase;
use App\Services\RoutingService;
use App\Support\TextFold;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Thin adapters from AICAD's existing providers to Evidence. Each adapter
 * reads what the provider already holds — campus rows, knowledge_facts,
 * KnowledgeBase, RoutingService, the request — and wraps it with provenance.
 * Entity resolution is NOT redone: the subject comes from Phase 3A's final
 * entities. Ranking is NOT redone: knowledge passages are KnowledgeBase's own
 * top hits. A missing fact becomes UNAVAILABLE with a reason, never a value.
 */
final class EvidenceCollector
{
    /** Passages per knowledge requirement — the same depth the prompt path retrieves. */
    private const KNOWLEDGE_LIMIT = 4;

    private const EXCERPT_CHARS = 600;

    /** @var array<string, int> requirement id → next evidence ordinal */
    private array $ordinals = [];

    /** @var array{query: string, limit: int, hits: list<array<string, mixed>>}|null the KnowledgeBase result used, for reuse by the prompt */
    private ?array $knowledgeHits = null;

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly KnowledgeBase $knowledge,
    ) {}

    /**
     * Evidence for every requirement of a planned question, keyed by requirement id.
     *
     * @param  array{lat?: mixed, lng?: mixed}|null  $location
     * @return array<string, list<Evidence>>
     */
    public function collect(PlanningResult $planning, string $query, ?array $location, CarbonInterface $now): array
    {
        $this->ordinals = [];
        $this->knowledgeHits = null;
        $now = CarbonImmutable::instance($now);
        $states = [];
        foreach ($planning->states as $state) {
            $states[$state->taskId] = $state;
        }
        $routed = [];
        foreach ($planning->routes as $route) {
            $routed[$route->requirementId] = $route->routed();
        }

        $out = [];
        foreach ($planning->requirements as $requirement) {
            $task = $planning->plan?->task($requirement->taskId);
            $state = $states[$requirement->taskId] ?? null;
            if ($task === null || $state === null) {
                continue;
            }
            if ($state->state === TaskState::BLOCKED) {
                $out[$requirement->id] = [$this->none($requirement, EvidenceStatus::NOT_APPLICABLE, 'task_blocked', ['detail' => $state->reason])];

                continue;
            }
            if (! ($routed[$requirement->id] ?? false)) {
                $out[$requirement->id] = [$this->none($requirement, EvidenceStatus::UNAVAILABLE, 'no_provider')];

                continue;
            }

            try {
                $items = $this->gather($requirement, $task, $planning, $states, $out, $query, $location, $now);
            } catch (Throwable $e) {
                // A provider fault is recorded as ERROR — distinct from "the data does not exist".
                $items = [$this->none($requirement, EvidenceStatus::ERROR, 'provider_error', ['exception' => class_basename($e)])];
            }
            $out[$requirement->id] = $items !== [] ? $items : [$this->none($requirement, EvidenceStatus::UNAVAILABLE, 'no_record')];
        }

        return $out;
    }

    /**
     * @param  array<string, TaskExecutionState>  $states
     * @param  array<string, list<Evidence>>  $collected  evidence gathered so far (earlier requirements)
     * @return list<Evidence>
     */
    private function gather(EvidenceRequirement $r, Task $task, PlanningResult $planning,
        array $states, array $collected, string $query, ?array $location, CarbonImmutable $now): array
    {
        $previous = [];
        foreach ($task->dependsOn as $dependency) {
            $previous += $states[$dependency]->output ?? [];
        }

        return match ($r->factType) {
            'current_opening_hours' => $r->subjectRef !== null && str_starts_with($r->subjectRef, 'task:')
                ? $this->venueHours($r, (array) ($previous['venue_ids'] ?? []), $now)
                : $this->serviceHours($r, $task, $planning, $now),
            'place_coordinates' => $this->coordinates($r, $task, $planning, $previous, $now),
            'user_location' => [$this->userLocation($r, $location, $now)],
            'routing' => [$this->routing($r, $collected, $now)],
            'required_documents' => $this->passages($r, $query, $now, $this->subjectTerms($task, $planning)),
            // The structured programme fact first; passage retrieval (about a
            // second) only when the named programme has none.
            'program_language' => $this->programFacts($r, $query, $now) ?: $this->passages($r, $query, $now),
            'programme_duration' => $this->programFacts($r, $query, $now, KnowledgeFact::DURATION) ?: [$this->none($r, EvidenceStatus::UNAVAILABLE, 'no_record')],
            'current_menu' => $this->menus($r, $now),
            'current_events' => $this->events($r, $now),
            'food_places' => $this->foodPlaces($r, $now),
            'club_social_profile' => [$this->clubSocial($r, $task, $planning, $now)],
            'academic_date' => [$this->academicDate($r, $query, $now)],
            'contact_details' => $this->contacts($r, $task, $planning, $now),
            default => [$this->none($r, EvidenceStatus::UNAVAILABLE, 'no_provider')],
        };
    }

    /** @return list<Evidence> */
    private function serviceHours(EvidenceRequirement $r, Task $task, PlanningResult $planning, CarbonImmutable $now): array
    {
        $subject = $this->subject($task, $planning, ['service', 'place']);
        if (is_string($subject)) {
            return [$this->none($r, EvidenceStatus::UNAVAILABLE, $subject)];
        }
        if ($subject['type'] === 'place') {
            // Buildings carry no opening hours in the campus data.
            return [$this->none($r, EvidenceStatus::UNAVAILABLE, 'no_authoritative_field', [], $subject)];
        }
        $service = ServiceItem::query()->find($subject['id']);
        if ($service === null) {
            return [$this->none($r, EvidenceStatus::UNAVAILABLE, 'no_record', [], $subject)];
        }
        $hours = OpeningHour::summary('service', (string) $service->id, $now) ?? trim((string) $service->hours);
        if ($hours === '') {
            return [$this->none($r, EvidenceStatus::UNAVAILABLE, 'no_authoritative_field', [], $subject)];
        }

        return [$this->found($r, $subject, $hours, 'string', 'campus_operational', ServiceItem::class,
            'database', 'services:'.$service->id, (string) $service->title, 'authoritative_operational', $now,
            metadata: ['open_now' => OpeningHour::openAt('service', (string) $service->id, $now) ?? OpeningHours::isOpen($hours, $now)])];
    }

    /**
     * Hours of each candidate venue: the standing hours on the venue, and a
     * day-specific value from today's menu row (valid for that date only).
     *
     * @param  list<string>  $venueIds
     * @return list<Evidence>
     */
    private function venueHours(EvidenceRequirement $r, array $venueIds, CarbonImmutable $now): array
    {
        $out = [];
        $today = FoodDailyMenu::query()->whereIn('food_venue_id', $venueIds)->whereDate('menu_date', $now->toDateString())
            ->get()->keyBy('food_venue_id');
        foreach (FoodVenue::query()->whereIn('id', $venueIds)->get() as $venue) {
            $subject = ['type' => 'food_venue', 'id' => (string) $venue->id, 'name' => (string) $venue->name];
            // Structured weekly rows when staff entered them, else the free-text field.
            $hours = OpeningHour::summary('food_venue', (string) $venue->id, $now) ?? trim((string) $venue->hours);
            $out[] = $hours === ''
                ? $this->none($r, EvidenceStatus::UNAVAILABLE, 'no_authoritative_field', [], $subject)
                : $this->found($r, $subject, $hours, 'string', 'campus_operational', FoodVenue::class, 'database',
                    'food_venues:'.$venue->id, (string) $venue->name, 'authoritative_operational', $now,
                    metadata: ['open_now' => OpeningHour::openAt('food_venue', (string) $venue->id, $now) ?? OpeningHours::isOpen($hours, $now)]);
            $menu = $today->get($venue->id);
            if ($menu !== null && trim((string) $menu->hours) !== '') {
                $day = CarbonImmutable::instance($menu->menu_date);
                $out[] = $this->found($r, $subject, trim((string) $menu->hours), 'string', 'campus_operational', FoodDailyMenu::class,
                    'database', 'food_daily_menus:'.$menu->id, (string) $venue->name, 'authoritative_operational', $now,
                    validFrom: $day->startOfDay(), validUntil: $day->endOfDay(), scope: 'exception',
                    metadata: ['open_now' => OpeningHours::isOpen((string) $menu->hours, $now)]);
            }
        }

        return $out;
    }

    /** @return list<Evidence> */
    private function coordinates(EvidenceRequirement $r, Task $task, PlanningResult $planning, array $previous, CarbonImmutable $now): array
    {
        if ($r->subjectRef !== null && str_starts_with($r->subjectRef, 'task:')) {
            if (isset($previous['place_id'])) {
                return [$this->placeEvidence($r, Place::query()->find($previous['place_id']), $now)];
            }
            // Candidate venues from the previous task (rank_by_distance).
            $out = [];
            foreach (FoodVenue::query()->whereIn('id', (array) ($previous['venue_ids'] ?? []))->get() as $venue) {
                $place = TaskExecutor::venuePlace($venue);
                $out[] = $place === null
                    ? $this->none($r, EvidenceStatus::UNAVAILABLE, 'no_authoritative_field',
                        ['detail' => 'venue is not linked to a campus place'], ['type' => 'food_venue', 'id' => (string) $venue->id, 'name' => (string) $venue->name])
                    : $this->placeEvidence($r, $place, $now);
            }

            return $out;
        }

        $subject = $this->subject($task, $planning, ['place', 'service', 'club', 'sport', 'food_venue']);
        if (is_string($subject)) {
            return [$this->none($r, EvidenceStatus::UNAVAILABLE, $subject)];
        }
        if ($subject['type'] === 'food_venue') {
            $venue = FoodVenue::query()->find($subject['id']);
            $place = $venue === null ? null : TaskExecutor::venuePlace($venue);

            return [$place === null
                ? $this->none($r, EvidenceStatus::UNAVAILABLE, 'no_authoritative_field', ['detail' => 'venue is not linked to a campus place'], $subject)
                : $this->placeEvidence($r, $place, $now)];
        }
        if ($subject['type'] === 'sport') {
            $sport = Sport::query()->find($subject['id']);
            $place = $sport?->place_id === null ? null : Place::query()->find($sport->place_id);

            return [$place === null
                ? $this->none($r, EvidenceStatus::UNAVAILABLE, 'no_authoritative_field', ['detail' => 'no campus place recorded for the team'], $subject)
                : $this->placeEvidence($r, $place, $now)];
        }
        if ($subject['type'] === 'club') {
            $place = TaskExecutor::clubPlace(Club::query()->find($subject['id']));

            return [$place === null
                ? $this->none($r, EvidenceStatus::UNAVAILABLE, 'no_authoritative_field', ['detail' => 'no room recorded for the club'], $subject)
                : $this->placeEvidence($r, $place, $now)];
        }
        if ($subject['type'] === 'service') {
            $service = ServiceItem::query()->find($subject['id']);
            $place = $service === null ? null : TaskExecutor::servicePlace($service);
            if ($place === null) {
                return [$this->none($r, EvidenceStatus::UNAVAILABLE, 'no_record', ['detail' => 'service building is not a campus place'], $subject)];
            }

            return [$this->placeEvidence($r, $place, $now)];
        }

        return [$this->placeEvidence($r, Place::query()->find($subject['id']), $now)];
    }

    private function placeEvidence(EvidenceRequirement $r, ?Place $place, CarbonImmutable $now): Evidence
    {
        if ($place === null) {
            return $this->none($r, EvidenceStatus::UNAVAILABLE, 'no_record');
        }
        $subject = ['type' => 'place', 'id' => (string) $place->id, 'name' => (string) $place->name];
        if ($place->lat === null || $place->lng === null) {
            return $this->none($r, EvidenceStatus::UNAVAILABLE, 'no_authoritative_field', [], $subject);
        }

        return $this->found($r, $subject, ['lat' => (float) $place->lat, 'lng' => (float) $place->lng], 'location',
            'campus_operational', Place::class, 'database', 'places:'.$place->id, (string) $place->name, 'authoritative_operational', $now);
    }

    private function userLocation(EvidenceRequirement $r, ?array $location, CarbonImmutable $now): Evidence
    {
        if (! is_numeric($location['lat'] ?? null) || ! is_numeric($location['lng'] ?? null)) {
            // The student has not shared a location: context, not a data gap.
            return $this->none($r, EvidenceStatus::UNAVAILABLE, 'context_missing');
        }

        // Coordinates stay out of the trace: only that a location was present.
        return $this->found($r, ['type' => 'user', 'id' => 'request', 'name' => 'current location'],
            ['lat' => (float) $location['lat'], 'lng' => (float) $location['lng']], 'location', 'request_context', 'request',
            'request_context', 'request', null, 'request_context', $now, observedAt: $now, validFrom: $now, validUntil: $now->addMinutes(30),
            redact: true);
    }

    /** @param array<string, list<Evidence>> $collected */
    private function routing(EvidenceRequirement $r, array $collected, CarbonImmutable $now): Evidence
    {
        $siblings = array_merge(...array_values(array_filter($collected, fn ($k) => str_starts_with($k, $r->taskId.'.'), ARRAY_FILTER_USE_KEY)) ?: [[]]);
        $destination = collect($siblings)->first(fn (Evidence $e) => $e->factType === 'place_coordinates' && $e->available());
        $origin = collect($siblings)->first(fn (Evidence $e) => $e->factType === 'user_location' && $e->available());
        if ($destination === null || $origin === null) {
            return $this->none($r, EvidenceStatus::UNAVAILABLE, 'prerequisite_unavailable',
                ['missing' => array_values(array_filter([$destination === null ? 'destination' : null, $origin === null ? 'origin' : null]))]);
        }
        if (! RoutingService::isConfiguredFor('walking')) {
            return $this->none($r, EvidenceStatus::UNAVAILABLE, 'routing_not_configured');
        }
        $route = RoutingService::route($origin->value['lat'], $origin->value['lng'], $destination->value['lat'], $destination->value['lng']);
        if ($route === null) {
            return $this->none($r, EvidenceStatus::ERROR, 'routing_failed');
        }

        return $this->found($r, $destination->subject,
            ['distance_m' => (int) round($route['distanceMeters']), 'duration_s' => (int) round($route['durationSeconds']), 'steps' => count($route['steps'] ?? [])],
            'structured', 'routing_service', RoutingService::class, 'routing', 'osrm:walking:'.$destination->sourceId,
            null, 'routing_service', $now, validFrom: $now, validUntil: $now->addMinutes(30));
    }

    /**
     * The KnowledgeBase result this collection used, so the prompt path can
     * reuse it for the identical query instead of ranking twice.
     *
     * @return array{query: string, limit: int, hits: list<array<string, mixed>>}|null
     */
    public function knowledgeHits(): ?array
    {
        return $this->knowledgeHits;
    }

    /**
     * KnowledgeBase's own top hits for the question, as untrusted document
     * passages. Ranked once per collection, however many requirements need them.
     *
     * @return list<Evidence>
     */
    private function passages(EvidenceRequirement $r, string $query, CarbonImmutable $now, ?array $subjectTerms = null): array
    {
        if ($this->knowledgeHits === null) {
            // As the agent does before ranking: bounded, allow-listed, cached per URL.
            $this->knowledge->refreshFor($query);
            $this->knowledgeHits = ['query' => $query, 'limit' => self::KNOWLEDGE_LIMIT,
                'hits' => $this->knowledge->hasContent() ? $this->knowledge->relevant($query, self::KNOWLEDGE_LIMIT) : []];
        }
        $hits = $this->knowledgeHits['hits'];
        $out = [];
        foreach ($hits as $hit) {
            $url = (string) $hit['url'];
            $out[] = $this->found($r, null, mb_strimwidth((string) $hit['snippet'], 0, self::EXCERPT_CHARS, '…'), 'document_passage',
                'official_knowledge', KnowledgeBase::class,
                str_contains(strtolower((string) ($hit['contentType'] ?? '')), 'pdf') ? 'official_pdf' : 'official_web',
                'knowledge_documents:'.$hit['documentId'].(isset($hit['chunkId']) ? '#chunk:'.$hit['chunkId'] : ''),
                $hit['title'] ?? null,
                preg_match('~/syl+a?bus~i', $url) ? 'course_document' : 'official_document',
                isset($hit['fetchedAt']) ? CarbonImmutable::parse($hit['fetchedAt']) : $now,
                publishedAt: isset($hit['lastModifiedAt']) ? CarbonImmutable::parse($hit['lastModifiedAt']) : null,
                url: $url,
                status: ($hit['stale'] ?? false) ? EvidenceStatus::STALE : EvidenceStatus::AVAILABLE,
                reason: ($hit['stale'] ?? false) ? 'source_stale' : null,
                metadata: array_filter([
                    'document_id' => $hit['documentId'], 'chunk_id' => $hit['chunkId'] ?? null, 'locale' => $hit['language'] ?? null,
                    'score' => $hit['score'] ?? null, 'lexical' => $hit['lexical'] ?? null, 'semantic' => $hit['similarity'] ?? null,
                    'authority' => $hit['authority'] ?? null, 'page' => $hit['page'] ?? null, 'untrusted' => true,
                    // Whether the passage (or its title) names the task's subject at all.
                    'mentions_subject' => $subjectTerms === null ? null : $this->mentionsAny(($hit['title'] ?? '').' '.$hit['snippet'], $subjectTerms),
                ], fn ($v) => $v !== null));
        }

        return $out;
    }

    /** Programme facts whose programme the question names. @return list<Evidence> */
    private function programFacts(EvidenceRequirement $r, string $query, CarbonImmutable $now, string $attribute = KnowledgeFact::LANGUAGE): array
    {
        // Canonical programmes the question names, in any supported language;
        // each fact is attributed to its canonical programme, so the English
        // page's fact and the Turkish one are the same subject.
        $out = [];
        foreach (app(ProgrammeCatalog::class)->inText($query) as $programme) {
            $facts = KnowledgeFact::query()->where('attribute', $attribute)->whereIn('subject_folded', $programme['names'])->get();
            foreach ($facts as $fact) {
                $out[] = $this->programmeFact($r, $programme, $fact, $now);
            }
        }

        return $out;
    }

    private function programmeFact(EvidenceRequirement $r, array $programme, KnowledgeFact $fact, CarbonImmutable $now): Evidence
    {
        return $this->found($r, ['type' => 'programme', 'id' => $programme['id'], 'name' => $programme['name']],
            (string) $fact->value, 'string', 'structured_facts', KnowledgeFact::class, 'database', 'knowledge_facts:'.$fact->id,
            (string) $fact->subject, 'structured_fact', $now, observedAt: $fact->verified_at ? CarbonImmutable::instance($fact->verified_at) : null,
            url: $fact->source_url);
    }

    /**
     * The date the question asks for, from the official academic calendar
     * (AcademicCalendar). A calendar whose year has ended is STALE evidence:
     * kept, but never a current fact.
     */
    private function academicDate(EvidenceRequirement $r, string $query, CarbonImmutable $now): Evidence
    {
        $calendars = app(AcademicCalendar::class);
        $calendar = $calendars->current();
        if ($calendar === null) {
            return $this->none($r, EvidenceStatus::UNAVAILABLE, 'no_record', ['detail' => 'no official academic calendar indexed']);
        }
        $kind = AcademicCalendar::kindAsked(TextFold::fold($query)) ?? 'classes_start';
        ['entries' => $entries, 'next' => $entry, 'recent' => $recent] = $calendars->ofKind($calendar, $kind);
        if ($entry === null && ! $calendar['stale']) {
            return $this->none($r, EvidenceStatus::UNAVAILABLE, 'no_record', ['detail' => 'no upcoming '.$kind]);
        }
        $entry ??= collect($entries)->last();
        if ($entry === null) {
            return $this->none($r, EvidenceStatus::UNAVAILABLE, 'no_record');
        }
        // Just after a term began, that term's start is part of the same
        // answer: context on the one value, not a second, competing value.
        $recent = $kind === 'classes_start' && $recent !== null && $recent !== $entry
            ? ['term' => $recent['term'], 'start' => $recent['start'], 'end' => $recent['end']] : null;

        return $this->found($r, ['type' => 'academic_calendar', 'id' => (string) $calendar['academic_year'], 'name' => 'Akademik takvim '.$calendar['academic_year']],
            ['kind' => $kind, 'label' => $entry['label'], 'term' => $entry['term'], 'start' => $entry['start'], 'end' => $entry['end'],
                'academic_year' => $calendar['academic_year']] + ($recent !== null ? ['recent' => $recent] : []),
            'structured', 'official_knowledge', AcademicCalendar::class, 'official_web', 'knowledge_documents:'.sha1((string) $calendar['source_url']),
            $calendar['title'], 'official_document', isset($calendar['fetched_at']) ? CarbonImmutable::parse($calendar['fetched_at']) : $now,
            url: $calendar['source_url'],
            status: $calendar['stale'] ? EvidenceStatus::STALE : EvidenceStatus::AVAILABLE,
            reason: $calendar['stale'] ? 'calendar_year_ended' : null);
    }

    /**
     * Contact details from the canonical service row: the e-mail and phone in
     * its contact field, verbatim. A staff profile is used only when the task
     * names it; an office row is never presented as a person.
     *
     * @return list<Evidence>
     */
    private function contacts(EvidenceRequirement $r, Task $task, PlanningResult $planning, CarbonImmutable $now): array
    {
        $subject = $this->subject($task, $planning, ['service', 'staff', 'club']);
        if (is_string($subject)) {
            return [$this->none($r, EvidenceStatus::UNAVAILABLE, $subject)];
        }
        $model = match ($subject['type']) {
            'service' => ServiceItem::query()->find($subject['id']),
            'staff' => StaffProfile::query()->find($subject['id']),
            default => Club::query()->find($subject['id']),
        };
        if ($model === null) {
            return [$this->none($r, EvidenceStatus::UNAVAILABLE, 'no_record', [], $subject)];
        }
        // The canonical contact fields of each kind of record, nothing from prose.
        $text = match ($subject['type']) {
            'service' => (string) $model->contact,
            default => (string) $model->email,
        };
        preg_match_all('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', $text, $emails);
        preg_match_all('/\+?\d[\d\s().-]{6,}\d/u', $text, $phones);
        $phones = array_map('trim', $phones[0]);
        if ($subject['type'] === 'service' && trim((string) $model->phone) !== '') {
            array_unshift($phones, trim((string) $model->phone));
        }
        // One value per record: an e-mail and a phone are two channels of
        // the same contact, not two competing answers.
        $value = ['emails' => array_values(array_unique($emails[0])), 'phones' => array_values(array_unique($phones))];
        if ($value['emails'] === [] && $value['phones'] === []) {
            return [$this->none($r, EvidenceStatus::UNAVAILABLE, 'no_authoritative_field', [], $subject)];
        }
        $sourceId = ['service' => 'services:', 'staff' => 'staff_profiles:', 'club' => 'clubs:'][$subject['type']].$subject['id'];

        return [$this->found($r, $subject, $value, 'structured', 'campus_operational',
            class_basename($model), 'database', $sourceId, $subject['name'], 'authoritative_operational', $now)];
    }

    /** @return list<Evidence> */
    private function menus(EvidenceRequirement $r, CarbonImmutable $now): array
    {
        $out = [];
        foreach (FoodDailyMenu::query()->with('venue')->whereDate('menu_date', $now->toDateString())->get() as $menu) {
            $day = CarbonImmutable::instance($menu->menu_date);
            $out[] = $this->found($r, ['type' => 'food_venue', 'id' => (string) $menu->food_venue_id, 'name' => (string) $menu->venue?->name],
                ['items' => array_slice((array) $menu->items, 0, 8), 'price' => $menu->price], 'structured', 'campus_operational',
                FoodDailyMenu::class, 'database', 'food_daily_menus:'.$menu->id, (string) $menu->venue?->name, 'authoritative_operational', $now,
                validFrom: $day->startOfDay(), validUntil: $day->endOfDay());
        }

        return $out !== [] ? $out : [$this->none($r, EvidenceStatus::UNAVAILABLE, 'no_record_for_today')];
    }

    /** @return list<Evidence> */
    private function events(EvidenceRequirement $r, CarbonImmutable $now): array
    {
        $out = [];
        $events = Event::query()->publiclyListed()->whereDate('event_date', '>=', $now->toDateString())
            ->orderBy('event_date')->limit(5)->get();
        foreach ($events as $event) {
            $day = $event->event_date ? CarbonImmutable::instance($event->event_date) : null;
            // The listing is a claim valid while it is published — until it
            // expires or the event's day ends; the event itself may be ahead.
            $until = $event->expires_at ? CarbonImmutable::instance($event->expires_at) : $day?->endOfDay();
            $out[] = $this->found($r, ['type' => 'event', 'id' => (string) $event->id, 'name' => (string) $event->title],
                ['title' => (string) $event->title, 'date' => $day?->toDateString(), 'time' => $event->time, 'place' => $event->place_name],
                'structured', 'campus_operational', Event::class, 'database', 'events:'.$event->id, (string) $event->title,
                'authoritative_operational', $now, publishedAt: $event->publish_at ? CarbonImmutable::instance($event->publish_at) : null,
                validFrom: $event->publish_at ? CarbonImmutable::instance($event->publish_at) : null, validUntil: $until);
        }

        return $out !== [] ? $out : [$this->none($r, EvidenceStatus::UNAVAILABLE, 'no_record')];
    }

    /** @return list<Evidence> */
    private function foodPlaces(EvidenceRequirement $r, CarbonImmutable $now): array
    {
        return FoodVenue::query()->orderBy('name')->get()->map(fn (FoodVenue $v) => $this->found($r,
            ['type' => 'food_venue', 'id' => (string) $v->id, 'name' => (string) $v->name], (string) $v->name, 'string',
            'campus_operational', FoodVenue::class, 'database', 'food_venues:'.$v->id, (string) $v->name, 'authoritative_operational', $now,
        ))->all();
    }

    private function clubSocial(EvidenceRequirement $r, Task $task, PlanningResult $planning, CarbonImmutable $now): Evidence
    {
        $subject = $this->subject($task, $planning, ['club']);
        if (is_string($subject)) {
            return $this->none($r, EvidenceStatus::UNAVAILABLE, $subject);
        }
        // The canonical field only. Handles in a description are prose,
        // not a verified account, and are never promoted to a fact.
        $club = Club::query()->find($subject['id']);
        $profile = trim((string) $club?->instagram_url);
        if ($club === null || $profile === '') {
            return $this->none($r, EvidenceStatus::UNAVAILABLE, 'no_authoritative_field', [], $subject);
        }

        return $this->found($r, $subject, $profile, 'string', 'campus_operational', Club::class, 'database', 'clubs:'.$club->id,
            (string) $club->name, 'authoritative_operational', $now);
    }

    /**
     * The task's final subject, as Phase 3A's executor recorded it — never
     * re-resolved here. Returns a reason code when there is none.
     *
     * @param  list<string>  $types  acceptable entity types, preferred first
     * @return array{type: string, id: string, name: string}|string
     */
    private function subject(Task $task, PlanningResult $planning, array $types): array|string
    {
        $finals = array_values(array_filter($planning->finalEntities, fn (array $f) => $f['task_id'] === $task->id));
        foreach ($types as $type) {
            foreach ($finals as $final) {
                [$finalType, $id] = explode(':', (string) $final['final'], 2) + [1 => ''];
                if ($finalType === $type) {
                    return ['type' => $type, 'id' => $id, 'name' => $this->label($planning, (string) $final['final']) ?? (string) $final['mention']];
                }
            }
        }
        $mine = fn (EntityResolution $res) => in_array($res->mention->surface, $task->mentions, true);
        if (collect($planning->resolutions)->contains(fn (EntityResolution $res) => $mine($res) && $res->status === ResolutionStatus::AMBIGUOUS)) {
            return 'subject_ambiguous';
        }
        // The subject IS known (here, or for another task of the same
        // mention), but the data holds no entity of a usable type for it —
        // e.g. a club has no room or place. A data gap, not a resolution miss.
        $known = collect($planning->resolutions)->contains(fn (EntityResolution $res) => $mine($res) && $res->status === ResolutionStatus::RESOLVED)
            || collect($planning->finalEntities)->contains(fn (array $f) => in_array($f['mention'], $task->mentions, true));

        return $known ? 'no_authoritative_field' : 'subject_unresolved';
    }

    /**
     * Names a passage may use for the task's subject: what the student typed
     * and the resolved entity's canonical label (e.g. "öğrenci işleri",
     * "Öğrenci İşleri (Student Affairs)" → also "student affairs").
     *
     * @return list<string>|null null when the task has no subject to check
     */
    private function subjectTerms(Task $task, PlanningResult $planning): ?array
    {
        $terms = $task->mentions;
        $subject = $this->subject($task, $planning, ['service', 'place', 'staff']);
        if (is_array($subject)) {
            $terms[] = $subject['name'];
            if (preg_match_all('/\(([^)]+)\)/u', $subject['name'], $m)) {
                array_push($terms, ...$m[1]);
            }
            $terms[] = trim((string) preg_replace('/\([^)]*\)/u', '', $subject['name']));
        }
        $terms = array_values(array_unique(array_filter(array_map(fn ($t) => TextFold::fold((string) $t), $terms), fn ($t) => mb_strlen($t) >= 4)));

        return $terms === [] ? null : $terms;
    }

    /** @param list<string> $folded */
    private function mentionsAny(string $text, array $folded): bool
    {
        $haystack = TextFold::fold($text);
        foreach ($folded as $term) {
            if (str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    private function label(PlanningResult $planning, string $ref): ?string
    {
        foreach ($planning->resolutions as $resolution) {
            foreach ($resolution->candidates as $candidate) {
                if ($candidate->ref() === $ref) {
                    return $candidate->label;
                }
            }
        }

        return null;
    }

    private function nextId(EvidenceRequirement $r): string
    {
        $this->ordinals[$r->id] = ($this->ordinals[$r->id] ?? 0) + 1;

        return $r->id.'.e'.$this->ordinals[$r->id];
    }

    private function none(EvidenceRequirement $r, EvidenceStatus $status, string $reason, array $metadata = [], ?array $subject = null): Evidence
    {
        return new Evidence($this->nextId($r), $r->taskId, $r->id, $r->factType, $status, subject: $subject,
            capability: $this->registry->providersFor($r->factType)[0] ?? null, reason: $reason, metadata: $metadata);
    }

    private function found(EvidenceRequirement $r, ?array $subject, mixed $value, string $valueType, string $capability, string $implementation,
        string $sourceType, string $sourceId, ?string $title, string $authority, CarbonImmutable $retrievedAt,
        ?CarbonImmutable $publishedAt = null, ?CarbonImmutable $observedAt = null, ?CarbonImmutable $validFrom = null,
        ?CarbonImmutable $validUntil = null, string $scope = 'generic', ?string $url = null,
        EvidenceStatus $status = EvidenceStatus::AVAILABLE, ?string $reason = null, array $metadata = [], bool $redact = false): Evidence
    {
        return new Evidence($this->nextId($r), $r->taskId, $r->id, $r->factType, $status, $subject, $value, $valueType,
            $capability, class_basename($implementation), $sourceType, $sourceId, $url, $title, $authority,
            $publishedAt, $observedAt, $validFrom, $validUntil, $retrievedAt, $scope, [], $reason,
            $metadata + ($redact ? ['redacted' => true] : []));
    }
}
