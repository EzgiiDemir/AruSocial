<?php

namespace App\Services\Ai\Planning;

use App\Models\Club;
use App\Models\FoodVenue;
use App\Models\KnowledgeFact;
use App\Models\OpeningHour;
use App\Models\Place;
use App\Models\ServiceItem;
use App\Models\Sport;
use App\Support\TextFold;
use Carbon\CarbonInterface;

/**
 * Executes a TaskPlan's DAG with explicit states, wave by wave.
 *
 * Phase 3A scope: each task establishes whether its evidence is available
 * from its routed provider (the canonical rows AICAD already reads) and
 * which entity it is about — final, task-specific resolution. The answer is
 * still produced by the existing pipeline; this decides what that pipeline
 * must be given and records what cannot be known.
 *
 * Invariants: a task runs only when every dependency COMPLETED; a FAILED or
 * BLOCKED dependency BLOCKS it. Nothing downstream ever runs on invented
 * data — no destination, no coordinates, no route.
 */
final class TaskExecutor
{
    /** @var list<array<string, mixed>> */
    private array $finalEntities = [];

    /**
     * @param  list<EntityResolution>  $resolutions
     * @param  array<string, array{resolution: EntityResolution, source: string}>  $referents  reference surface → what it points at
     * @param  list<ProviderRoute>  $routes
     * @param  array{lat?: mixed, lng?: mixed}|null  $location
     * @return array{states: list<TaskExecutionState>, final_entities: list<array<string, mixed>>}
     */
    public function execute(TaskPlan $plan, array $resolutions, array $referents, array $routes, ?array $location, CarbonInterface $now): array
    {
        $this->finalEntities = [];
        $states = [];
        $outputs = [];
        foreach ($plan->topologicalOrder() as $waveIndex => $wave) {
            foreach ($wave as $taskId) {
                $task = $plan->task($taskId);
                $failedDependency = collect($task->dependsOn)->first(fn ($d) => ($states[$d]->state ?? null) !== TaskState::COMPLETED);
                if ($failedDependency !== null) {
                    $states[$taskId] = new TaskExecutionState($taskId, $task->type, TaskState::BLOCKED,
                        "dependency {$failedDependency} ({$states[$failedDependency]->taskType}) did not complete", [], $waveIndex);

                    continue;
                }
                $unrouted = collect($routes)->first(fn (ProviderRoute $r) => $r->taskId === $taskId && ! $r->routed());
                if ($unrouted !== null) {
                    $states[$taskId] = new TaskExecutionState($taskId, $task->type, TaskState::FAILED,
                        "no provider can supply {$unrouted->factType}", [], $waveIndex);

                    continue;
                }
                $previous = [];
                foreach ($task->dependsOn as $d) {
                    $previous += $outputs[$d] ?? [];
                }
                [$state, $reason, $output] = $this->run($task, $resolutions, $referents, $previous, $location, $now);
                $states[$taskId] = new TaskExecutionState($taskId, $task->type, $state, $reason, $output, $waveIndex);
                $outputs[$taskId] = $output;
            }
        }

        return ['states' => array_values($states), 'final_entities' => $this->finalEntities];
    }

