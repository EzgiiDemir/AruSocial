<?php

namespace App\Services\Ai\Facts;

use App\Models\User;
use App\Services\Ai\AskTrace;
use App\Services\Ai\Planning\PlanningResult;
use App\Services\GranularPermissions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Per-request rollout state of the SupportedFacts generation path, and the
 * aggregate rollout metrics the AICAD health page reads.
 *
 * Decides the path once per request (mode off | staff_only | on, staff
 * meaning existing back-office access — never a list of e-mails), owns the
 * technical fallback, and records the trace signals. Aggregates are counters
 * and a bounded list of latencies: no question, answer or prompt is stored.
 *
 * Fallback rule: a TECHNICAL failure of the fact path may fall back to the
 * Phase 3B model path only when every task already had its facts — then the
 * legacy answer has nothing to invent. When a task's data is missing,
 * insufficient or conflicting, the request is answered by the deterministic
 * fallback instead: lack of facts is never a reason to ask a looser prompt.
 */
final class SupportedFactsRollout
{
    public const PATH_SUPPORTED_FACTS = 'supported_facts';

    public const PATH_LEGACY = 'legacy';

    public const FALLBACK_PATH_ERROR = 'SUPPORTED_FACT_PATH_ERROR';

    /** Aggregate counter names (cache keys `ai:rollout:<name>`, 30-day window). */
    public const COUNTERS = [
        'supported_facts_requests', 'legacy_planned_requests', 'outcome_complete', 'outcome_partial', 'outcome_unavailable',
        'outcome_failed', 'regenerations', 'unsupported_claims_removed', 'speculative_claims_removed', 'answers_withheld',
        'restatements', 'model_calls', 'path_errors', 'path_error_legacy_fallbacks', 'path_error_deterministic_fallbacks',
        'shadow_checks', 'shadow_unsupported_claims',
        // Phase 3C.1 staging signals.
        'claim_verification_failures', 'ai_unavailable', 'data_unavailable_requests', 'sentences_total',
        'responses_with_uncertain', 'uncertain_sentences', 'regeneration_contradiction', 'regeneration_coverage', 'regeneration_empty',
        // Phase 4D: fact-path requests with no supported fact, answered without a model call.
        'deterministic_absence_answers',
    ];

    /**
     * Bounded buckets for UNCERTAIN sentences — counts only, never the
     * sentence: by answer language and by the planned task types involved.
     */
    private const UNCERTAIN_LANGUAGES = ['tr', 'en', 'ru', 'other'];

    private const UNCERTAIN_TASK_TYPES = ['opening_hours', 'required_documents', 'location', 'route', 'current_menu', 'current_events',
        'program_language', 'programme_duration', 'academic_dates', 'contact_details', 'club_social_profile', 'find_food_places', 'filter_open_now', 'rank_by_distance'];

    private const LATENCY_KEY = 'ai:rollout:latency_ms';

    private const LATENCY_SAMPLES = 500;

    private ?string $path = null;

    private ?string $fallbackReason = null;

    private ?array $prepared = null;

    /** @var array<string, mixed> */
    private array $signals = [];

    /** A controlled diagnostic probe: traced, but never counted in the rollout aggregates. */
    private bool $diagnostic = false;

    public function markDiagnostic(): void
    {
        $this->diagnostic = true;
    }

    public static function mode(): string
    {
        $mode = (string) config('ai.supported_facts.mode', 'off');

        return in_array($mode, ['off', 'staff_only', 'on'], true) ? $mode : 'off';
    }

    /** Decide the path for the request's own user (the controller knows them; auth() may not on every guard). */
    public function decideFor(PlanningResult $planning, ?User $user): void
    {
        if ($this->path === null && $planning->facts !== null && $planning->planned()) {
            $this->path = $this->decide($user);
        }
    }

