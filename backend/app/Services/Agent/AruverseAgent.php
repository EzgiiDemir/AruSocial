<?php

namespace App\Services\Agent;

use App\Models\AcademicYear;
use App\Models\AiEntityAlias;
use App\Models\CareerOpportunity;
use App\Models\Club;
use App\Models\Consultation;
use App\Models\DirectoryEntry;
use App\Models\Event;
use App\Models\FoodVenue;
use App\Models\KnowledgeFact;
use App\Models\Place;
use App\Models\ServiceItem;
use App\Models\ShuttleRoute;
use App\Models\Sport;
use App\Models\StaffProfile;
use App\Services\Ai\AcademicCalendar;
use App\Services\Ai\AskTrace;
use App\Services\Ai\Evidence\EvidenceBudget;
use App\Services\Ai\Facts\FactPromptBlock;
use App\Services\Ai\Facts\SupportedFactsRollout;
use App\Services\Ai\Planning\PlanningResult;
use App\Services\Ai\ProgrammeCatalog;
use App\Services\Ai\QueryPlanner;
use App\Services\Ai\SourceAuthority;
use App\Services\Knowledge\KnowledgeBase;
use App\Support\SchemaColumnCache;
use App\Support\TextFold;

/**
 * The ARUVERSE Agent: a CONTROLLED intent router, not an autonomous agent.
 *
 * It reads the user's question, decides which internal sources are relevant,
 * gathers grounded facts from exactly those, and returns a combined context
 * for the LLM to phrase. It never takes actions and never calls anything but
 * the fixed, read-only tools registered in tools(). Website knowledge carries
 * its source URL so answers can cite it.
 *
 * Every tool is:
 *   - explicit (listed here, nothing is discovered at runtime),
 *   - read-only (a query against existing models / the knowledge base),
 *   - scoped (returns only formatted facts, never executes anything).
 *
 * This is the boundary the spec calls for: crawled/DB content becomes DATA in
 * the prompt, never instructions, and the agent cannot grant itself new tools.
 */
class AruverseAgent
{
    public function __construct(
        private readonly KnowledgeBase $knowledge,
        private readonly QueryPlanner $planner,
    ) {}

