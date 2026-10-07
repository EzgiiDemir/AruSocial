<?php

namespace App\Services\Ai\Planning;

use App\Services\Ai\ProgrammeCatalog;
use App\Services\Ai\QueryConcepts;
use App\Services\Ai\QueryPlanner;
use App\Support\PhraseMatcher;
use App\Support\TextFold;

/**
 * What does the user want accomplished? Deterministic, from the message
 * alone (plus trusted conversation turns for references) — never from
 * retrieved content, so a crawled page cannot inject a task.
 *
 * It names task TYPES and their dependencies. It does not know, and must
 * not know, which database or service will answer them: that is
 * ProviderRouter's job, after planning.
 *
 * Only compositional questions are planned. A single recognised intent
 * stays on the existing fast paths (AskOperations, DirectAnswer, the
 * agent), so "bugün yemekte ne var?" pays nothing for planning.
 */
final class TaskPlanner
{
    /**
     * Wording that signals each task type, folded. A clause may hold more
     * than one; each yields one task. Kept to the task types AICAD can act
     * on today. A leading `=` means whole word only: "açık" (open) must not
     * match "açıklama" (explanation).
     *
     * @var array<string, list<string>>
     */
    public const TASK_PATTERNS = [
        'opening_hours' => ['acik mi', 'acik mu', '=acik', 'kacta aciliyor', 'kacta kapaniyor', 'calisma saatleri', 'saat kacta',
            'is it open', 'open today', 'opening hours', 'open now', '=open', 'открыт', 'часы работы'],
        // Asking what to bring is asking for the required documents: the
        // bring/need verb stems ("götürmem lazım", "getirmeliyim", "yanımda
        // ne…"), not whole sentences, so inflected and colloquial forms match.
        'required_documents' => ['hangi belge', 'hangi belgeleri', 'gerekli belge', 'belgeler', 'evrak', 'required documents',
            'goturmem', 'goturmeli', 'getirmem', 'getirmeli', 'yanimda ne', 'yanima ne',
            'what documents', 'which documents', 'what do i need to bring', 'what should i bring', 'need to bring',
            'какие документы', 'документы', 'что взять с собой', 'что нужно принести', 'что принести'],
        // `=gotur`: the imperative only. As a stem it also matched "götürmem"
        // (to bring along), turning "what should I bring" into a route.
        'route' => ['nasil giderim', 'nasil gidilir', 'nasil gidebilirim', 'yol tarifi', '=gotur', 'beni gotur', 'goturur musun',
            'how do i get', 'how can i get', 'take me', 'directions', 'как пройти', 'как добраться', 'проведи'],
        'location' => ['nerede', 'nerde', 'neresi', 'where is', 'where', 'где'],
        'current_menu' => ['yemekte ne var', 'yemek ne', 'ne cikti', 'menu', 'vejetaryen', 'vegan', 'whats for lunch',
            'what is for lunch', 'меню'],
        'current_events' => ['etkinlik', 'etkinlikler', 'event', 'events', 'мероприят'],
        // How to reach an office: phone, e-mail. Not a bare "numara" ("oda numarası" is a room).
        'contact_details' => ['telefon', 'numarasi ne', 'numarasi kac', 'arasam', 'arayabil', 'nasil ararim', '=mail', 'maili', 'mail adresi', 'e-posta', 'eposta', 'iletisim bilgi',
            'phone number', 'email', 'e-mail', 'contact details', 'how can i contact', 'who should i email', 'телефон', 'почта', 'электронн', 'связаться'],
        // How long a programme takes. Kept only when the message names a
        // programme (typesIn): "how long" alone is usually a walk.
        'programme_duration' => ['kac yil', 'kac sene', 'kac donem', 'egitim suresi', 'ogrenim suresi', 'okuma suresi', 'kac yillik', 'kac senelik',
            'how long', 'how many years', 'duration', 'сколько лет', 'сколько длится', 'длительность', 'срок обучения', 'сколько учиться'],
        // `=insta`: the common short form, as a whole word only ("instalasyon" is not Instagram).
        'club_social_profile' => ['instagram', '=insta', 'insta hesab', 'sosyal medya', 'social media', 'инстаграм', 'соцсети'],
    ];

    private const NEAREST = ['en yakin', 'nearest', 'closest', 'ближайш'];

