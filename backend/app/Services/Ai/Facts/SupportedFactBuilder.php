<?php

namespace App\Services\Ai\Facts;

use App\Services\Ai\AskTrace;
use App\Services\Ai\Evidence\AssessedEvidence;
use App\Services\Ai\Evidence\ConsolidatedRequirement;
use App\Services\Ai\Evidence\Evidence;
use App\Services\Ai\Evidence\EvidenceResult;
use App\Services\Ai\Evidence\EvidenceStatus;
use App\Services\Ai\Planning\OpeningHours;
use App\Services\Ai\Planning\PlanningResult;
use App\Services\Ai\ProgrammeCatalog;
use App\Support\TextFold;

/**
 * Phase 3C: Evidence → CandidateFact → FactValidator → SupportedFact, per
 * requirement, then the AnswerPlan. Deterministic throughout.
 *
 *  - Structured evidence (campus rows, knowledge_facts, RoutingService, the
 *    request) converts directly; it is never sent back through document
 *    extraction.
 *  - Document passages go through DocumentFactExtractor for the fact type
 *    the requirement asks for; a relevant passage that does not STATE the
 *    fact yields no candidate, and the requirement is INSUFFICIENT.
 *  - An unresolved Phase 3B conflict stays CONFLICTING: no value is chosen.
 *  - Facts backed only by stale sources are STALE, not SUPPORTED.
 */
final class SupportedFactBuilder
{
    /** @var array<string, int> */
    private array $counters = [];

    /**
     * A day other than today, named in the question ("yarın", "hafta sonu",
     * "on Saturday", "в субботу"). Then "is it open NOW" is not what was
     * asked: no is_open_now fact is built, and the schedule (with its days)
     * answers. Folded text; inflections allowed.
     */
    private const OTHER_DAY = '/(?<![\p{L}])(?:yarin|hafta ?sonu|pazartesi|sali|carsamba|persembe|cuma|cumartesi|pazar|tomorrow|weekend|monday|tuesday|wednesday|thursday|friday|saturday|sunday|завтра|выходн|понедельник|вторник|сред[ау]|четверг|пятниц|суббот|воскресень)\w*/u';

    private bool $askedAboutAnotherDay = false;

    public function __construct(
        private readonly DocumentFactExtractor $extractor,
        private readonly FactValidator $validator,
    ) {}

    public function build(PlanningResult $planning, EvidenceResult $evidence, string $query): FactResult
    {
        $this->counters = [];
        $timings = ['extraction' => 0.0, 'validation' => 0.0];
        $started = microtime(true);
        $taskTypes = [];
        foreach ($planning->plan?->tasks ?? [] as $task) {
            $taskTypes[$task->id] = $task->type;
        }
        $programmes = $this->programmesIn($query);
        $this->askedAboutAnotherDay = (bool) preg_match(self::OTHER_DAY, TextFold::fold($query));

        $requirements = [];
        foreach ($planning->requirements as $requirement) {
            $consolidated = $evidence->requirements[$requirement->id] ?? null;
            if ($consolidated === null) {
                continue;
            }
            $requirements[$requirement->id] = $this->forRequirement($consolidated, $evidence, $programmes, $timings);
        }
        $timings['assembly'] = round((microtime(true) - $started) * 1000 - $timings['extraction'] - $timings['validation'], 2);
        $planStarted = microtime(true);
        $plan = $this->answerPlan($requirements, $taskTypes, $evidence);
        $timings['answer_plan'] = round((microtime(true) - $planStarted) * 1000, 2);
        $timings['extraction'] = round($timings['extraction'], 2);
        $timings['validation'] = round($timings['validation'], 2);

        $result = new FactResult($requirements, $plan, $taskTypes, $timings);
        $this->trace($result);

        return $result;
    }

