<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The ceiling that makes one shared API key survive a whole campus.
 *
 * The per-user limiter (20 requests a minute, `throttle:ai`) bounds what
 * one student can do and says nothing about what three thousand of them
 * do together: at that limit the campus could aim sixty thousand
 * requests a minute at a single key. The circuit breaker only reacts
 * *after* the provider starts refusing, which is a smoke alarm, not a
 * budget.
 *
 * Two limits, because they fail differently:
 *
 * **Concurrency** bounds how many calls are in flight at once. It is
 * what stops a lecture ending and four hundred phones asking at the same
 * second from queueing four hundred PHP workers on a provider that
 * answers a few at a time. Exceeding it is momentary and common.
 *
 * **A daily cap** bounds the bill, or on a free tier, the quota. It is
 * what stops the whole day's allowance being spent by eleven in the
 * morning. Exceeding it lasts until midnight and should be rare.
 *
 * Both degrade the same way and neither ever errors: the caller falls
 * through to knowledge-only mode, which answers from indexed ARUCAD
 * sources with no model at all, and tells the student it did. A slightly
 * flatter answer is a far better outcome than a spinner.
 */
class AiBudget
{
    private const CONCURRENCY_KEY = 'ai:budget:inflight';

    private const DAY_KEY = 'ai:budget:day:';

    /**
     * Claim a slot, or explain why not.
     *
     * Returns null when the call may proceed, or a short machine-readable
     * reason when it may not.
     */
    public function claim(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $dailyCap = (int) config('ai.budget.daily_requests', 0);
        if ($dailyCap > 0 && $this->usedToday() >= $dailyCap) {
            $this->noteRefusal('daily_cap');

            return 'daily_cap';
        }

        $maxInFlight = (int) config('ai.budget.max_concurrent', 0);
        if ($maxInFlight > 0) {
            $inFlight = (int) Cache::get(self::CONCURRENCY_KEY, 0);
            if ($inFlight >= $maxInFlight) {
                $this->noteRefusal('busy');

                return 'busy';
            }

            /*
             * Not atomic, and that is a deliberate trade.
             *
             * `Cache::increment` is atomic on Redis and on the database
             * store, but the read above is a separate operation, so two
             * requests can both see "one under the limit" and both
             * proceed. The overshoot is bounded by however many requests
             * land in the same millisecond, and the cost of one extra
             * concurrent call is nothing. A lock around every AI request
             * would add a round trip to the hot path to prevent an
             * occasional off-by-one.
             */
            Cache::increment(self::CONCURRENCY_KEY);

            // A TTL so a crashed worker cannot leak a slot forever. It
            // must comfortably exceed the provider timeout, or a slow
            // call would free its own slot while still running.
            Cache::put(self::CONCURRENCY_KEY, (int) Cache::get(self::CONCURRENCY_KEY, 1),
                now()->addMinutes(5));
        }

        $this->countRequest();

        return null;
    }

    /** Give the slot back. Safe to call even when nothing was claimed. */
    public function release(): void
    {
        if (! $this->enabled() || (int) config('ai.budget.max_concurrent', 0) <= 0) {
            return;
        }

        $inFlight = (int) Cache::get(self::CONCURRENCY_KEY, 0);
        if ($inFlight > 0) {
            Cache::decrement(self::CONCURRENCY_KEY);
        }
    }

    /** Requests already made today, for the health endpoint and the panel. */
    public function usedToday(): int
    {
        return (int) Cache::get(self::DAY_KEY.$this->today(), 0);
    }

    /** @return array{enabled: bool, used_today: int, daily_cap: int, in_flight: int, max_concurrent: int} */
    public function snapshot(): array
    {
        return [
            'enabled' => $this->enabled(),
            'used_today' => $this->usedToday(),
            'daily_cap' => (int) config('ai.budget.daily_requests', 0),
            'in_flight' => (int) Cache::get(self::CONCURRENCY_KEY, 0),
            'max_concurrent' => (int) config('ai.budget.max_concurrent', 0),
        ];
    }

    private function enabled(): bool
    {
        return (bool) config('ai.budget.enabled', true);
    }

    private function countRequest(): void
    {
        $key = self::DAY_KEY.$this->today();

        // Seed before incrementing: `increment` on a missing key is a
        // no-op on some stores, which would leave the counter at zero
        // forever and the cap unreachable.
        if (Cache::get($key) === null) {
            Cache::put($key, 0, now()->addDay()->addHour());
        }
        Cache::increment($key);
    }

    private function noteRefusal(string $reason): void
    {
        // Once a minute at most: when the cap is hit, every request for
        // the rest of the day would otherwise write a line.
        $throttle = 'ai:budget:logged:'.$reason;
        if (Cache::add($throttle, true, 60)) {
            Log::warning('ai.budget.exhausted', ['reason' => $reason] + $this->snapshot());
        }
    }

    private function today(): string
    {
        return now()->toDateString();
    }
}
