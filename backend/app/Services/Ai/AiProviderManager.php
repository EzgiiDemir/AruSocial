<?php

namespace App\Services\Ai;

/**
 * Chooses which LLM answers, from config, and exposes the roster to the admin
 * panel. Production strategy: Groq is primary; a local model is an OPTIONAL
 * fallback that is used only when it is actually configured. A missing local
 * endpoint is never an error.
 */
class AiProviderManager
{
    /** @var array<string, AiProvider>|null */
    private ?array $providers = null;

    /** @return array<string, AiProvider> */
    public function all(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $this->providers = [
            'local' => new LocalAiProvider((array) config('ai.providers.local', [])),
            'groq' => new GroqProvider((array) config('ai.providers.groq', [])),
        ];

        return $this->providers;
    }

    public function get(string $key): ?AiProvider
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * The primary provider (AI_PROVIDER, default groq) when configured.
     * Null only when the primary itself is not set up.
     */
    public function primary(): ?AiProvider
    {
        $provider = $this->get((string) config('ai.provider', 'groq'));

        return ($provider && $provider->isConfigured()) ? $provider : null;
    }

    /**
     * The optional fallback (AI_FALLBACK_PROVIDER, default local) — returned
     * ONLY when it is actually configured AND is not the same as the primary.
     * A missing local endpoint simply yields null, never an error.
     */
    public function fallback(): ?AiProvider
    {
        $key = (string) config('ai.fallback', '');
        if ($key === '' || $key === (string) config('ai.provider', 'groq')) {
            return null;
        }
        $provider = $this->get($key);

        return ($provider && $provider->isConfigured()) ? $provider : null;
    }

    /**
     * Backwards-compatible "the provider that answers first" — the primary.
     */
    public function active(): ?AiProvider
    {
        return $this->primary();
    }

    /** True when a self-hosted model can answer (primary or fallback). */
    public function hasSelfHosted(): bool
    {
        foreach ($this->all() as $provider) {
            if ($provider->isSelfHosted() && $provider->isConfigured()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Static configuration summary, e.g. "Groq (birincil)" or
     * "Groq (birincil) + Yerel AI (yedek)".
     */
    public function modeLabel(): string
    {
        $primary = $this->primary();
        if ($primary === null) {
            return 'AI yapılandırılmamış';
        }

        $label = $primary->label().' (birincil)';
        $fallback = $this->fallback();
        if ($fallback !== null) {
            $label .= ' + '.$fallback->label().' (yedek)';
        }

        return $label;
    }

    /**
     * LIVE mode for the panel, reflecting the circuit breaker: normal Groq,
     * Groq temporarily down → Local AI fallback, or Knowledge-Only degraded
     * mode when no AI provider can currently serve.
     */
    public function liveModeLabel(): string
    {
        $primary = $this->primary();
        $fallback = $this->fallback();
        $breaker = app(AiCircuitBreaker::class);

        if ($primary === null) {
            return $fallback !== null
                ? $fallback->label().' (birincil AI yok)'
                : 'Yalnızca Bilgi Tabanı (kısıtlı mod)';
        }

        // Primary is Groq. If its circuit is open, it is temporarily skipped.
        if ($breaker->isOpen()) {
            if ($fallback !== null) {
                return $fallback->label().' (yedek aktif — Groq geçici olarak beklemede)';
            }

            return (bool) config('ai.allow_knowledge_only_fallback', true)
                ? 'Yalnızca Bilgi Tabanı (kısıtlı mod — Groq beklemede)'
                : 'Groq geçici olarak kullanılamıyor';
        }

        return $this->modeLabel();
    }
}