    /**
     * @param  list<array{folded: string, name: string}>  $programmes
     * @param  array{extraction: float, validation: float}  $timings
     */
    private function forRequirement(ConsolidatedRequirement $c, EvidenceResult $evidence, array $programmes, array &$timings): RequirementFacts
    {
        $make = fn (FactStatus $status, array $candidates = [], array $facts = [], array $rejected = [], array $conflict = [], array $reasons = []) => new RequirementFacts(
            $c->requirementId, $c->taskId, $c->factType, $c->required, $status, $candidates, $facts, $rejected, $conflict, $reasons);

        if ($c->coverage === ConsolidatedRequirement::NOT_APPLICABLE) {
            return $make(FactStatus::NOT_APPLICABLE, reasons: ['task_blocked']);
        }
        if ($c->coverage === ConsolidatedRequirement::CONFLICTING) {
            $values = array_values(array_unique(array_map(fn (AssessedEvidence $a) => is_scalar($a->evidence->value)
                ? (string) $a->evidence->value : (string) json_encode($a->evidence->value, JSON_UNESCAPED_UNICODE), $c->conflict->contested)));

            return $make(FactStatus::CONFLICTING, conflict: $values, reasons: [(string) $c->conflict->rule]);
        }
        if ($c->conflict->winners === []) {
            $expired = collect($c->excluded)->contains(fn ($x) => str_contains($x['reason'], 'expired'));

            return $make($expired ? FactStatus::STALE : FactStatus::UNSUPPORTED, reasons: $c->reasons ?: [$c->coverage]);
        }

        $extractStarted = microtime(true);
        $candidates = $rejected = [];
        $sources = $temporal = [];
        foreach ($c->conflict->winners as $winner) {
            $e = $winner->evidence;
            if ($e->valueType === 'document_passage') {
                if (! in_array($c->factType, DocumentFactExtractor::TYPES, true)) {
                    $rejected[] = ['evidence_id' => $e->id, 'reason' => 'no document extractor for '.$c->factType];

                    continue;
                }
                $subjects = $c->factType === 'program_language' ? array_merge(...array_column($programmes, 'names') ?: [[]]) : null;
                $out = $this->extractor->extract($c->factType, (string) $e->value, $e->title, $subjects);
                if (isset($out['none'])) {
                    $rejected[] = ['evidence_id' => $e->id, 'reason' => $out['none']];

                    continue;
                }
                $subject = $c->factType === 'program_language' ? $this->programmeSubject($programmes, $e) : $e->subject;
                $candidates[] = $candidate = new CandidateFact($this->nextId('cand', $c->taskId), $c->taskId, $c->requirementId, $c->factType,
                    $subject, $out['value'], is_array($out['value']) ? 'list' : 'enum', [$e->id], 'pattern:'.$c->factType, class_basename(DocumentFactExtractor::class), $out['span']);
                $sources[$candidate->id] = (string) $e->value;
                $temporal[$candidate->id] = $winner->temporal->value;

                continue;
            }
            foreach ($this->structured($c->factType, $e, $winner) as $spec) {
                [$factType, $value, $valueType, $method] = $spec;
                $candidates[] = $candidate = new CandidateFact($this->nextId('cand', $c->taskId), $c->taskId, $c->requirementId, $factType,
                    $e->subject, $value, $valueType, [$e->id], $method, (string) ($e->implementation ?? 'campus'), qualifiers: $spec[4] ?? []);
                $sources[$candidate->id] = is_scalar($e->value) ? (string) $e->value : (string) json_encode($e->value, JSON_UNESCAPED_UNICODE);
                $temporal[$candidate->id] = $winner->temporal->value;
            }
        }
        $timings['extraction'] += (microtime(true) - $extractStarted) * 1000;

        $validateStarted = microtime(true);
        $facts = [];
        $staleOnly = true;
        foreach ($candidates as $candidate) {
            $check = $this->validator->validate($candidate, $sources[$candidate->id] ?? null);
            if (! $check['ok']) {
                $rejected[] = ['candidate_id' => $candidate->id, 'reason' => $check['method'].': '.$check['reason']];

                continue;
            }
            $backing = array_map(fn (string $id) => $evidence->find($id), $candidate->evidenceIds);
            if (collect($backing)->every(fn (?Evidence $e) => $e?->status === EvidenceStatus::STALE)) {
                $rejected[] = ['candidate_id' => $candidate->id, 'reason' => 'backed only by a stale source'];

                continue;
            }
            $staleOnly = false;
            $facts = $this->merge($facts, $candidate, $check, $backing, $rejected, $temporal[$candidate->id] ?? null);
        }
        $timings['validation'] += (microtime(true) - $validateStarted) * 1000;

        if ($facts !== []) {
            $programmeFacts = array_filter($facts, fn (SupportedFact $f) => in_array($f->factType, ['program_language', 'programme_duration'], true));
            $values = array_unique(array_map(fn (SupportedFact $f) => $f->factType.'='.$f->normalizedValue, $programmeFacts));
            $subjects = array_unique(array_map(fn (SupportedFact $f) => $f->subject['id'] ?? '-', $programmeFacts));
            if (count($values) > 1 && count($subjects) === 1) {
                // Two validated languages (or durations) for one programme: a conflict, not a choice.
                return $make(FactStatus::CONFLICTING, $candidates, [], $rejected, array_map(fn (SupportedFact $f) => (string) $f->value, $facts),
                    ['validated values disagree']);
            }

            return $make(FactStatus::SUPPORTED, $candidates, array_values($facts), $rejected);
        }
        $anyStale = collect($c->conflict->winners)->contains(fn (AssessedEvidence $a) => $a->evidence->status === EvidenceStatus::STALE);

        return $make($candidates !== [] && $staleOnly && $anyStale ? FactStatus::STALE : FactStatus::INSUFFICIENT, $candidates, [], $rejected, [],
            [$candidates === [] ? 'evidence does not state the fact' : 'no candidate passed validation']);
    }