    /**
     * Build focused, sourced context for a question.
     *
     * `sources` is what the backend actually handed to the model, so the
     * API can cite it directly instead of trusting the model to report its
     * own citations honestly.
     *
     * Blocks are ordered by SourceAuthority — our own tables before
     * crawled web text — and `sources` carries each one's authority,
     * visibility and freshness so the API can cite it honestly.
     *
     * @return array{tools: list<string>, context: string, hasWebSources: bool, sources: list<array<string, mixed>>, blocks: list<array{id: string, chars: int}>}
     */
    public function buildContext(string $query, ?PlanningResult $planning = null): array
    {
        // If a matching high-value page (for example the academic calendar)
        // has not been indexed yet, read that approved URL now before doing
        // retrieval. This is bounded and allow-listed by KnowledgeBase.
        $this->knowledge->refreshFor($query);

        // Which domains the question is about, and the entities it names.
        // QueryPlanner owns the vocabulary; knowledge always runs below
        // (it self-filters). See QueryPlanner for why this is not a keyword
        // overlap any more.
        $plan = $this->planner->plan($query);
        $resolvedIds = [];
        foreach ($plan['entities'] as $entity) {
            $resolvedIds[$entity['type']][$entity['id']] = true;
        }
        $tools = $this->tools($query, $resolvedIds);
        $selectedKeys = array_values(array_filter($plan['tools'], fn ($key) => isset($tools[$key])));
        // A planned question also needs the tools its routed providers use
        // (ProviderRouter), even where the domain vote did not pick them.
        if ($planning !== null && $planning->planned()) {
            foreach ($planning->agentTools as $tool) {
                if (isset($tools[$tool]) && ! in_array($tool, $selectedKeys, true)) {
                    $selectedKeys[] = $tool;
                }
            }
        }

        $trace = app(AskTrace::class);
        $trace->record('routing', [
            'query' => $query,
            'normalized' => $plan['normalized'],
            'domains' => $plan['domains'],
            'tools' => $selectedKeys,
            'fallback' => $plan['fallback'],
            'timings' => $plan['timings'],
        ]);
        $trace->record('entities', ['resolved' => $plan['entities']]);

        // Phase 3C: a planned question generated from SupportedFacts gets
        // the fact contract alone — the raw tool rows and retrieved pages
        // behind those facts are not handed over again.
        if ($planning !== null && $planning->generatesFromFacts()) {
            $rollout = app(SupportedFactsRollout::class);
            if ($rollout->prepared() !== null || $rollout->prepare($planning)) {
                return $this->factContext($rollout->prepared(), $plan['entities']);
            }
            // A technical failure of the fact block: prepare() recorded it,
            // and the legacy context below is used only where that is safe.
        }

        $used = [];
        $sources = [];

        /*
         * Blocks carry their authority so the prompt can be ordered by it.
         *
         * Our own tables go FIRST and the crawled web LAST, which is the
         * reverse of how this was built. A crawled page is a snapshot of
         * what a page said when the crawler last read it; the events table
         * is what is true now. Putting the snapshot first invited the
         * model to answer from it and mention the database as a footnote
         * — and on anything that moves (an event time, a shuttle
         * departure, an office's hours) that is how a stale fact gets
         * stated confidently.
         */
        $ranked = [];

        $rowCounts = [];
        $toolMs = [];
        foreach ($selectedKeys as $key) {
            $toolStarted = microtime(true);
            $lines = ($tools[$key]['gather'])();
            $toolMs[$key] = round((microtime(true) - $toolStarted) * 1000, 1);
            $rowCounts[$key] = count($lines);
            $trace->detail('tool.'.$key, fn () => ['rows' => $lines]);
            if ($lines === []) {
                continue;
            }
            $used[] = $key;
            $ranked[] = [
                'id' => 'tool:'.$key,
                'authority' => SourceAuthority::OPERATIONAL,
                'text' => $tools[$key]['label']."\n".implode("\n", $lines),
            ];
            // An internal tool is a real source too — "the campus events
            // table" is a more honest citation than silence, and it has
            // no URL to link, which is why the type is distinguished
            // rather than the entry omitted.
            $sources[] = [
                'type' => 'campus',
                'title' => rtrim((string) $tools[$key]['label'], ':'),
                'url' => '',
                'id' => 'tool:'.$key,
                'authority' => SourceAuthority::OPERATIONAL,
                'visibility' => SourceAuthority::VISIBILITY_PUBLIC,
                // Read at question time, from the row as it stands.
                'freshness' => SourceAuthority::FRESHNESS_LIVE,
                'updatedAt' => null,
                'stale' => false,
            ];
        }

        // A planned question: what each task established or could not, from
        // campus rows only. Without it the model would see the data for
        // "is it open" and silently answer "how do I get there" from nothing.
        if ($planning !== null && $planning->planned()) {
            $ranked[] = [
                'id' => 'tool:tasks',
                // First among the campus blocks: it is this question's own
                // per-task evidence, already budgeted, and the context budget
                // cuts from the end — a long generic tool listing must not
                // push it (and the document excerpt it carries) out.
                'authority' => $planning->evidence !== null ? SourceAuthority::OPERATIONAL + 1 : SourceAuthority::OPERATIONAL,
                'text' => $this->taskBlock($planning),
            ];
        }

        // One phrase naming several rows of a type ("idari bina" → two
        // buildings): tell the model, so it asks or names both instead of
        // picking one. EntityResolver marks it; nothing here guesses.
        $ambiguous = [];
        foreach ($plan['entities'] as $entity) {
            if ($entity['ambiguous'] ?? false) {
                $ambiguous[$entity['matched']][] = $entity['name'];
            }
        }
        foreach ($ambiguous as $phrase => $names) {
            $ranked[] = [
                'id' => 'ambiguity',
                'authority' => SourceAuthority::OPERATIONAL,
                'text' => "BELİRSİZ AD: \"{$phrase}\" birden fazla kayda karşılık geliyor: "
                    .implode(', ', $names).'. Birini seçme; hangisini kastettiğini sor ya da hepsini belirt.',
            ];
        }

        // Website knowledge always runs; it returns nothing unless relevant.
        // Structured data is answering (a resolved entity whose table
        // returned rows): a page found only by vague similarity must not
        // ride along into the prompt or the citations.
        $structuredAnswer = $plan['entities'] !== [] && array_filter($rowCounts) !== [];
        $web = $this->knowledge->contextWithSources($query, 4, $structuredAnswer, $planning?->evidence?->knowledgeHitsFor($query, 4));
        $hasWeb = $web['block'] !== '';
        if ($hasWeb) {
            $used[] = 'knowledge';
            $ranked[] = [
                'id' => 'knowledge',
                'authority' => SourceAuthority::WEB,
                'text' => $web['block'],
            ];
            $sources = array_merge($sources, $web['sources']);
        }

        usort($ranked, fn ($a, $b) => $b['authority'] <=> $a['authority']);

        $trace->record('database', ['rows' => $rowCounts, 'tool_ms' => $toolMs, 'knowledge_block' => $hasWeb]);

        return [
            'tools' => $used,
            'context' => implode("\n\n", array_column($ranked, 'text')),
            'hasWebSources' => $hasWeb,
            'sources' => SourceAuthority::rank($sources),
            // In prompt order, so the budget step can say which blocks it cut.
            'blocks' => array_map(fn (array $b) => ['id' => $b['id'], 'chars' => mb_strlen($b['text'])], $ranked),
        ];
    }

