<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;

/**
 * Lightweight, secret-free counters to understand and reduce Groq usage:
 * request volume, failures by kind, cache hits, and how often each fallback
 * layer was used. Cache-backed (no table); a rolling snapshot feeds the panel.
 */
class AiTelemetry
{
    public const REQUESTS = 'requests';

    public const GROQ_CALLS = 'groq_calls';

    public const GROQ_FAILURES = 'groq_failures';

    public const RATE_LIMITS = 'rate_limits';

    public const QUOTA_ERRORS = 'quota_errors';

    /** Requests the campus-wide budget declined; see AiBudget. */
    public const BUDGET_REFUSALS = 'budget_refusals';

    public const CACHE_HITS = 'cache_hits';

    public const LOCAL_FALLBACKS = 'local_fallbacks';

    public const KNOWLEDGE_ONLY = 'knowledge_only';

    /**
     * An external provider was skipped because the privacy policy refused
     * it (see AiPrivacy). Counted separately from an outage: "we chose not
     * to send this off campus" and "the provider is down" look identical
     * in the answer and are completely different operationally.
     */
    public const PRIVACY_REFUSALS = 'privacy_refusals';

    /**
     * A prompt-injection attempt in a message the client sent, refused before
     * any model was called. See App\Support\PromptInjection.
     */
    public const INJECTION_REFUSALS = 'injection_refusals';

    /**
     * An injection attempt found inside RETRIEVED content — a crawled page
     * carrying instructions aimed at the assistant.
     *
     * Counted separately from the one above because they mean different
     * things operationally: a user-turn attempt is one person probing, while
     * this one means a page in our own index has been poisoned and someone
     * should go and look at it.
     */
    public const INDIRECT_INJECTIONS = 'indirect_injections';

    /**
     * The built prompt exceeded its token budget and had to be compacted.
     * Non-zero here means answers are being produced with less context than
     * intended; see AskPromptBuilder.
     */
    public const CONTEXT_COMPACTIONS = 'context_compactions';

    /**
     * Questions answered with help from the open web.
     *
     * Worth counting on its own: it is the only path that sends requests to
     * hosts we did not choose, and a sudden rise means our own index has a
     * gap worth crawling rather than researching every time.
     */
    public const WEB_RESEARCH = 'web_research';

    private const KEYS = [
        self::REQUESTS, self::GROQ_CALLS, self::GROQ_FAILURES, self::RATE_LIMITS,
        self::QUOTA_ERRORS, self::CACHE_HITS, self::LOCAL_FALLBACKS, self::KNOWLEDGE_ONLY,
        self::BUDGET_REFUSALS, self::PRIVACY_REFUSALS, self::INJECTION_REFUSALS,
        self::INDIRECT_INJECTIONS, self::CONTEXT_COMPACTIONS,
    ];

    private function key(string $name): string
    {
        return "ai:metrics:{$name}";
    }

    public function bump(string $name, int $by = 1): void
    {
        $cacheKey = $this->key($name);
        // add() seeds the counter to 0 with a 30-day TTL the first time, so
        // increment() has something to increment and the window rolls.
        Cache::add($cacheKey, 0, now()->addDays(30));
        Cache::increment($cacheKey, $by);
    }

    /** @return array<string, int> */
    public function snapshot(): array
    {
        $out = [];
        foreach (self::KEYS as $name) {
            $out[$name] = (int) Cache::get($this->key($name), 0);
        }

        return $out;
    }

    public function reset(): void
    {
        foreach (self::KEYS as $name) {
            Cache::forget($this->key($name));
        }
    }
}