    /** An open place, not one named venue ("açık yer var mı", "somewhere open"). */
    private const OPEN_PLACE = ['acik yer', 'acik bir yer', 'acik olan yer', 'acik mekan', 'somewhere open', 'place open', 'places open', 'открытое место'];

    /** Wanting to eat, said without a food noun ("bir şeyler yiyebileceğim"). Verb stems, folded. */
    private const EATING = ['yiyebilecegim', 'yiyebilecegimiz', 'yemek yiyebil', 'something to eat', 'grab a bite', 'поесть', 'перекусить'];

    private const OPEN_NOW = ['acik olan', '=acik', 'open now', '=open', 'открыт'];

    /**
     * Asking whether ANY such place exists, not about one named venue:
     * "açık yemek yeri var mı", "anywhere open to eat", "есть открытое кафе".
     * With an open word and a food word it starts the open-food chain;
     * "yemekhane açık mı" (one venue, no marker) keeps its own task.
     */
    private const INDEFINITE = ['var mi', 'var mı', 'herhangi', 'bir yer', '=any', 'anywhere', 'somewhere', 'есть ли', '=есть', '=можно', 'где-нибудь'];

    /** "Now" said with an eating verb means open now: "где сейчас можно поесть". */
    private const NOW = ['su an', 'simdi', '=now', 'right now', 'сейчас'];

    private const EAT_VERBS = ['yiyebil', '=eat', 'поесть', 'перекусить'];

    /** Task type → the existing fast-path handler a single intent goes to (for the trace only). */
    private const HANDLERS = [
        'current_menu' => 'food.daily_menu', 'current_events' => 'events.listing', 'location' => 'place.card',
        'route' => 'routing.route', 'opening_hours' => 'service.hours', 'required_documents' => 'knowledge.retrieval',
        'program_language' => 'programs.facts', 'club_social_profile' => 'clubs.profile', 'academic_dates' => 'calendar.direct',
        'contact_details' => 'contact.card', 'programme_duration' => 'programs.facts',
    ];

    public function __construct(private readonly QueryConcepts $concepts) {}

    /**
     * @param  list<EntityResolution>  $resolutions
     * @param  list<Mention>  $references
     * @return array{0: PlanningDecision, 1: ?TaskPlan}
     */
    public function plan(string $query, array $resolutions, array $references): array
    {
        $folded = TextFold::fold($query);
        $tasks = $this->chain($folded) ?? $this->independent($folded, $resolutions, $references);

        if ($tasks === []) {
            return [new PlanningDecision(PlanningDecision::FAST_PATH, 'no task pattern recognised', 'legacy'), null];
        }
        $plan = new TaskPlan($tasks);
        $hasDependency = collect($tasks)->contains(fn (Task $t) => $t->dependsOn !== []);
        if (count($tasks) === 1) {
            return [new PlanningDecision(PlanningDecision::FAST_PATH, 'single deterministic intent: '.$tasks[0]->type,
                self::HANDLERS[$tasks[0]->type] ?? 'legacy'), $plan];
        }

        return [new PlanningDecision(PlanningDecision::PLANNED, $hasDependency ? 'dependency_chain' : 'multi_intent_compositional_query'), $plan];
    }

    /**
     * "Bugün açık olan en yakın yemek yerine götür": find → filter open →
     * rank by distance → route, each depending on the previous. Recognised
     * when a food word appears with "nearest" — or with "open now", which
     * asks for the open places without ranking them ("bugün bir şeyler
     * yiyebileceğim açık yer var mı").
     *
     * @return list<Task>|null
     */
    private function chain(string $folded): ?array
    {
        $nearest = $this->any($folded, self::NEAREST);
        $openNow = $this->any($folded, self::OPEN_NOW) || ($this->any($folded, self::NOW) && $this->any($folded, self::EAT_VERBS));
        // Without "nearest", only an open PLACE — said outright, or as "is there
        // any…" — starts the chain: "yemekhane açık mı" asks about one venue
        // and keeps its own tasks.
        $openPlace = $this->any($folded, self::OPEN_PLACE) || ($openNow && $this->any($folded, self::INDEFINITE));
        if ((! $nearest && ! $openPlace) || ! $this->any($folded, [...QueryPlanner::LEXICON['food'], ...self::EATING])) {
            return null;
        }
        $tasks = [new Task('t1', 'find_food_places')];
        $previous = 't1';
        if ($openNow) {
            $tasks[] = new Task('t2', 'filter_open_now', [$previous]);
            $previous = 't2';
        }
        if (! $nearest) {
            return $tasks;
        }
        $id = 't'.(count($tasks) + 1);
        $tasks[] = new Task($id, 'rank_by_distance', [$previous]);
        if ($this->any($folded, self::TASK_PATTERNS['route'])) {
            $tasks[] = new Task('t'.(count($tasks) + 1), 'route', [$id]);
        }

        return $tasks;
    }