    /**
     * The context of a fact-generated answer: the fact block, plus the
     * ambiguity notice when one phrase named several rows.
     *
     * @param  array{text: string, sources: list<array<string, mixed>>}  $built  the prepared fact block
     * @param  list<array<string, mixed>>  $entities
     * @return array{tools: list<string>, context: string, hasWebSources: bool, sources: list<array<string, mixed>>, blocks: list<array{id: string, chars: int}>}
     */
    private function factContext(array $built, array $entities): array
    {
        $blocks = [['id' => FactPromptBlock::BLOCK_ID, 'text' => $built['text']]];
        $ambiguous = [];
        foreach ($entities as $entity) {
            if ($entity['ambiguous'] ?? false) {
                $ambiguous[$entity['matched']][] = $entity['name'];
            }
        }
        foreach ($ambiguous as $phrase => $names) {
            $blocks[] = ['id' => 'ambiguity', 'text' => "BELİRSİZ AD: \"{$phrase}\" birden fazla kayda karşılık geliyor: "
                .implode(', ', $names).'. Birini seçme; hangisini kastettiğini sor ya da hepsini belirt.'];
        }

        return [
            'tools' => ['facts'],
            'context' => implode("\n\n", array_column($blocks, 'text')),
            'hasWebSources' => collect($built['sources'])->contains(fn ($s) => in_array($s['type'], ['web', 'pdf'], true)),
            'sources' => SourceAuthority::rank($built['sources']),
            'blocks' => array_map(fn (array $b) => ['id' => $b['id'], 'chars' => mb_strlen($b['text'])], $blocks),
        ];
    }

    /**
     * One line per planned task: its outcome and, when it failed, why. With
     * Phase 3B, the task-aware evidence block within its own budget.
     */
    private function taskBlock(PlanningResult $planning): string
    {
        if ($planning->evidence !== null) {
            return app(EvidenceBudget::class)->build($planning, (int) config('ai.evidence.budget_chars', 1200))['text'];
        }

        $lines = ['SORUDAKİ GÖREVLER (kampüs verisiyle doğrulanmış durum; başarısız bir görevi uydurma, eksik olduğunu söyle):'];
        foreach ($planning->states as $state) {
            $detail = match (true) {
                isset($state->output['hours']) => ' — çalışma saatleri: '.$state->output['hours']
                    .($state->output['open_now'] === null ? '' : ($state->output['open_now'] ? ' (şu an açık)' : ' (şu an kapalı)')),
                isset($state->output['place_id']) => ' — yer: '.$state->output['place_id'],
                default => '',
            };
            $lines[] = '- '.$state->taskType.': '.match ($state->state->value) {
                'COMPLETED' => 'tamam'.$detail,
                'FAILED' => 'yapılamadı ('.$state->reason.')',
                'BLOCKED' => 'yapılamadı (önceki adım tamamlanamadı)',
                default => strtolower($state->state->value),
            };
        }

        return implode("\n", $lines);
    }

