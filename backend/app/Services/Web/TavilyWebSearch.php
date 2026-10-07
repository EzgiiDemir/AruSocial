<?php

namespace App\Services\Web;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tavily Search API.
 *
 * Preferred over a plain search API because Tavily returns the extracted,
 * cleaned text of each result alongside the link. That removes the whole
 * second half of this feature for us: no fetching a stranger's page, no HTML
 * extraction, no SSRF surface, and one request instead of one plus three.
 *
 * [WebResearchService] still fetches pages itself for providers that return
 * links only, so the contract is unchanged — this one simply fills `content`
 * and the fetch is skipped.
 *
 * Requires TAVILY_API_KEY. Without it the container binds [NullWebSearch]
 * and the assistant says it cannot search.
 */
class TavilyWebSearch implements WebSearchProvider
{
    private const ENDPOINT = 'https://api.tavily.com/search';

    public function search(string $query, int $limit = 5): array
    {
        $key = trim((string) config('ai.web_research.key'));
        if ($key === '' || trim($query) === '') {
            return [];
        }

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                // Tavily accepts the key as a bearer token; sending it in a
                // header rather than the body keeps it out of any proxy's
                // request-body logging.
                'Authorization' => 'Bearer '.$key,
            ])
                ->timeout((int) config('ai.web_research.timeout', 8))
                ->post(self::ENDPOINT, [
                    'query' => $query,
                    'max_results' => max(1, min(10, $limit)),
                    // "basic" is a single pass and is what a fallback path
                    // should cost; "advanced" doubles the latency for depth
                    // this feature does not need.
                    'search_depth' => (string) config('ai.web_research.depth', 'basic'),
                    // We want the page text, not Tavily's own synthesis: an
                    // answer composed by another model is not a source, and
                    // citing it would mean grounding our answer in something
                    // nobody can check.
                    'include_answer' => false,
                    'include_raw_content' => false,
                ]);
        } catch (Throwable $e) {
            // A search that fails is a missing capability, not a failed
            // request: the student still gets an answer from what we hold.
            Log::warning('ai.web_search.transport', ['message' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            Log::warning('ai.web_search.http', [
                'status' => $response->status(),
                // The body carries Tavily's reason (bad key, quota) and no
                // student data, so it is safe and useful to keep.
                'body' => mb_substr((string) $response->body(), 0, 200),
            ]);

            return [];
        }

        $results = $response->json('results');
        if (! is_array($results)) {
            return [];
        }

        $out = [];
        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }
            $url = trim((string) ($result['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $content = trim((string) ($result['content'] ?? ''));

            $out[] = [
                'title' => trim((string) ($result['title'] ?? '')),
                'url' => $url,
                'snippet' => mb_substr($content, 0, 300),
                // The extracted page text. Present for Tavily, empty for a
                // links-only provider, which is what makes the fetch in
                // WebResearchService conditional rather than dead code.
                'content' => $content,
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
        return 'tavily';
    }
}
