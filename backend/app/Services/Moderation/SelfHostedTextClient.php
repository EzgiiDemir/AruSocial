<?php

namespace App\Services\Moderation;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The semantic text layer, self-hosted.
 *
 * Occupies the same slot the OpenAI client did and returns the same
 * ProviderResult, so ContentModerator needs no knowledge of which one is
 * running. It exists because that account has no quota — `HTTP 429`
 * with `invalid_request_error` on every call — and because this project
 * deliberately does not depend on a paid moderation API.
 *
 * It answers the paraphrase half of text moderation. The deterministic
 * lexicon catches phrasings someone wrote down; measured on the live API
 * it published five of nine realistic harmful posts, because a phrase
 * list cannot cover rewording. The service scores a *margin* — how much
 * more a post resembles a category exemplar than ordinary campus writing
 * — and the two layers block independently, so neither can rescue
 * content the other refused.
 */
final class SelfHostedTextClient
{
    public function isConfigured(): bool
    {
        return (bool) config('moderation.text.enabled', false)
            && trim((string) config('moderation.text.base_url')) !== '';
    }

    public function inspect(?string $text): ProviderResult
    {
        if (! $this->isConfigured()) {
            return ProviderResult::unavailable('not_configured');
        }

        if ($text === null || trim($text) === '') {
            // Nothing to read is not the same as nothing wrong, but there
            // is genuinely no text here — an image-only post is a normal
            // case, not an outage.
            return ProviderResult::clean();
        }

        $base = rtrim((string) config('moderation.text.base_url'), '/');

        try {
            $response = Http::timeout((float) config('moderation.text.timeout', 10))
                ->connectTimeout(3)
                ->acceptJson()
                ->post($base.'/v1/moderate/text', [
                    // Long posts are truncated: intent is not hidden on
                    // the fortieth page, and the model caps input anyway.
                    'text' => mb_substr($text, 0, 4000),
                ]);
        } catch (\Throwable $e) {
            Log::warning('moderation.text.exception', ['message' => $e->getMessage()]);

            return ProviderResult::unavailable('text_exception');
        }

        if (! $response->successful()) {
            Log::warning('moderation.text.http', [
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 200),
            ]);

            return ProviderResult::unavailable('text_http_'.$response->status());
        }

        $margins = $response->json('margins');
        if (! is_array($margins)) {
            return ProviderResult::unavailable('text_malformed');
        }

        return ProviderResult::fromSelfHostedText(
            margins: array_map('floatval', $margins),
            model: (string) $response->json('model'),
        );
    }
}
