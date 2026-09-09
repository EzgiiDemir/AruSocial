<?php

namespace App\Services\Moderation;

/**
 * Carries a publishable-but-notable moderation result to the response.
 *
 * Three outcomes let content through and still have something to say:
 * `warned` (published, but the author should know it was borderline),
 * `review` (published, a human will look at it) and `support` (published,
 * and the author is being offered help). None of them are errors, so they
 * never went through the error path — and controllers returned a plain
 * success, so the message was computed and then dropped. The self-harm
 * support text in particular never reached anyone.
 *
 * Request-scoped rather than passed through every controller signature:
 * the alternative was editing fifteen call sites and hoping the sixteenth
 * remembers. One moderation gate runs per request, so a single slot is
 * enough; `take()` clears it so a notice cannot leak into a later response
 * on a reused worker process.
 */
class ModerationNotice
{
    /** @var array<string, mixed>|null */
    private static ?array $pending = null;

    /** Records an outcome worth telling the client about. */
    public static function remember(ModerationOutcome $outcome): void
    {
        // Rejections and bans travel on the error path and must not be
        // duplicated here; a clean result has nothing to report.
        if (! $outcome->isPublishable() || $outcome->status === ModerationOutcome::ALLOWED) {
            return;
        }

        self::$pending = array_filter([
            'status' => $outcome->status,
            'message' => $outcome->message(),
            'categories' => $outcome->categories ?: null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** @return array<string, mixed>|null */
    public static function take(): ?array
    {
        $notice = self::$pending;
        self::$pending = null;

        return $notice;
    }

    /** Test hook: drop anything left over between cases. */
    public static function reset(): void
    {
        self::$pending = null;
    }
}
