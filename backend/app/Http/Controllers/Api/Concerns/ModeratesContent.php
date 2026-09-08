<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\User;
use App\Services\Moderation\ContentModerator;
use App\Services\Moderation\ModerationOutcome;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * One-line moderation gate for controllers.
 *
 * Usage, and the reason it returns a response rather than a boolean:
 *
 *     if ($blocked = $this->moderationBlock($me, $text, 'post', 'feed.store')) {
 *         return $blocked;
 *     }
 *
 * The guard runs *before* the content row is created, so rejected content
 * is never written and never briefly visible. Returning the ready-made
 * error response keeps the four client-visible outcomes (rejected, warned,
 * suspended, unavailable) identical everywhere instead of each controller
 * inventing its own shape.
 */
trait ModeratesContent
{
    /**
     * Returns a JSON error response when the submission must not proceed,
     * or null when it may.
     *
     * @param  list<string>  $imageUrls
     */
    protected function moderationBlock(
        User $user,
        ?string $text,
        string $contentType,
        string $sourceFeature,
        array $imageUrls = [],
    ): ?JsonResponse {
        $outcome = $this->moderate($user, $text, $contentType, $sourceFeature, $imageUrls);

        return $outcome->isPublishable() ? null : $this->moderationError($outcome);
    }

    /** The raw outcome, for callers that need the warned/review signal. */
    protected function moderate(
        User $user,
        ?string $text,
        string $contentType,
        string $sourceFeature,
        array $imageUrls = [],
    ): ModerationOutcome {
        return app(ContentModerator::class)
            ->check($user, $text, $contentType, $sourceFeature, $imageUrls);
    }

    /**
     * Turns a stored media URL into something the moderation provider can
     * actually read.
     *
     * Uploads live on this server — often on localhost or behind a private
     * network — so a bare URL would be unreachable from OpenAI's side and
     * the image would silently go unchecked. Local files are therefore
     * inlined as data URIs; anything already public is passed through.
     * Videos are handled separately (frames are extracted first).
     *
     * @return list<string>
     */
    protected function moderatableImages(?string ...$urls): array
    {
        $out = [];
        foreach ($urls as $url) {
            if ($url === null || trim($url) === '') {
                continue;
            }
            $inlined = \App\Services\Moderation\MediaInliner::toDataUri($url);
            if ($inlined !== null) {
                $out[] = $inlined;
            }
        }

        return $out;
    }

    protected function moderationError(ModerationOutcome $outcome): JsonResponse
    {
        // 503 for a provider outage so clients and monitoring can tell
        // "try again shortly" apart from "we refused this content".
        $status = match ($outcome->status) {
            ModerationOutcome::UNAVAILABLE => 503,
            ModerationOutcome::BANNED => 403,
            default => 400,
        };

        return response()->json([
            'data' => null,
            'meta' => ['request_id' => 'req-'.Str::uuid()],
            'error' => array_merge([
                'code' => $outcome->errorCode(),
                'message' => $outcome->message(),
            ], $outcome->toArray()),
        ], $status);
    }
}
