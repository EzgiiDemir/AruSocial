<?php

namespace App\Services\Ai;

/**
 * Outcome of one provider completion attempt, with a classified status so the
 * router can decide whether to fall back and the panel can show why a provider
 * is unhealthy (rate-limited vs quota vs outage vs timeout).
 */
final class AiCompletion
{
    public const OK = 'ok';

    public const RATE_LIMITED = 'rate_limited';   // 429 — retry/backoff or fall back

    public const QUOTA = 'quota';                 // 429 with quota/billing signal

    public const TIMEOUT = 'timeout';             // connection/read timeout

    public const ERROR = 'error';                 // 5xx / unexpected / empty

    public const UNAUTHORIZED = 'unauthorized';   // 401 — bad/absent key

    public const UNCONFIGURED = 'unconfigured';   // provider not set up

    private function __construct(
        public readonly string $status,
        public readonly ?string $text = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $detail = null,
        /** Wall-clock time for this attempt, for health reporting. */
        public readonly ?int $latencyMs = null,
        /** The provider's own token accounting, when it reports any. */
        public readonly ?array $usage = null,
    ) {}

    public static function ok(string $text, ?int $latencyMs = null, ?array $usage = null): self
    {
        return new self(self::OK, $text, latencyMs: $latencyMs, usage: $usage);
    }

    public static function fail(string $status, ?int $httpStatus = null, ?string $detail = null): self
    {
        return new self($status, null, $httpStatus, $detail);
    }

    public function isOk(): bool
    {
        return $this->status === self::OK && $this->text !== null && $this->text !== '';
    }

    /** Transient statuses worth a retry on the SAME provider before falling back. */
    public function isTransient(): bool
    {
        return in_array($this->status, [self::RATE_LIMITED, self::TIMEOUT, self::ERROR], true);
    }
}