    /**
     * The explicit tool table. Each entry is a fixed capability: a label and
     * a read-only gather closure. Which tools run for a question is decided
     * by QueryPlanner, not here.
     *
     * `$query` is passed so a tool can answer the question that was
     * actually asked rather than dumping its whole table — the places
     * tool ranks by it. Tools that do not need it ignore it, and the
     * empty default keeps the roster callable for listings (the admin
     * integrations page enumerates the tool names).
     *
     * `$resolved` is type => id => true for the entities EntityResolver
     * found, so a place named only by an alias ("gym") is still described
     * first even though no word of the question appears in its row.
     *
     * @param  array<string, array<string, true>>  $resolved
     * @return array<string, array{label: string, gather: callable(): list<string>}>
     */
    public function tools(string $query = '', array $resolved = []): array
    {
        return [
            'knowledge' => [
                'label' => 'ARUCAD web siteleri',
                'gather' => fn () => [],
            ],
            'places' => [
                'label' => 'Kampüs yerleri:',
                'gather' => function () use ($query, $resolved) {
                    $rows = Place::query()->limit(120)->get();

                    // Legacy `place-*` rows duplicate a canonical place under
                    // the same name. The API and the app both hide them; the
                    // agent must too, or the same building is listed twice.
                    $byName = [];
                    foreach ($rows as $p) {
                        $key = TextFold::fold(trim((string) $p->name));
                        if (! isset($byName[$key]) || str_starts_with((string) $byName[$key]->id, 'place-')) {
                            $byName[$key] = $p;
                        }
                    }

                    /*
                     * Rank by the question, then answer in depth.
                     *
                     * Every place used to be listed on every location
                     * question, one line each. That spent the grounding
                     * budget on sixty buildings the student did not ask
                     * about, and still left out what they did ask —
                     * "where is it exactly", "is there a lift", "what is
                     * it for". Matching first means the few places that
                     * are relevant can be described properly.
                     */
                    // Folded on both sides, or "Kütüphane nerede?" fails
                    // to match a place whose description says
                    // "kütüphane" — the haystack is folded and an
                    // unfolded term never appears in it.
                    $terms = array_map(TextFold::fold(...), $this->terms($query));

                    $scored = [];
                    foreach ($byName as $p) {
                        $haystack = TextFold::fold(implode(' ', [
                            (string) $p->name, (string) $p->category, (string) $p->description,
                            (string) $p->street,
                        ]));

                        $score = 0;
                        foreach ($terms as $term) {
                            if ($term !== '' && str_contains($haystack, $term)) {
                                $score++;
                            }
                        }
                        // Named by the question (by name, alias or typo):
                        // above any incidental word overlap.
                        if (isset($resolved[AiEntityAlias::TYPE_PLACE][(string) $p->id])) {
                            $score += 10;
                        }
                        $scored[] = ['place' => $p, 'score' => $score];
                    }

                    usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
                    $matched = array_values(array_filter($scored, fn ($r) => $r['score'] > 0));

                    /*
                     * With no question to rank by there is nothing to
                     * narrow to, so describe a bounded set properly
                     * rather than emit a list of bare names. Detail is
                     * the point of this tool: a name alone is useless
                     * here, because ARUCAD names its buildings after
                     * sculptures and only the description says which one
                     * is the library.
                     */
                    if ($terms === []) {
                        return array_map(
                            fn (array $r) => $this->describePlace($r['place']),
                            array_slice($scored, 0, 12),
                        );
                    }

                    // A question was asked and nothing matched it: a
                    // compact index is the useful answer, because the
                    // student is looking for a name we may not have
                    // recognised.
                    if ($matched === []) {
                        return array_map(
                            fn (array $r) => '- '.$r['place']->name.' ('.$r['place']->category.')',
                            array_slice($scored, 0, 30),
                        );
                    }

                    $lines = [];
                    foreach (array_slice($matched, 0, 6) as $row) {
                        $lines[] = $this->describePlace($row['place']);
                    }

                    return $lines;
                },
            ],
            'events' => [
                'label' => 'Etkinlikler (tarih sırasıyla):',
                'gather' => function () {
                    // The SAME visibility rule the rest of the app uses.
                    //
                    // This filtered `draft` and `workflow_status` only, so an
                    // event scheduled to publish next week, or one that had
                    // already expired, still reached the model and could be
                    // told to a student as current. Event::scopePubliclyListed
                    // is the one definition of "a student may see this", and
                    // the assistant now shares it rather than keeping its own
                    // partial copy.
                    $q = Event::query()->publiclyListed();

                    /*
                     * Upcoming first, and always with the date.
                     *
                     * This used to return thirty events in table order
                     * with no date at all — "Konser @ Bandabuliya, saat
                     * 19:00" — so "what is on today" could not be
                     * answered even in principle: the model had no way to
                     * tell today's event from one last March, and nothing
                     * stopped it presenting a past event as upcoming.
                     */
                    $today = now()->toDateString();
                    if (SchemaColumnCache::hasColumn('events', 'event_date')) {
                        $upcoming = (clone $q)
                            ->whereDate('event_date', '>=', $today)
                            ->orderBy('event_date')
                            ->limit(15)
                            ->get();

                        // Nothing ahead: say what recently happened
                        // rather than nothing, but label it clearly so it
                        // cannot be read as a plan for tonight.
                        $rows = $upcoming->isNotEmpty()
                            ? $upcoming
                            : (clone $q)->orderByDesc('event_date')->limit(5)->get();

                        return $rows->map(function (Event $e) use ($today) {
                            $date = optional($e->event_date)->format('d.m.Y');
                            $past = $e->event_date !== null
                                && $e->event_date->toDateString() < $today;

                            return '- '.($past ? '[geçmiş] ' : '').$e->title
                                .($date ? ' — '.$date : '')
                                .(trim((string) $e->time) !== '' ? ' '.$e->time : '')
                                .(trim((string) $e->place_name) !== '' ? ' @ '.$e->place_name : '');
                        })->all();
                    }

                    return $q->limit(15)->get()
                        ->map(fn (Event $e) => "- {$e->title} @ {$e->place_name}, saat {$e->time}")->all();
                },
            ],
            'clubs' => [
                'label' => 'Kulüpler:',
                'gather' => fn () => Club::query()->limit(40)->get()
                    ->map(fn (Club $c) => "- {$c->name} ({$c->category}): {$c->description}")->all(),
            ],
            'sports' => [
                'label' => 'Spor imkânları:',
                'gather' => fn () => Sport::query()->limit(40)->get()
                    ->map(fn (Sport $s) => "- {$s->name}: {$s->facility}")->all(),
            ],
            'services' => [
                'label' => 'Kampüs hizmetleri:',
                'gather' => fn () => ServiceItem::query()->limit(40)->get()
                    ->map(function (ServiceItem $s) {
                        $where = collect([$s->building, $s->floor, $s->room])->filter()->implode(', ');

                        return "- {$s->title} ({$s->category})".($where !== '' ? " — {$where}" : '')." İletişim: {$s->contact}";
                    })->all(),
            ],
            'food' => [
                'label' => 'Yemek noktaları:',
                'gather' => function () {
                    $today = now()->toDateString();

                    return FoodVenue::with(['dailyMenus' => fn ($q) => $q->whereDate('menu_date', $today)])
                        ->limit(30)->get()
                        ->map(function (FoodVenue $v) {
                            $menu = $v->dailyMenus->first();
                            $part = $menu === null ? 'bugünün menüsü girilmemiş'
                                : (empty($menu->items) ? 'menü detayı yok' : implode(', ', $menu->items));

                            return "- {$v->name}".($v->hours ? " ({$v->hours})" : '').": {$part}";
                        })->all();
                },
            ],
            'shuttle' => [
                'label' => 'Servis hatları:',
                /*
                 * The stops AND the departure times.
                 *
                 * This listed only the stops, so "when is the next
                 * shuttle?" — the most-asked transport question, and one
                 * the shuttle_routes table answers exactly — had no
                 * structured source at all and fell through to whatever a
                 * crawled page happened to say. The times are the point of
                 * a timetable.
                 */
                'gather' => fn () => ShuttleRoute::query()->orderBy('sort_order')->limit(20)->get()
                    ->map(function (ShuttleRoute $r) {
                        $stops = is_array($r->stops) ? implode(' → ', array_slice($r->stops, 0, 6)) : '';
                        $out = is_array($r->departures) ? implode(', ', $r->departures) : '';
                        $back = is_array($r->returns) ? implode(', ', $r->returns) : '';

                        $line = "- {$r->name}".($stops !== '' ? ": {$stops}" : '');
                        if ($out !== '') {
                            $line .= " | Kampüsten kalkış: {$out}";
                        }
                        if ($back !== '') {
                            $line .= " | Dönüş: {$back}";
                        }

                        return $line;
                    })->all(),
            ],
            // Who sits where. 141 offices synced from the ARUCAD 360 directory,
            // each with its building, room and occupant.
            'directory' => [
                'label' => 'Kampüs rehberi (bina/oda/kişi):',
                'gather' => fn () => DirectoryEntry::query()
                    ->whereNotNull('occupant_name')
                    ->limit(60)->get()
                    ->map(function (DirectoryEntry $d) {
                        $where = trim(($d->building ?? '').' '.($d->room ?? ''));
                        $role = $d->occupant_role ? " ({$d->occupant_role})" : '';

                        return "- {$d->occupant_name}{$role}: {$where}";
                    })->all(),
            ],
            // Real staff contacts. Without this the model invented e-mail
            // addresses when asked who to write to.
            'staff' => [
                'label' => 'Akademik/idari personel:',
                'gather' => function () {
                    $q = StaffProfile::query();
                    if (SchemaColumnCache::hasColumn('staff_profiles', 'active')) {
                        $q->where('active', true);
                    }

                    return $q->limit(60)->get()->map(function (StaffProfile $p) {
                        $bits = array_filter([$p->title, $p->department, $p->faculty]);
                        $head = $p->is_department_head ? ' [Bölüm Başkanı]' : '';

                        return "- {$p->name}{$head}".($bits ? ' — '.implode(', ', $bits) : '').
                            ($p->email ? " — {$p->email}" : '');
                    })->all();
                },
            ],
            // Term dates, so "when does the semester start" is answerable.
            // The official calendar page is the source of term dates. An
            // academic-year row that has ended is labelled so — never offered
            // as the current year (the 2025–2026 row stayed "active" after
            // it ended, and its dates reached answers).
            'calendar' => [
                'label' => 'Akademik takvim:',
                'gather' => function () {
                    $lines = [];
                    $calendar = app(AcademicCalendar::class)->current();
                    if ($calendar !== null && ! $calendar['stale']) {
                        $today = now()->toDateString();
                        foreach (array_slice(array_values(array_filter($calendar['entries'], fn ($e) => $e['end'] >= $today)), 0, 8) as $e) {
                            $lines[] = "- {$e['start']}".($e['end'] !== $e['start'] ? " — {$e['end']}" : '').": {$e['label']}";
                        }
                        $lines[] = "(Resmî akademik takvim {$calendar['academic_year']}: {$calendar['source_url']})";
                    }
                    foreach (AcademicYear::query()->orderByDesc('starts_on')->limit(2)->get() as $y) {
                        $ended = $y->ends_on !== null && now()->greaterThan($y->ends_on);
                        $state = $ended ? ' (SONA ERDİ — güncel yıl değil)' : ($y->is_active ? ' (aktif)' : '');
                        $lines[] = "- Akademik yıl kaydı {$y->label}{$state}: {$y->starts_on} — {$y->ends_on}";
                    }

                    return $lines;
                },
            ],
            'consultation' => [
                'label' => 'Danışmanlık hizmetleri:',
                'gather' => function () {
                    $q = Consultation::query();
                    if (SchemaColumnCache::hasColumn('consultations', 'published')) {
                        $q->where('published', true);
                    }

                    return $q->limit(20)->get()->map(function (Consultation $c) {
                        $who = $c->counselor_name ? " — {$c->counselor_name}" : '';

                        return "- {$c->title}{$who}".($c->audience ? " ({$c->audience})" : '');
                    })->all();
                },
            ],
            // Wellbeing. Without this, none of the agent's keywords matched a
            // student saying "psikolojik sorunum var", so the model never even
            // saw that ARUCAD has a counselling service.
            'support' => [
                'label' => 'Öğrenci destek birimleri:',
                'gather' => fn () => ServiceItem::query()
                    ->whereIn('category', ['Wellbeing', 'Support', 'Accessibility'])
                    ->orWhere('title', 'like', '%Danışmanlık%')
                    ->orWhere('title', 'like', '%Destek%')
                    ->limit(10)->get()
                    ->map(function (ServiceItem $s) {
                        $where = collect([$s->building, $s->floor, $s->room])->filter()->implode(', ');

                        return "- {$s->title}: {$s->description}".
                            ($where !== '' ? " — {$where}" : '').
                            ($s->contact ? " — {$s->contact}" : '').
                            ($s->hours ? " ({$s->hours})" : '');
                    })->all(),
            ],
            // Programme facts extracted with provenance from the admissions
            // sites' field block. Trusted data: the model must not override it.
            'programs' => [
                'label' => 'Programlar (resmî program sayfalarından doğrulanmış bilgiler):',
                'gather' => function () use ($query) {
                    $facts = KnowledgeFact::query()->where('subject_type', KnowledgeFact::SUBJECT_PROGRAMME)
                        ->orderBy('subject')->get()->groupBy('subject_folded');
                    $folded = TextFold::fold($query);
                    // A programme named in the question narrows the list to it —
                    // in any supported language (ProgrammeCatalog aliases).
                    $names = array_merge(...array_column(app(ProgrammeCatalog::class)->inText($query), 'names') ?: [[]]);
                    $named = $facts->filter(fn ($rows, $subject) => str_contains($folded, (string) $subject) || in_array((string) $subject, $names, true));
                    $lines = [];
                    foreach (($named->isNotEmpty() ? $named : $facts) as $rows) {
                        $byAttribute = $rows->keyBy('attribute');
                        $language = $byAttribute[KnowledgeFact::LANGUAGE] ?? null;
                        $duration = $byAttribute[KnowledgeFact::DURATION] ?? null;
                        $first = $rows->first();
                        $lines[] = '- '.$first->subject
                            .($language ? ': eğitim dili '.$language->value : '')
                            .($duration ? '; eğitim süresi '.$duration->value : '')
                            .' (kaynak: '.$first->source_url.')';
                    }

                    return $lines;
                },
            ],
            'career' => [
                'label' => 'Kariyer fırsatları:',
                'gather' => function () {
                    $q = CareerOpportunity::query();
                    if (SchemaColumnCache::hasColumn('career_opportunities', 'published')) {
                        $q->where('published', true);
                    }

                    return $q->limit(25)->get()
                        ->map(fn (CareerOpportunity $c) => "- {$c->title}".($c->organization ? " @ {$c->organization}" : '').
                            ($c->kind ? " ({$c->kind})" : ''))->all();
                },
            ],
        ];
    }