    /**
     * Independent tasks, one clause at a time. A clause with no entity of
     * its own shares the message's subject ("Öğrenci işleri bugün açık mı,
     * hangi belgeleri götürmeliyim" — the documents are for the same office).
     *
     * @param  list<EntityResolution>  $resolutions
     * @param  list<Mention>  $references
     * @return list<Task>
     */
    private function independent(string $folded, array $resolutions, array $references): array
    {
        $types = [];
        $offset = 0;
        foreach (preg_split('/\s*(?:,|;|\?|\bve\b|\band\b|\bи\b)\s*/u', $folded) ?: [] as $clause) {
            $start = mb_strpos($folded, $clause, $offset);
            $start = $start === false ? $offset : $start;
            $end = $start + mb_strlen($clause);
            $offset = $end;
            if (trim($clause) === '') {
                continue;
            }
            $clauseTypes = $this->typesIn($clause, $folded);
            foreach ($clauseTypes as $type) {
                $types[$type] ??= ['mentions' => []];
                foreach ($resolutions as $r) {
                    if ($r->mention->start >= $start && $r->mention->start < $end) {
                        $types[$type]['mentions'][] = $r->mention->surface;
                    }
                }
                foreach ($references as $ref) {
                    if ($ref->start >= $start && $ref->start < $end) {
                        $types[$type]['mentions'][] = $ref->surface;
                    }
                }
            }
        }

        // "Where is it and how do I get there" asks for one thing twice:
        // a route already locates its destination.
        if (isset($types['route'], $types['location'])) {
            unset($types['location']);
        }

        // The message's subject: its named entities, else what its
        // conversation reference points at ("oraya … açık mı?").
        $subject = array_values(array_unique(array_map(fn ($r) => $r->mention->surface, $resolutions)));
        if ($subject === []) {
            $subject = array_values(array_unique(array_map(fn (Mention $m) => $m->surface, $references)));
        }
        $tasks = [];
        $n = 0;
        foreach ($types as $type => $info) {
            $mentions = array_values(array_unique($info['mentions']));
            if ($mentions === [] && $this->needsSubject($type)) {
                $mentions = $subject;
            }
            $tasks[] = new Task('t'.(++$n), $type, [], $mentions);
        }

        return $tasks;
    }

    /** @return list<string> */
    private function typesIn(string $clause, string $message): array
    {
        $found = [];
        foreach (self::TASK_PATTERNS as $type => $patterns) {
            if ($this->any($clause, $patterns)) {
                $found[] = $type;
            }
        }
        foreach ($this->concepts->match($clause) as $concept) {
            if ($concept['concept'] === 'language_of_instruction') {
                $found[] = 'program_language';
            }
            if ($concept['concept'] === 'academic_calendar') {
                $found[] = 'academic_dates';
            }
        }
        if (in_array('programme_duration', $found, true)) {
            // The whole message: a programme name can contain "ve"/"and", which splits clauses.
            if (app(ProgrammeCatalog::class)->inText($message) === []) {
                $found = array_diff($found, ['programme_duration']);
            } else {
                // "Grafik tasarım kaç yıl sürüyor": a duration, not a walk or a schedule.
                $found = array_diff($found, ['route', 'opening_hours']);
            }
        }

        return array_values(array_unique($found));
    }

    public function needsSubject(string $type): bool
    {
        return in_array($type, ['opening_hours', 'required_documents', 'route', 'location', 'club_social_profile', 'contact_details'], true);
    }

    /** @param list<string> $patterns */
    private function any(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $exact = str_starts_with($pattern, '=');
            if (PhraseMatcher::position($text, TextFold::fold(ltrim($pattern, '=')), $exact ? PHP_INT_MAX : 4) !== null) {
                return true;
            }
        }

        return false;
    }
}
