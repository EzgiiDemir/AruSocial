<?php

namespace App\Services\Moderation;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to OpenAI's dedicated moderation endpoint (omni-moderation-latest).
 *
 * This is a moderation model, not a chat model: it is free, purpose-built,
 * multilingual, and accepts both text and images in one request. The API key
 * lives only in the server environment — the Flutter app never sees it and
 * only ever talks to our own backend.
 *
 * Every failure path returns `unavailable()` rather than a permissive
 * result, so the caller can hold content instead of publishing it unchecked.
 */
class OpenAiModerationClient
{
    public function isConfigured(): bool
    {
        return trim((string) config('services.moderation.openai_key')) !== '';
    }

    /**
     * Moderates text and/or images in a single call.
     *
     * @param  list<string>  $imageUrls  Data URIs or public URLs.
     */
    public function inspect(?string $text, array $imageUrls = []): ProviderResult
    {
        if (! $this->isConfigured()) {
            return ProviderResult::unavailable('not_configured');
        }

        $input = [];
        if ($text !== null && trim($text) !== '') {
            // The endpoint caps input length; moderation only needs enough
            // to judge intent, and abuse is not hidden in the 40th page.
            $input[] = ['type' => 'text', 'text' => mb_substr($text, 0, 8000)];
        }
        foreach ($imageUrls as $url) {
            $input[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
        }

        if ($input === []) {
            return ProviderResult::clean();
        }

        try {
            $response = Http::withToken((string) config('services.moderation.openai_key'))
                ->timeout((int) config('services.moderation.timeout_seconds', 12))
                ->connectTimeout(5)
                ->asJson()
                ->post((string) config('services.moderation.endpoint'), [
                    'model' => (string) config('services.moderation.model'),
                    'input' => $input,
                ]);
        } catch (\Throwable $e) {
            Log::warning('moderation.provider_exception', ['message' => $e->getMessage()]);

            return ProviderResult::unavailable('exception');
        }

        if (! $response->successful()) {
            Log::warning('moderation.provider_http', [
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 300),
            ]);

            return ProviderResult::unavailable('http_'.$response->status());
        }

        $result = $response->json('results.0');
        if (! is_array($result)) {
            return ProviderResult::unavailable('malformed');
        }

        return ProviderResult::fromApi(
            flagged: (bool) ($result['flagged'] ?? false),
            categories: is_array($result['categories'] ?? null) ? $result['categories'] : [],
            scores: is_array($result['category_scores'] ?? null) ? $result['category_scores'] : [],
            model: (string) ($response->json('model') ?: config('services.moderation.model')),
        );
    }
}