    /**
     * Everything the app knows about one place, in one line.
     *
     * Coordinates are included because "where is it" is answered by a
     * map, not by prose, and the client can act on a pair of numbers.
     * The 360 tour link is included for the same reason.
     */
    private function describePlace(Place $place): string
    {
        $what = trim((string) $place->description);
        if (mb_strlen($what) > 220) {
            $what = mb_substr($what, 0, 220).'…';
        }

        $parts = ['- '.$place->name.' ('.$place->category.')'];
        if ($what !== '') {
            $parts[] = 'ne olduğu: '.$what;
        }
        if (trim((string) $place->street) !== '') {
            $parts[] = 'adres/konum: '.trim((string) $place->street);
        }
        if ($place->lat !== null && $place->lng !== null) {
            $parts[] = sprintf('koordinat: %.5f, %.5f', $place->lat, $place->lng);
        }
        $parts[] = $place->accessible
            ? 'engelli erişimine uygun'
            : 'engelli erişimi bilgisi yok';
        if (trim((string) $place->tour_url) !== '') {
            $parts[] = '360 tur mevcut';
        }

        return implode(' — ', $parts);
    }

    /** @return list<string> */
    private function terms(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [];

        return array_values(array_unique(array_filter($words, fn ($w) => mb_strlen($w) >= 3)));
    }
}