    /**
     * Candidate values from one structured evidence item — direct, plus the
     * facts derived from it by existing deterministic code.
     *
     * @return list<array{0: string, 1: mixed, 2: string, 3: string}> fact type, value, value type, method
     */
    private function structured(string $factType, Evidence $e, AssessedEvidence $winner): array
    {
        $v = $e->value;

        return match ($factType) {
            'current_opening_hours' => $this->hours((string) $v),
            'routing' => [
                ['route_distance_m', (int) ($v['distance_m'] ?? 0), 'number', 'derived:RoutingService'],
                ['route_duration_min', max(1, (int) round(((int) ($v['duration_s'] ?? 0)) / 60)), 'number', 'derived:RoutingService'],
            ],
            'place_coordinates', 'user_location' => [[$factType, $v, 'location', 'structured']],
            'current_menu' => [[$factType, ['items' => (array) ($v['items'] ?? []), 'price' => $v['price'] ?? null], 'list', 'structured']],
            'current_events', 'club_social_profile', 'food_places', 'program_language', 'programme_duration' => [[$factType, $v, is_array($v) ? 'structured' : 'string', 'structured']],
            'academic_date' => [['academic_date', $v, 'date', 'structured']],
            'contact_details' => [
                ...array_map(fn ($email) => ['contact_email', $email, 'string', 'structured'], (array) ($v['emails'] ?? [])),
                ...array_map(fn ($phone) => ['contact_phone', $phone, 'string', 'structured'], (array) ($v['phones'] ?? [])),
            ],
            default => [],
        };
    }

    /**
     * Opening hours and "open now" are two facts. The hours are the
     * schedule; whether it is open NOW is evaluated in campus time, and only
     * when the schedule covers today — a range with no days is no answer
     * for a Saturday, so no is_open_now fact is made.
     *
     * @return list<array{0: string, 1: mixed, 2: string, 3: string, 4?: array<string, string>}>
     */
    private function hours(string $text): array
    {
        $schedule = OpeningHours::parse($text);
        $out = [['current_opening_hours', $schedule === null ? $text : ['text' => $text] + $schedule, 'structured', 'structured']];
        $now = OpeningHours::evaluate($text, now());
        if ($now['open'] !== null && ! $this->askedAboutAnotherDay) {
            $out[] = ['is_open_now', $now['open'], 'boolean', 'derived:OpeningHours',
                ['evaluated_at' => $now['evaluated_at'], 'timezone' => $now['timezone'], 'reason' => $now['reason']]];
        }

        return $out;
    }