    /** @return array{0: TaskState, 1: string, 2: array<string, mixed>} */
    private function run(Task $task, array $resolutions, array $referents, array $previous, ?array $location, CarbonInterface $now): array
    {
        $ok = fn (string $reason, array $output = []) => [TaskState::COMPLETED, $reason, $output];
        $fail = fn (string $reason) => [TaskState::FAILED, $reason, []];

        switch ($task->type) {
            case 'opening_hours':
                $subject = $this->subject($task, $resolutions, $referents, ['service', 'place']);
                if (is_string($subject)) {
                    return $fail($subject);
                }
                $service = $subject['type'] === 'service' ? ServiceItem::query()->find($subject['id']) : null;
                if ($service === null || trim((string) $service->hours) === '') {
                    return $fail('no opening hours recorded for '.$subject['label']);
                }

                return $ok('hours recorded', ['hours' => $service->hours, 'open_now' => OpeningHours::isOpen((string) $service->hours, $now)]);

            case 'required_documents':
                $subject = $this->subject($task, $resolutions, $referents, ['service', 'place', 'staff']);

                // Unstructured: answered by the existing official-knowledge
                // retrieval in the prompt. Recorded as delegated, not as found.
                return $ok('delegated to official knowledge retrieval', ['delegated_to' => 'official_knowledge']
                    + (is_array($subject) ? ['subject' => $subject['type'].':'.$subject['id']] : []));

            case 'location':
            case 'route':
                if ($previous !== [] && isset($previous['place_id'])) {
                    $place = Place::query()->find($previous['place_id']);
                } else {
                    $subject = $this->subject($task, $resolutions, $referents, ['place', 'service', 'club', 'sport', 'food_venue']);
                    if (is_string($subject)) {
                        return $fail($subject);
                    }
                    $place = $this->placeFor($task, $subject);
                }
                if ($place === null || $place->lat === null || $place->lng === null) {
                    return $fail('destination has no place with coordinates');
                }
                if ($task->type === 'location') {
                    return $ok('place resolved', ['place_id' => (string) $place->id]);
                }
                if (! is_numeric($location['lat'] ?? null) || ! is_numeric($location['lng'] ?? null)) {
                    return $fail('origin unknown: no current location in the request');
                }

                return $ok('destination and origin known; route computed by RoutingService', ['place_id' => (string) $place->id]);

            case 'current_menu':
                $venues = FoodVenue::query()->with(['dailyMenus' => fn ($q) => $q->whereDate('menu_date', $now->toDateString())])->get();
                if ($venues->isEmpty()) {
                    return $fail('no food venue recorded');
                }

                return $ok('food venues read', ['venues' => $venues->count(), 'menus_today' => $venues->filter(fn ($v) => $v->dailyMenus->isNotEmpty())->count()]);

            case 'current_events':
                return $ok('live events read', []);

            case 'program_language':
                return KnowledgeFact::query()->where('attribute', KnowledgeFact::LANGUAGE)->exists()
                    ? $ok('programme facts available', ['facts' => KnowledgeFact::query()->where('attribute', KnowledgeFact::LANGUAGE)->count()])
                    : $ok('delegated to official knowledge retrieval', ['delegated_to' => 'official_knowledge']);

            case 'programme_duration':
                return KnowledgeFact::query()->where('attribute', KnowledgeFact::DURATION)->exists()
                    ? $ok('programme facts available', ['facts' => KnowledgeFact::query()->where('attribute', KnowledgeFact::DURATION)->count()])
                    : $fail('no programme duration recorded');

            case 'academic_dates':
                return $ok('delegated to the official academic calendar', ['delegated_to' => 'official_knowledge']);

            case 'contact_details':
                $subject = $this->subject($task, $resolutions, $referents, ['service', 'staff', 'club']);
                if (is_string($subject)) {
                    return $fail($subject);
                }

                return $ok('contact subject resolved', ['subject' => $subject['type'].':'.$subject['id']]);

            case 'club_social_profile':
                $subject = $this->subject($task, $resolutions, $referents, ['club']);
                if (is_string($subject)) {
                    return $fail($subject);
                }
                // The canonical field only: a handle in the description is
                // prose anyone could have typed, not a verified account.
                $club = Club::query()->find($subject['id']);
                if (trim((string) $club?->instagram_url) === '') {
                    return $fail('no social profile recorded for '.$subject['label']);
                }

                return $ok('social profile recorded', ['club_id' => $subject['id']]);

            case 'find_food_places':
                $ids = FoodVenue::query()->pluck('id')->map(fn ($id) => (string) $id)->all();

                return $ids === [] ? $fail('no food venue recorded') : $ok('food venues found', ['venue_ids' => $ids]);

            case 'filter_open_now':
                $open = [];
                $known = 0;
                foreach (FoodVenue::query()->whereIn('id', $previous['venue_ids'] ?? [])->get() as $venue) {
                    $isOpen = OpeningHour::openAt('food_venue', (string) $venue->id, $now) ?? OpeningHours::isOpen((string) $venue->hours, $now);
                    if ($isOpen !== null) {
                        $known++;
                    }
                    if ($isOpen === true) {
                        $open[] = (string) $venue->id;
                    }
                }
                if ($known === 0) {
                    return $fail('opening hours not recorded for any food venue');
                }

                return $open === [] ? $fail('no food venue is open now') : $ok('open venues filtered', ['venue_ids' => $open]);

            case 'rank_by_distance':
                if (! is_numeric($location['lat'] ?? null) || ! is_numeric($location['lng'] ?? null)) {
                    return $fail('origin unknown: no current location in the request');
                }
                $best = null;
                foreach (FoodVenue::query()->whereIn('id', $previous['venue_ids'] ?? [])->get() as $venue) {
                    $place = self::venuePlace($venue);
                    if ($place?->lat !== null) {
                        $d = hypot((float) $place->lat - (float) $location['lat'], (float) $place->lng - (float) $location['lng']);
                        if ($best === null || $d < $best[0]) {
                            $best = [$d, $place];
                        }
                    }
                }

                return $best === null
                    ? $fail('no candidate venue has a place with coordinates')
                    : $ok('nearest venue ranked', ['place_id' => (string) $best[1]->id]);
        }

        return $fail('unknown task type');
    }

