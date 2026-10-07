<?php

namespace App\Services\Ai;

/**
 * One chat-capable LLM backend. Implementations are OpenAI-compatible so a
 * self-hosted model and a hosted one differ only in configuration, never in
 * calling code.
 */
interface AiProvider
{
    /** Stable key: 'local', 'groq', … */
    public function key(): string;

    /** Human label for the admin panel. */
    public function label(): string;

    /** Whether this provider has enough configuration to be used at all. */
    public function isConfigured(): bool;

    /** True for a provider that runs on ARUCAD infrastructure. */
    public function isSelfHosted(): bool;

    /**
     * Run a chat completion. Returns the assistant text, or null on any
     * failure (unreachable, error status, empty) — callers fall back.
     *
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function complete(array $messages): ?string;

    /**
     * One classified completion attempt (ok / rate_limited / quota / timeout /
     * error / unauthorized / unconfigured). Never throws — the router reads the
     * status to decide retry vs fallback.
     *
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function attempt(array $messages): AiCompletion;

    /**
     * Cheap liveness probe for the integrations panel. Does not run a full
     * completion; asks the model list / a tiny request instead.
     */
    public function healthCheck(): AiHealth;
}