    /**
     * Add a validated candidate as a fact, or fold it into an identical one
     * (two sources stating the same value are one fact with two evidence ids).
     * A document-derived programme language that contradicts the structured
     * programme fact is rejected — the structured fact is the stronger source.
     *
     * @param  array<string, SupportedFact>  $facts
     * @param  list<?Evidence>  $backing
     * @return array<string, SupportedFact>
     */
    private function merge(array $facts, CandidateFact $candidate, array $check, array $backing, array &$rejected, ?string $temporal): array
    {
        $key = $candidate->factType.'|'.($candidate->subject['id'] ?? '-').'|'.$check['normalized'];
        if (isset($facts[$key])) {
            $f = $facts[$key];
            $facts[$key] = new SupportedFact($f->id, $f->taskId, $f->requirementId, $f->candidateId, $f->subject, $f->factType, $f->value,
                $f->normalizedValue, $f->valueType, array_values(array_unique([...$f->evidenceIds, ...$candidate->evidenceIds])),
                [...$f->provenance, ...$this->provenance($backing)], $f->temporalStatus, $f->authorityClass, $f->validation, $f->span ?? $candidate->span,
                $f->anchors, $f->redacted, $f->qualifiers);

            return $facts;
        }
        if ($candidate->factType === 'program_language' && str_starts_with($candidate->method, 'pattern:')) {
            foreach ($facts as $f) {
                if ($f->factType === 'program_language' && ($f->subject['id'] ?? null) === ($candidate->subject['id'] ?? null)
                    && $f->normalizedValue !== $check['normalized'] && $f->authorityClass === 'structured_fact') {
                    $rejected[] = ['candidate_id' => $candidate->id, 'reason' => 'contradicts the stronger structured programme fact'];

                    return $facts;
                }
            }
        }
        $first = $backing[0] ?? null;
        $facts[$key] = new SupportedFact($this->nextId('fact', $candidate->taskId), $candidate->taskId, $candidate->requirementId, $candidate->id,
            $candidate->subject, $candidate->factType, $candidate->value, $check['normalized'], $candidate->valueType, $candidate->evidenceIds,
            $this->provenance($backing), $temporal, $first?->authorityClass,
            ['method' => $check['method'], 'reason' => $check['reason']], $candidate->span,
            $this->anchors($candidate, $check['normalized']), $candidate->factType === 'user_location', $candidate->qualifiers);

        return $facts;
    }

    /** @param list<?Evidence> $backing */
    private function provenance(array $backing): array
    {
        return array_values(array_map(fn (Evidence $e) => [
            'evidence_id' => $e->id, 'source_type' => $e->sourceType, 'source_id' => $e->sourceId, 'url' => $e->url,
            'title' => $e->title, 'authority_class' => $e->authorityClass, 'page' => $e->metadata['page'] ?? null,
        ], array_filter($backing)));
    }

    /**
     * Folded strings by which a claim about this fact is recognised in an
     * answer — how claims are attributed to fact ids without asking the
     * model to cite them.
     *
     * @return list<string>
     */
    private function anchors(CandidateFact $c, string $normalized): array
    {
        $v = $c->value;
        $out = match ($c->factType) {
            'current_opening_hours' => array_merge(...array_map(function (string $range) {
                $times = explode('-', $range);

                return array_merge($times, array_map(fn ($t) => ltrim($t, '0'), $times));
            }, explode(',', $normalized))),
            // Claimed only by explicit "open/closed now" wording, which the
            // verifier checks against this fact; a bare "açık" is not a claim.
            'is_open_now' => [],
            'program_language' => $normalized === 'en' ? ['ingilizce', 'english', 'английск'] : ['turkce', 'turkish', 'турецк'],
            'programme_duration' => self::durationAnchors($normalized),
            'required_documents' => array_map(fn ($i) => TextFold::fold((string) $i), (array) $v),
            'route_duration_min' => [$v.' dk', $v.' dakika', $v.' min', $v.' мин'],
            'route_distance_m' => [$v.' m', $v.' metre', $v.' meter', $v.' м'],
            'current_events' => [TextFold::fold((string) ($v['title'] ?? ''))],
            'current_menu' => array_map(fn ($i) => TextFold::fold((string) $i), (array) ($v['items'] ?? [])),
            'club_social_profile' => [mb_strtolower((string) $v)],
            'academic_date' => array_merge(self::dateAnchors((string) ($v['start'] ?? ''), (string) ($v['end'] ?? '')),
                self::dateAnchors((string) ($v['recent']['start'] ?? ''), (string) ($v['recent']['end'] ?? ''))),
            'contact_email' => [mb_strtolower((string) $v)],
            'contact_phone' => [preg_replace('/\D+/', '', (string) $v)],
            'food_places' => [TextFold::fold((string) $v)],
            default => [],
        };
        // A place is claimed by its name; hours are claimed by their times or
        // the open/closed state — merely naming the office covers nothing.
        if ($c->factType === 'place_coordinates' && isset($c->subject['name'])) {
            $out[] = TextFold::fold(trim((string) preg_replace('/\([^)]*\)/u', '', $c->subject['name'])));
        }

        return array_values(array_unique(array_filter($out, fn ($a) => mb_strlen((string) $a) >= 2)));
    }

