<?php

namespace App\Support;

/**
 * Values computed at most once per request (or queued job).
 *
 * Bound as a scoped singleton, so it is emptied between requests and jobs —
 * unlike a static property, it can never carry one request's data into the
 * next in a long-running worker.
 */
final class RequestMemo
{
    /** @var array<string, mixed> */
    private array $values = [];

    /**
     * @template T
     *
     * @param  callable(): T  $compute
     * @return T
     */
    public function remember(string $key, callable $compute): mixed
    {
        if (! array_key_exists($key, $this->values)) {
            $this->values[$key] = $compute();
        }

        return $this->values[$key];
    }

    public function forget(string $key): void
    {
        unset($this->values[$key]);
    }

    public function forgetPrefix(string $prefix): void
    {
        foreach (array_keys($this->values) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->values[$key]);
            }
        }
    }
}
