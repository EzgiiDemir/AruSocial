<?php

namespace App\Services\Moderation\Image;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to the internal FastAPI classifier over multipart.
 *
 * Every failure path in here returns `unavailable`. That is the point:
 * the caller decides to hold content based on one boolean, and there is
 * no branch that can turn "I could not reach the model" into a score.
 */
final class FastApiImageModerationProvider implements ImageModerationProvider
{
    public function isConfigured(): bool
    {
        return (bool) config('moderation.image.enabled', false)
            && trim((string) config('moderation.image.base_url')) !== '';
    }

    public function inspect(string $bytes, string $filename = 'upload'): ImageSignal
    {
        if (! $this->isConfigured()) {
            return ImageSignal::unavailable('NOT_CONFIGURED',
                'No visual classifier is configured for this installation.');
        }

        if ($bytes === '') {
            return ImageSignal::rejected('EMPTY_FILE', 'No image bytes to inspect.');
        }

        $url = rtrim((string) config('moderation.image.base_url'), '/').'/v1/moderate/image';

        try {
            $response = Http::timeout((float) config('moderation.image.timeout', 20))
                ->attach('file', $bytes, $filename)
                ->post($url);
        } catch (\Throwable $e) {
            // Connection refused, DNS failure, timeout. Expected in
            // operation; logged so a dead scanner is visible rather than
            // quietly turning into a growing review queue.
            Log::error('moderation.image.unreachable', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return ImageSignal::unavailable('UNREACHABLE', $e->getMessage());
        }

        if ($response->status() === 400) {
            // The service refused the file itself: not decodable, too big,
            // too many pixels. A user error, not a policy violation.
            $detail = $response->json('detail');

            return ImageSignal::rejected(
                is_array($detail) ? (string) ($detail['code'] ?? 'INVALID_IMAGE') : 'INVALID_IMAGE',
                is_array($detail) ? (string) ($detail['message'] ?? '') : (string) $response->body(),
            );
        }

        if (! $response->successful()) {
            Log::error('moderation.image.provider_error', [
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 500),
            ]);

            return ImageSignal::unavailable('HTTP_'.$response->status(),
                mb_substr((string) $response->body(), 0, 300));
        }

        $body = $response->json();

        // A 200 carrying a body we cannot read is not a successful
        // inspection. Without this check a malformed response would parse
        // to an empty score array and read exactly like "nothing found".
        if (! is_array($body) || ($body['success'] ?? false) !== true || ! is_array($body['scores'] ?? null)) {
            Log::error('moderation.image.malformed_response', [
                'body' => mb_substr((string) $response->body(), 0, 500),
            ]);

            return ImageSignal::unavailable('MALFORMED_RESPONSE',
                'Provider returned 200 with an unusable body.');
        }

        $scores = [];
        foreach ($body['scores'] as $label => $score) {
            if (! is_numeric($score)) {
                return ImageSignal::unavailable('MALFORMED_RESPONSE',
                    'Provider returned a non-numeric score.');
            }
            $scores[mb_strtolower((string) $label)] = (float) $score;
        }

        if ($scores === []) {
            return ImageSignal::unavailable('MALFORMED_RESPONSE',
                'Provider returned no categories.');
        }

        return ImageSignal::scored(
            scores: $scores,
            model: isset($body['model']) ? (string) $body['model'] : null,
            modelVersion: isset($body['model_version']) ? (string) $body['model_version'] : null,
            latencyMs: isset($body['latency_ms']) ? (int) $body['latency_ms'] : null,
        );
    }
}
