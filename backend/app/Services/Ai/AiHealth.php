<?php

namespace App\Services\Ai;

/** Result of a provider liveness probe. */
final class AiHealth
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly bool $skipped = false,
    ) {}

    public static function ok(string $message): self
    {
        return new self(true, $message);
    }

    public static function fail(string $message): self
    {
        return new self(false, $message);
    }

    public static function skipped(string $message): self
    {
        return new self(false, $message, true);
    }
}
