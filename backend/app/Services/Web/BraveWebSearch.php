<?php

namespace App\Services\Web;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Brave Search API.
 *
 * Chosen over Google Custom Search because it needs one key and no per-site
 * configuration, returns a plain JSON result list, and its free tier is
 * enough for a campus assistant's fallback path. Nothing here is specific to
 * Brave beyond the request shape; see [WebSearchProvider].
 *
 * Requires BRAVE_SEARCH_KEY. Without it the container binds [NullWebSearch]
 * instead and the assistant says it cannot search.
 */
class BraveWebSearch implements WebSearchProvider
{
    private const ENDPOINT = 'https://api.search.brave.com/res/v1/web/search';

    public function search(string $query, int $limit = 5): array
    {
        $key = (string) config('ai.web_research.key');
        if ($key === '' || trim($query) === '') {
            return [];
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'X-Subscription-Token' => $key,
            ])
                ->timeout((int) config('ai.web_research.timeout', 8))
                ->get(self::ENDPOINT, [
                    'q' => $query,
                    'count' => max(1, min(20, $limit)),
                    // Brave's own safe-search; this is a university product.
                    'safesearch' => 'moderate',
                ]);
        } catch (Throwable $e) {
            // A search that fails is a missing capability, not a failed
            // request: the student still gets an answer from what we hold.
            Log::warning('ai.web_search.transport', ['message' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            Log::warning('ai.web_search.http', ['status' => $response->status()]);

            return [];
        }

        $results = $response->json('web.results');
        if (! is_array($results)) {
            return [];
        }

        $out = [];
        foreach ($results as $result) {
            $url = is_array($result) ? (string) ($result['url'] ?? '') : '';
            if ($url === '') {
                continue;
            }

            $out[] = [
                'title' => trim((string) ($result['title'] ?? '')),
                'url' => $url,
                // Brave marks matched terms with <strong>; the snippet is
                // shown to a student and given to a model, so the markup goes.
                'snippet' => trim(strip_tags((string) ($result['description'] ?? ''))),
            ];
        }

        return $out;
    }

    public function isConfigured(): bool
    {
        return trim((string) config('ai.web_research.key')) !== '';
    }

    public function label(): string
    {
        return 'brave';
    }
}