    /**
     * Folded forms of a programme length an answer may use: "4 yil",
     * "4 yillik", "4 years", "4-year", "4 года", "1-2 yariyil".
     *
     * @return list<string>
     */
    public static function durationAnchors(string $normalized): array
    {
        if (! preg_match('/^(\d)(?:-(\d))?(y|s)$/', $normalized, $m)) {
            return [];
        }
        $n = $m[2] !== '' ? $m[1].'-'.$m[2] : $m[1];
        $forms = $m[3] === 'y' ? ['yil', 'yillik', 'sene', 'years', 'year', '-year', 'года', 'год', 'лет'] : ['yariyil', 'donem', 'semesters', 'semester', 'семестр'];

        return array_merge(...array_map(fn ($f) => str_starts_with($f, '-') ? [$n.$f] : [$n.' '.$f, str_replace('-', '–', $n).' '.$f], $forms));
    }

    /**
     * Folded forms of a date an answer may use: "5 ekim", "october 5", "5 october", "2026-10-05".
     *
     * @return list<string>
     */
    public static function dateAnchors(string $start, string $end): array
    {
        $tr = ['ocak', 'subat', 'mart', 'nisan', 'mayis', 'haziran', 'temmuz', 'agustos', 'eylul', 'ekim', 'kasim', 'aralik'];
        $en = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];
        $out = [];
        foreach (array_unique(array_filter([$start, $end])) as $date) {
            [$y, $m, $d] = array_map('intval', explode('-', $date)) + [0, 1, 1];
            if ($m < 1 || $m > 12) {
                continue;
            }
            array_push($out, $d.' '.$tr[$m - 1], $d.' '.$en[$m - 1], $en[$m - 1].' '.$d, $date);
        }