    /** Whether this planned question is generated from its SupportedFacts. */
    public function usesFacts(PlanningResult $planning): bool
    {
        if ($planning->facts === null || ! $planning->planned()) {
            return false;
        }
        $this->path ??= $this->decide(request()->user() ?? auth()->user());

        return $this->path === self::PATH_SUPPORTED_FACTS;
    }

    public function path(): ?string
    {
        return $this->path;
    }

    private function decide(mixed $user): string
    {
        return match (self::mode()) {
            'on' => self::PATH_SUPPORTED_FACTS,
            'staff_only' => $user instanceof User && GranularPermissions::canAccessAdminPanel($user) ? self::PATH_SUPPORTED_FACTS : self::PATH_LEGACY,
            default => self::PATH_LEGACY,
        };
    }

    /**
     * Builds the fact block before any model call, so a technical failure is
     * known while there is still a choice. Returns false when the fact path
     * cannot be used for this request (the path is then legacy or withheld).
     */
    public function prepare(PlanningResult $planning): bool
    {
        if (! $this->usesFacts($planning)) {
            return false;
        }
        try {
            $this->prepared = app(FactPromptBlock::class)->build($planning->facts);

            return true;
        } catch (Throwable $e) {
            $this->technicalFailure($e, 'prompt', self::legacySafe($planning->facts));

            return false;
        }
    }

    /** @return array{text: string, sources: list<array<string, mixed>>}|null */
    public function prepared(): ?array
    {
        return $this->prepared;
    }

    /**
     * Record a technical failure of the fact path. `$legacyAllowed` says
     * whether the Phase 3B model path may take over (see the class doc).
     */
    public function technicalFailure(Throwable $e, string $stage, bool $legacyAllowed): void
    {
        $this->fallbackReason = self::FALLBACK_PATH_ERROR;
        $this->path = $legacyAllowed ? self::PATH_LEGACY : 'deterministic_fallback';
        $this->bump('path_errors');
        $this->bump($legacyAllowed ? 'path_error_legacy_fallbacks' : 'path_error_deterministic_fallbacks');
        if ($stage === 'claim_verification') {
            $this->bump('claim_verification_failures');
        }
        Log::warning('aicad.supported_facts.path_error', ['stage' => $stage, 'exception' => class_basename($e), 'legacy_fallback' => $legacyAllowed]);
        app(AskTrace::class)->record('supported_facts_fallback', ['reason' => self::FALLBACK_PATH_ERROR, 'stage' => $stage,
            'exception' => class_basename($e), 'fallback' => $this->path]);
    }

    /**
     * The deterministic absence answer when this request is on the fact path
     * and its plan has no supported fact at all (outcome UNAVAILABLE); null
     * otherwise. A PARTIAL plan still generates: it has verified facts to say.
     */
    public function absenceAnswer(PlanningResult $planning, ?string $language): ?string
    {
        if ($this->path !== self::PATH_SUPPORTED_FACTS || $planning->facts === null || $planning->facts->plan->outcome !== 'UNAVAILABLE') {
            return null;
        }
        $this->bump('deterministic_absence_answers');
        app(AskTrace::class)->record('supported_facts_absence', ['outcome' => 'UNAVAILABLE', 'model_called' => false]);

        return app(FactSupplement::class)->absence($planning->facts, $language);
    }

    /** True when the deterministic fallback must answer (a fact-path failure with missing data). */
    public function mustUseDeterministicFallback(): bool
    {
        return $this->path === 'deterministic_fallback';
    }

    /**
     * Only a plan whose every task has its facts may be handed to the looser
     * Phase 3B prompt after a technical failure.
     */
    public static function legacySafe(?FactResult $facts): bool
    {
        if ($facts === null) {
            return true;
        }

        return collect($facts->plan->tasks)->every(fn (array $t) => in_array($t['status'], [AnswerPlan::SUPPORTED, AnswerPlan::CONTEXT_MISSING], true));
    }

