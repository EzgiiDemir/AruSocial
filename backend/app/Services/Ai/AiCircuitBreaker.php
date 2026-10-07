<?php

namespace App\Services\Ai;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps ARUVERSE from continuously hammering Groq while Groq cannot serve
 * requests. After enough transient failures the circuit "opens" for a cooldown
 * window, during which the primary provider is skipped entirely and requests go
 * straight to the fallback chain. A quota error opens it immediately (quota
 * will not recover in seconds), for a longer cooldown.
 *
 * Cache-backed, so it is shared across web/queue workers and needs no table.
 */
class AiCircuitBreaker
{
    public function __construct(private readonly string $provider = 'groq') {}

    private function openKey(): string
    {
        return "ai:cb:{$this->provider}:open_until";
    }

    private function failKey(): string
    {
        return "ai:cb:{$this->provider}:failures";
    }

    /** True while the provider is in cooldown and should be skipped. */
    public function isOpen(): bool
    {
        $until = Cache::get($this->openKey());

        return $until !== null && Carbon::parse($until)->isFuture();
    }

    /** Seconds remaining in cooldown, or 0 when closed. */
    public function cooldownRemaining(): int
    {
        $until = Cache::get($this->openKey());
        if ($until === null) {
            return 0;
        }
        $seconds = (int) round(now()->diffInSeconds(Carbon::parse($until), false));

        return max(0, $seconds);
    }

    public function recordSuccess(): void
    {
        Cache::forget($this->failKey());
        Cache::forget($this->openKey());
    }

    /**
     * Record a failed attempt. A quota failure trips the breaker at once;
     * other transient failures trip it once the threshold is reached.
     */
    public function recordFailure(string $status): void
    {
        if ($status === AiCompletion::QUOTA) {
            $this->open((int) config('ai.circuit.quota_cooldown_seconds', 900));

            return;
        }

        // Count only transient failures toward the threshold.
        if (! in_array($status, [AiCompletion::RATE_LIMITED, AiCompletion::TIMEOUT, AiCompletion::ERROR], true)) {
            return;
        }

        $failures = (int) Cache::get($this->failKey(), 0) + 1;
        Cache::put($this->failKey(), $failures, now()->addMinutes(5));

        if ($failures >= max(1, (int) config('ai.circuit.failure_threshold', 3))) {
            $this->open((int) config('ai.circuit.cooldown_seconds', 120));
        }
    }

    private function open(int $seconds): void
    {
        Cache::put($this->openKey(), now()->addSeconds($seconds)->toIso8601String(), now()->addSeconds($seconds));
    }

    /** For the admin panel. */
    public function state(): array
    {
        return [
            'open' => $this->isOpen(),
            'cooldownRemaining' => $this->cooldownRemaining(),
            'failures' => (int) Cache::get($this->failKey(), 0),
        ];
    }
}
