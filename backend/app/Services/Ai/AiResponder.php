<?php

namespace App\Services\Ai;

use App\Models\IntegrationState;
use App\Models\KnowledgeDocument;
use App\Services\Ai\Facts\SupportedFactsRollout;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The production AI flow: cache → primary (Groq, with retry/backoff) → optional
 * fallback (Local AI, only if configured) → controlled failure.
 *
 * A failed Groq request never crashes the assistant: it is classified
 * (rate-limited / quota / timeout / outage), recorded for the panel, and the
 * caller falls back to a deterministic non-AI answer. The Local-AI fallback is
 * attempted ONLY when a local endpoint is actually configured; otherwise it is
 * silently skipped.
 */
class AiResponder
{
    public function __construct(
        private readonly AiProviderManager $providers,
        private readonly AiCircuitBreaker $breaker,
        private readonly AiTelemetry $telemetry,
        private readonly AiBudget $budget = new AiBudget,
    ) {}

    /**
     * @param  callable(): list<array{role: string, content: string}>  $buildMessages
     *                                                                                 Builds the full payload (system+history+user). A CLOSURE so that a
     *                                                                                 cache hit — or a missing primary — costs zero agent/DB work, which
     *                                                                                 is the point of caching for reducing Groq usage.
     * @param  string|null  $cacheBasis  A stable string identifying a repeatable,
     *                                   non-personalized question (normalized text + language). Null = do
     *                                   not cache (e.g. multi-turn conversations).
     */
    /**
     * @param  callable|null  $acceptable  Returns false for an answer that must
     *                                     not be trusted or cached (see AnswerGrounding). A rejected answer is
     *                                     still returned to the caller, which decides how to degrade — but it
     *                                     never enters the shared cache, so one fabricated reply cannot be
     *                                     served to everyone for the rest of the TTL.
     */
    public function generate(
        callable $buildMessages,
        ?string $cacheBasis = null,
        ?callable $acceptable = null,
        ?AiPrivacy $privacy = null,
    ): AiResult {
        // No privacy stated means "treat it as personal". A caller that
        // forgets to classify its prompt must not thereby opt a student's
        // data into leaving campus.
        $privacy ??= AiPrivacy::personal();

        $this->telemetry->bump(AiTelemetry::REQUESTS);

        $cacheKey = $this->cacheKey($cacheBasis);
        if ($cacheKey !== null) {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                $this->telemetry->bump(AiTelemetry::CACHE_HITS);

                return new AiResult($cached, null, 'cached', cached: true);
            }
        }

        // The privacy policy is applied HERE, where the provider is chosen,
        // rather than at the call site: every caller of generate() then
        // inherits it, including ones written later. An external provider
        // that is refused is simply not in the chain, so the request
        // degrades to knowledge-only exactly as it would if that provider
        // were down.
        $primary = $this->permitted($this->providers->primary(), $privacy);
        $fallback = $this->permitted($this->providers->fallback(), $privacy);

        /*
         * The campus-wide ceiling.
         *
         * Claimed AFTER the cache lookup, so a cached answer never costs
         * a slot — at three thousand students the cache is what most
         * questions are answered from, and charging them against the
         * budget would close it for no reason.
         *
         * A refusal here is not an error: it means "too many at once" or
         * "today's allowance is spent", and both fall through to the
         * knowledge-only path below with a provider that was never
         * called.
         */
        $refusal = $this->budget->claim();
        if ($refusal !== null) {
            $this->telemetry->bump(AiTelemetry::BUDGET_REFUSALS);

            return $this->knowledgeOnly($primary?->key(), $refusal);
        }

        if ($primary === null && $fallback === null) {
            // Nothing may answer. Distinguished from "everything failed" so
            // the panel can say WHY: a policy refusal is not an outage.
            $reason = $privacy->refusalReason() ?? 'no_provider_configured';
            $this->telemetry->bump(AiTelemetry::KNOWLEDGE_ONLY);
            $this->budget->release();

            return $this->knowledgeOnly(null, $reason);
        }

