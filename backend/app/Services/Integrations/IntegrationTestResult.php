<?php

namespace App\Services\Integrations;

/**
 * Outcome of one connection test.
 *
 * Two messages on purpose. [$message] is what an administrator reads and is
 * written to be actionable without being diagnostic; [$logContext] is what
 * goes to the server log. Provider responses frequently echo back part of the
 * request — including the credential — so the provider's own text never
 * becomes the operator-facing message.
 */
final class IntegrationTestResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly array $logContext = [],
        /** True when the test could not run at all (no credentials yet). */
        public readonly bool $skipped = false,
    ) {}

    public static function success(string $message, array $logContext = []): self
    {
        return new self(true, $message, $logContext);
    }

    public static function failure(string $message, array $logContext = []): self
    {
        return new self(false, $message, $logContext);
    }

    /**
     * Not an error: there is simply nothing configured to test yet. Keeps a
     * Not Configured integration from being coloured red for the operator who
     * has not finished setting it up.
     */
    public static function skipped(string $message): self
    {
        return new self(false, $message, [], true);
    }
}
