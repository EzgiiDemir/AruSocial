<?php

namespace App\Services\Moderation\Image;

/**
 * One visual classifier.
 *
 * An interface rather than a concrete client so a second model (gore,
 * hate symbols) can be added, and a candidate model benchmarked against
 * the incumbent, without any of that reaching policy code.
 *
 * Implementations must never throw for an inspection failure. A network
 * error, a timeout, a 500 or an unreadable body are all normal operating
 * conditions and must come back as `ImageSignal::unavailable()`, because
 * an exception escaping here would be caught somewhere generic and could
 * end up looking like "no problems found".
 */
interface ImageModerationProvider
{
    /**
     * @param  string  $bytes  Raw image bytes. Never a URL — the service must
     *                         not fetch user-supplied addresses (SSRF), and
     *                         bytes it fetched itself are not provably the
     *                         bytes the student uploaded.
     */
    public function inspect(string $bytes, string $filename = 'upload'): ImageSignal;

    /** False when this install has no visual classifier configured at all. */
    public function isConfigured(): bool;
}