        try {
            return $this->attemptProviders($buildMessages, $cacheKey, $acceptable, $primary, $fallback);
        } finally {
            // In a finally, so a provider throwing does not leak the
            // slot. A leaked slot is permanent until its TTL expires and
            // shrinks the ceiling for everyone.
            $this->budget->release();
        }
    }

    /**
     * The provider, or null when this request may not use it.
     *
     * Logged when it refuses, because a silently shorter provider chain is
     * indistinguishable from a misconfiguration when someone is trying to
     * work out why answers went knowledge-only.
     */
    private function permitted(?AiProvider $provider, AiPrivacy $privacy): ?AiProvider
    {
        if ($provider === null || $privacy->allows($provider)) {
            return $provider;
        }

        $this->telemetry->bump(AiTelemetry::PRIVACY_REFUSALS);
        Log::info('ai.provider.refused_by_privacy_policy', [
            'provider' => $provider->key(),
            'reason' => $privacy->refusalReason(),
        ]);

        return null;
    }

    /**
     * @param  callable(): list<array{role: string, content: string}>  $buildMessages
     */
    private function attemptProviders(
        callable $buildMessages,
        ?string $cacheKey,
        ?callable $acceptable,
        ?AiProvider $primary,
        ?AiProvider $fallback,
    ): AiResult {
        $messages = null;

        // Nothing configured, or the breaker is open (Groq in cooldown): do not
        // even build context for a call we will not make.
        $skipPrimary = $primary === null || $this->breaker->isOpen();

        if (! $skipPrimary) {
            $messages = $buildMessages();
            $this->telemetry->bump(AiTelemetry::GROQ_CALLS);
            $outcome = $this->attemptWithRetry($primary, $messages);
            if ($outcome->isOk()) {
                $this->recordSuccess($primary->key());
                $this->breaker->recordSuccess();
                if ($acceptable === null || $acceptable($outcome->text)) {
                    $this->store($cacheKey, $outcome->text);
                }

                return new AiResult($outcome->text, $primary->key(), AiCompletion::OK);
            }
            $this->recordFailure($primary->key(), $outcome);
            $this->breaker->recordFailure($outcome->status);
            $this->telemetry->bump(AiTelemetry::GROQ_FAILURES);
            if ($outcome->status === AiCompletion::RATE_LIMITED) {
                $this->telemetry->bump(AiTelemetry::RATE_LIMITS);
            } elseif ($outcome->status === AiCompletion::QUOTA) {
                $this->telemetry->bump(AiTelemetry::QUOTA_ERRORS);
            }
        }

        // Optional Local AI fallback — only when actually configured.
        if ($fallback !== null) {
            $messages ??= $buildMessages();
            $fbOutcome = $this->attemptWithRetry($fallback, $messages);
            if ($fbOutcome->isOk()) {
                $this->recordSuccess($fallback->key());
                $this->telemetry->bump(AiTelemetry::LOCAL_FALLBACKS);
                if ($acceptable === null || $acceptable($fbOutcome->text)) {
                    $this->store($cacheKey, $fbOutcome->text);
                }

                return new AiResult($fbOutcome->text, $fallback->key(), AiCompletion::OK, usedFallback: true);
            }
            $this->recordFailure($fallback->key(), $fbOutcome);
        }

        return $this->knowledgeOnly($primary?->key(), 'providers_failed');
    }

    /**
     * Knowledge-Only degraded mode: no model answered, for whatever
     * reason. The caller answers from indexed ARUCAD sources instead —
     * grounded, marked as source-based. Never a crash, never a
     * hallucinated answer.
     */
    private function knowledgeOnly(?string $providerKey, string $reason): AiResult
    {
        if ((bool) config('ai.allow_knowledge_only_fallback', true)) {
            $this->telemetry->bump(AiTelemetry::KNOWLEDGE_ONLY);

            return new AiResult(null, $providerKey, 'knowledge_only', knowledgeOnly: true);
        }

        return new AiResult(null, $providerKey, 'unavailable');
    }

    private function attemptWithRetry(AiProvider $provider, array $messages): AiCompletion
    {
        $retries = max(0, (int) config('ai.retries', 1));
        $backoff = max(0, (int) config('ai.retry_backoff_ms', 400));

        $outcome = $provider->attempt($messages);
        $tries = 0;
        // Retry only transient failures (rate limit / timeout / 5xx). Quota,
        // auth and unconfigured will not succeed on retry, so fall through fast.
        while (! $outcome->isOk() && $outcome->isTransient() && $tries < $retries) {
            if ($backoff > 0) {
                usleep($backoff * 1000 * (2 ** $tries)); // exponential backoff
            }
            $tries++;
            $outcome = $provider->attempt($messages);
        }

        return $outcome;
    }

    /**
     * A cache key that changes when the knowledge base is re-crawled, OR when
     * the instructions that produced the answer change.
     *
     * The second half was missing and it mattered. A cached answer lives for
     * six hours and was invalidated only by a re-crawl, so editing a rule left
     * the previous wording's answers in circulation — measured during the
     * multilingual work, where a Russian library question kept returning
     * invented opening hours from before the fix while the English one
     * returned the correct hours from the canonical row. Same fact, two
     * answers, and the only difference was which of them a stale cache
     * happened to hold.
     *
     * AskPromptBuilder::version() fingerprints the rules themselves, so this
     * needs nobody to remember to bump anything.
     */
    private function cacheKey(?string $basis): ?string
    {
        if ($basis === null || ! (bool) config('ai.cache.enabled', true)) {
            return null;
        }

        return 'ai:answer:'.sha1(
            $basis
            .'|kv:'.$this->knowledgeStamp()

            // Aliases, embedded passages, live campus rows and today's date:
            // see RetrievalVersion.
            .'|r:'.RetrievalVersion::stamp()
            // The rules AND the institution profile: an operator correcting
            // who we are must not keep serving the old answer from cache.
            .'|p:'.AskPromptBuilder::version()
            .'|i:'.app(InstitutionProfile::class)->version()
            // The generation mode: an answer made under one rollout mode is
            // never served under another (fact-generated answers themselves
            // are planned questions, which are not cached at all).
            .'|sf:'.SupportedFactsRollout::mode(),
        );
    }

    /**
     * Freshness stamp: the newest knowledge fetch time. A re-crawl advances it,
     * so every previously cached answer keyed on the old stamp is naturally
     * bypassed (and expires on its own TTL).
     */
    private function knowledgeStamp(): string
    {
        $max = KnowledgeDocument::query()->max('fetched_at');

        return (string) ($max ?? 'none');
    }

    private function store(?string $cacheKey, ?string $text): void
    {
        if ($cacheKey === null || $text === null || $text === '') {
            return;
        }
        Cache::put($cacheKey, $text, now()->addMinutes((int) config('ai.cache.ttl_minutes', 360)));
    }

    /** Map a provider key to its integration key for panel status. */
    private function integrationKey(string $providerKey): string
    {
        return $providerKey === 'local' ? 'local_ai' : $providerKey;
    }

    private function recordSuccess(string $providerKey): void
    {
        IntegrationState::updateOrCreate(['key' => $this->integrationKey($providerKey)], [
            'last_success_at' => now(),
            'last_test_at' => now(),
            'last_test_ok' => true,
            'last_error' => null,
            'last_error_at' => null,
        ]);
    }

    private function recordFailure(string $providerKey, AiCompletion $outcome): void
    {
        // Human, safe message — never the provider's raw body (which can echo
        // the request/key). Just the classified status.
        $message = match ($outcome->status) {
            AiCompletion::RATE_LIMITED => 'Groq hız sınırı (429).',
            AiCompletion::QUOTA => 'Groq kotası doldu (429).',
            AiCompletion::TIMEOUT => 'Zaman aşımı — sağlayıcıya ulaşılamadı.',
            AiCompletion::UNAUTHORIZED => 'API anahtarı reddedildi (401).',
            AiCompletion::UNCONFIGURED => 'Yapılandırılmamış.',
            default => 'Sağlayıcı hatası'.($outcome->httpStatus ? ' (HTTP '.$outcome->httpStatus.').' : '.'),
        };

        IntegrationState::updateOrCreate(['key' => $this->integrationKey($providerKey)], [
            'last_test_at' => now(),
            'last_test_ok' => false,
            'last_error' => Str::limit($message, 500, ''),
            'last_error_at' => now(),
        ]);
    }
}