        return $out;
    }

    /**
     * Canonical programmes the question names, in any supported language.
     *
     * @return list<array{id: string, name: string, names: list<string>}>
     */
    private function programmesIn(string $query): array
    {
        return app(ProgrammeCatalog::class)->inText($query);
    }

    /** @param list<array{id: string, name: string, names: list<string>}> $programmes */
    private function programmeSubject(array $programmes, Evidence $e): ?array
    {
        $haystack = TextFold::fold(($e->title ?? '').' '.$e->value);
        foreach ($programmes as $p) {
            foreach ($p['names'] as $name) {
                if (str_contains($haystack, $name)) {
                    return ['type' => 'programme', 'id' => $p['id'], 'name' => $p['name']];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, RequirementFacts>  $requirements
     * @param  array<string, string>  $taskTypes
     */
    private function answerPlan(array $requirements, array $taskTypes, EvidenceResult $evidence): AnswerPlan
    {
        $tasks = [];
        foreach ($taskTypes as $taskId => $type) {
            $mine = array_values(array_filter($requirements, fn (RequirementFacts $r) => $r->taskId === $taskId && $r->required));
            $statuses = array_map(fn (RequirementFacts $r) => $r->status, $mine);
            $reasons = array_values(array_unique(array_merge(...array_map(fn (RequirementFacts $r) => $r->reasons, $mine) ?: [[]])));
            $contextMissing = collect($evidence->forTask($taskId))->contains(fn (ConsolidatedRequirement $c) => in_array('context_missing', $c->reasons, true));
            $supported = count(array_filter($statuses, fn ($s) => $s === FactStatus::SUPPORTED));
            $status = match (true) {
                $mine === [] => AnswerPlan::UNAVAILABLE,
                $supported === count($mine) => AnswerPlan::SUPPORTED,
                in_array(FactStatus::NOT_APPLICABLE, $statuses, true) && $supported === 0 => AnswerPlan::NOT_APPLICABLE,
                in_array(FactStatus::CONFLICTING, $statuses, true) => AnswerPlan::CONFLICTING,
                $contextMissing => AnswerPlan::CONTEXT_MISSING,
                $supported > 0 => AnswerPlan::PARTIAL,
                in_array(FactStatus::STALE, $statuses, true) => AnswerPlan::STALE,
                in_array(FactStatus::INSUFFICIENT, $statuses, true) => AnswerPlan::INSUFFICIENT,
                default => AnswerPlan::UNAVAILABLE,
            };
            $tasks[] = array_filter([
                // Nothing to say about a task: what that absence means.
                'absence' => in_array($status, [AnswerPlan::UNAVAILABLE, AnswerPlan::INSUFFICIENT], true) ? AnswerPlan::NOT_FOUND_IN_CURRENT_DATA : null,
            ]) + [
                'task_id' => $taskId, 'task_type' => $type, 'status' => $status,
                'fact_ids' => array_values(array_merge(...array_map(fn (RequirementFacts $r) => array_map(fn (SupportedFact $f) => $f->id, $r->facts), $mine) ?: [[]])),
                'reasons' => $reasons,
                'conflict_values' => array_values(array_merge(...array_map(fn (RequirementFacts $r) => $r->conflictValues, $mine) ?: [[]])),
            ];
        }
        $answered = count(array_filter($tasks, fn ($t) => in_array($t['status'], [AnswerPlan::SUPPORTED, AnswerPlan::PARTIAL, AnswerPlan::CONTEXT_MISSING], true) && $t['fact_ids'] !== []));
        $complete = count(array_filter($tasks, fn ($t) => $t['status'] === AnswerPlan::SUPPORTED));

        return new AnswerPlan($tasks, match (true) {
            $tasks !== [] && $complete === count($tasks) => 'COMPLETE',
            $answered > 0 => 'PARTIAL',
            default => 'UNAVAILABLE',
        });
    }

    private function nextId(string $prefix, string $taskId): string
    {
        $key = $prefix.'_'.$taskId;
        $this->counters[$key] = ($this->counters[$key] ?? 0) + 1;

        return $key.'_'.$this->counters[$key];
    }

    private function trace(FactResult $result): void
    {
        $trace = app(AskTrace::class);
        $trace->record('candidate_facts', ['candidates' => array_map(fn (CandidateFact $c) => $c->factType === 'user_location'
            ? ['value' => '[redacted]'] + $c->toArray() : $c->toArray(), $result->candidates())]);
        $trace->record('fact_validation', ['requirements' => array_values(array_map(fn (RequirementFacts $r) => [
            'requirement_id' => $r->requirementId, 'task_id' => $r->taskId, 'fact_type' => $r->factType,
            'accepted' => array_map(fn (SupportedFact $f) => ['candidate_id' => $f->candidateId, 'fact_id' => $f->id, 'validation' => $f->validation], $r->facts),
            'rejected' => $r->rejected,
        ], $result->requirements))]);
        $trace->record('supported_facts', ['facts' => array_map(fn (SupportedFact $f) => $f->toArray(), $result->facts())]);
        $trace->record('requirement_fact_status', ['requirements' => array_values(array_map(fn (RequirementFacts $r) => $r->toArray(), $result->requirements)),
            'timings' => $result->timings]);
        $trace->record('answer_plan', $result->plan->toArray());
    }
}