    /**
     * Task-specific final resolution: the task's mention (or the referent of
     * its conversation reference), narrowed to an entity type the task can
     * use. Recorded with the preliminary candidate it came from.
     *
     * @param  list<string>  $allowedTypes  in preference order
     * @return array{type: string, id: string, label: string}|string entity, or the failure reason
     */
    private function subject(Task $task, array $resolutions, array $referents, array $allowedTypes): array|string
    {
        foreach ($task->mentions as $surface) {
            $via = null;
            if (isset($referents[$surface])) {
                $resolution = $referents[$surface]['resolution'];
                $via = 'conversation reference "'.$surface.'" ('.$referents[$surface]['source'].') → "'.$resolution->mention->surface.'"; ';
            } else {
                $resolution = collect($resolutions)->first(fn (EntityResolution $r) => $r->mention->surface === $surface);
            }
            if ($resolution === null) {
                continue;
            }
            if ($resolution->status === ResolutionStatus::AMBIGUOUS) {
                return 'subject "'.$surface.'" is ambiguous: '.implode(', ', array_map(fn ($c) => $c->label, $resolution->candidates));
            }
            foreach ($allowedTypes as $type) {
                foreach ($resolution->candidates as $candidate) {
                    if ($candidate->entityType === $type) {
                        $preliminary = $resolution->candidates[0];
                        $this->finalEntities[] = ['task_id' => $task->id, 'mention' => $surface,
                            'preliminary' => $preliminary->ref(), 'final' => $candidate->ref(),
                            'reason' => ($via ?? '').($preliminary->ref() === $candidate->ref()
                                ? 'preliminary best fits the task'
                                : "task {$task->type} needs a {$type}; preliminary best was {$preliminary->entityType}")];

                        return ['type' => $candidate->entityType, 'id' => $candidate->entityId, 'label' => $candidate->label];
                    }
                }
            }

            return 'subject "'.$surface.'" resolved to no '.implode('/', $allowedTypes);
        }

        return 'subject unresolved: no recognised entity'.($task->mentions !== [] ? ' for "'.implode('", "', $task->mentions).'"' : '');
    }

    /**
     * The campus place a food venue is in: its canonical place_id, else
     * matched by name (venues carry no coordinates of their own).
     */
    public static function venuePlace(FoodVenue $venue): ?Place
    {
        if ($venue->place_id !== null) {
            return Place::query()->find($venue->place_id);
        }
        $name = TextFold::fold((string) $venue->name);

        return Place::query()->get()->first(fn (Place $p) => TextFold::fold((string) $p->name) !== ''
            && str_contains($name, TextFold::fold((string) $p->name)));
    }

    /** The room a club meets in, from its canonical place_id only. */
    public static function clubPlace(?Club $club): ?Place
    {
        return $club?->place_id === null ? null : Place::query()->find($club->place_id);
    }

    /** The building a service is in, as a campus place. */
    public static function servicePlace(ServiceItem $service): ?Place
    {
        $building = TextFold::fold((string) $service->building);

        return $building === '' ? null : Place::query()->get()->first(fn (Place $p) => TextFold::fold((string) $p->name) === $building);
    }

    /** A service is navigated to through the building it is in. */
    private function placeFor(Task $task, array $subject): ?Place
    {
        if ($subject['type'] === 'place') {
            return Place::query()->find($subject['id']);
        }
        if ($subject['type'] === 'club') {
            // A club's room, when staff recorded one; never guessed.
            return self::clubPlace(Club::query()->find($subject['id']));
        }
        if ($subject['type'] === 'food_venue') {
            $venue = FoodVenue::query()->find($subject['id']);

            return $venue === null ? null : self::venuePlace($venue);
        }
        if ($subject['type'] === 'sport') {
            // Where the team plays, from its canonical place_id only.
            $sport = Sport::query()->find($subject['id']);

            return $sport?->place_id === null ? null : Place::query()->find($sport->place_id);
        }
        $service = ServiceItem::query()->find($subject['id']);
        $place = $service === null ? null : self::servicePlace($service);
        if ($place !== null) {
            $this->finalEntities[] = ['task_id' => $task->id, 'mention' => $subject['label'],
                'preliminary' => 'service:'.$subject['id'], 'final' => 'place:'.$place->id,
                'reason' => 'navigation goes to the building the service is in ('.$service->building.')'];
        }

        return $place;
    }
}