    /** @param array<string, mixed> $signals */
    public function addSignals(array $signals): void
    {
        $this->signals = array_merge($this->signals, $signals);
    }

    /**
     * The per-request rollout trace, and the aggregate counters. Called once,
     * when the answer is final.
     *
     * @param  list<array<string, mixed>>  $attempts  the model.attempt stages of this request
     */
    public function finish(PlanningResult $planning, array $attempts, float $durationMs, bool $aiUnavailable = false, ?string $language = null): void
    {
        if (! $planning->planned()) {
            return;
        }
        if ($aiUnavailable) {
            $this->signals['ai_unavailable'] = true;
        }
        $path = $this->path ?? self::PATH_LEGACY;
        $outcome = $planning->facts?->plan->outcome ?? $planning->evidence?->outcome->value;
        if ($this->fallbackReason !== null && ($this->signals['answer_withheld'] ?? false)) {
            $outcome = 'FAILED';
        }
        app(AskTrace::class)->record('generation_path', array_filter([
            'supported_facts_mode' => self::mode(),
            'supported_facts_enabled' => $path === self::PATH_SUPPORTED_FACTS,
            'generation_path' => $path,
            'fallback_reason' => $this->fallbackReason,
            'generation_attempts' => count($attempts),
            'request_outcome' => $outcome,
        ] + $this->signals, fn ($v) => $v !== null));

        if ($path === self::PATH_SUPPORTED_FACTS) {
            $this->bump('supported_facts_requests');
            $this->bump('model_calls', count($attempts));
            $this->bump('regenerations', ($this->signals['regenerated'] ?? false) ? 1 : 0);
            $this->bump('unsupported_claims_removed', (int) ($this->signals['unsupported_claims_removed'] ?? 0));
            $this->bump('speculative_claims_removed', (int) ($this->signals['speculative_claims_removed'] ?? 0));
            $this->bump('answers_withheld', ($this->signals['answer_withheld'] ?? false) ? 1 : 0);
            $this->bump('restatements', (int) ($this->signals['restated_fact_count'] ?? 0));
            $this->bump('sentences_total', (int) ($this->signals['final_claim_count'] ?? 0));
            $uncertain = (int) ($this->signals['uncertain_claim_count'] ?? 0);
            if ($uncertain > 0) {
                $this->bump('responses_with_uncertain');
                $this->bump('uncertain_sentences', $uncertain);
                $this->bumpBucket('uncertain_lang', in_array($language, self::UNCERTAIN_LANGUAGES, true) ? $language : 'other', $uncertain);
                foreach (array_unique(array_map(fn ($t) => $t->type, $planning->plan?->tasks ?? [])) as $type) {
                    $this->bumpBucket('uncertain_task', $type, $uncertain);
                }
            }
            $this->bump(match ($this->signals['regeneration_reason'] ?? null) {
                'contradicts_supported_fact' => 'regeneration_contradiction',
                'removal_drops_task_coverage' => 'regeneration_coverage',
                'removal_leaves_nothing' => 'regeneration_empty',
                default => 'none',
            });
            $this->latency($durationMs);
        } else {
            $this->bump('legacy_planned_requests');
        }
        if ($outcome !== null) {
            $this->bump('outcome_'.strtolower($outcome));
        }
        $this->bump('ai_unavailable', $aiUnavailable ? 1 : 0);
        $absent = collect($planning->facts?->plan->tasks ?? [])->contains(fn (array $t) => isset($t['absence']));
        $this->bump('data_unavailable_requests', $absent ? 1 : 0);
    }

    /** A bounded bucket counter: only known names, never free text. */
    private function bumpBucket(string $family, string $name, int $by): void
    {
        $allowed = $family === 'uncertain_lang' ? self::UNCERTAIN_LANGUAGES : self::UNCERTAIN_TASK_TYPES;
        if ($this->diagnostic || $by <= 0 || ! in_array($name, $allowed, true)) {
            return;
        }
        $key = 'ai:rollout:'.$family.':'.$name;
        Cache::add($key, 0, now()->addDays(30));
        Cache::increment($key, $by);
    }

    public function bump(string $name, int $by = 1): void
    {
        if ($this->diagnostic || $by <= 0 || ! in_array($name, self::COUNTERS, true)) {
            return;
        }
        $key = 'ai:rollout:'.$name;
        Cache::add($key, 0, now()->addDays(30));
        Cache::increment($key, $by);
    }

    private function latency(float $ms): void
    {
        if ($this->diagnostic) {
            return;
        }
        $samples = (array) Cache::get(self::LATENCY_KEY, []);
        $samples[] = (int) round($ms);
        Cache::put(self::LATENCY_KEY, array_slice($samples, -self::LATENCY_SAMPLES), now()->addDays(30));
    }

    /**
     * Safe aggregates for the health page: counts, rates and latency
     * percentiles over the last samples. No content.
     *
     * @return array<string, mixed>
     */
    public static function aggregates(): array
    {
        $c = [];
        foreach (self::COUNTERS as $name) {
            $c[$name] = (int) Cache::get('ai:rollout:'.$name, 0);
        }
        $samples = (array) Cache::get(self::LATENCY_KEY, []);
        sort($samples);
        $pct = fn (float $p) => $samples === [] ? null : $samples[(int) min(count($samples) - 1, max(0, (int) ceil($p * count($samples)) - 1))];
        $sf = $c['supported_facts_requests'];
        $planned = $sf + $c['legacy_planned_requests'];
        $rate = fn (int $n, int $of) => $of === 0 ? null : round($n / $of, 4);
        $buckets = fn (string $family, array $names) => array_filter(array_combine($names,
            array_map(fn ($n) => (int) Cache::get('ai:rollout:'.$family.':'.$n, 0), $names)));

        return [
            'mode' => self::mode(),
            'counters' => $c,
            'regeneration_rate' => $rate($c['regenerations'], $sf),
            'model_calls_per_request' => $sf === 0 ? null : round($c['model_calls'] / $sf, 2),
            'latency_ms' => ['median' => $pct(0.5), 'p95' => $pct(0.95), 'n' => count($samples)],
            // Rates the soak period is judged on (docs/AICAD_ROLLOUT.md).
            'rates' => [
                'path_error_rate' => $rate($c['path_errors'], $planned),
                'technical_fallback_rate' => $rate($c['path_error_legacy_fallbacks'] + $c['path_error_deterministic_fallbacks'], $planned),
                'uncertain_response_rate' => $rate($c['responses_with_uncertain'], $sf),
                'uncertain_sentence_rate' => $rate($c['uncertain_sentences'], $c['sentences_total']),
                'regeneration_rate' => $rate($c['regenerations'], $sf),
                'withheld_rate' => $rate($c['answers_withheld'], $sf),
                'data_unavailable_rate' => $rate($c['data_unavailable_requests'], $planned),
                'ai_unavailable_rate' => $rate($c['ai_unavailable'], $planned),
            ],
            'uncertain_by_language' => $buckets('uncertain_lang', self::UNCERTAIN_LANGUAGES),
            'uncertain_by_task_type' => $buckets('uncertain_task', self::UNCERTAIN_TASK_TYPES),
        ];
    }

    public static function resetAggregates(): void
    {
        foreach (self::COUNTERS as $name) {
            Cache::forget('ai:rollout:'.$name);
        }
        foreach (self::UNCERTAIN_LANGUAGES as $n) {
            Cache::forget('ai:rollout:uncertain_lang:'.$n);
        }
        foreach (self::UNCERTAIN_TASK_TYPES as $n) {
            Cache::forget('ai:rollout:uncertain_task:'.$n);
        }
        Cache::forget(self::LATENCY_KEY);
    }
}
