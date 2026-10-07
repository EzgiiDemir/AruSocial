<?php

namespace App\Services\Web;

/**
 * A web search back end.
 *
 * An interface because search is the one part of this feature that cannot be
 * self-hosted: it needs a provider and a key. Keeping it behind a contract
 * means the research pipeline, the ranking, the fetching and the citations
 * are all testable and shippable now, and swapping Brave for Google CSE or
 * anything else later is one class.
 *
 * `isConfigured()` exists so the assistant can say "I cannot search the web"
 * truthfully rather than silently returning nothing and looking broken.
 */
interface WebSearchProvider
{
    /**
     * Results for a query, best first, or [] when the provider cannot answer.
     *
     * Implementations must not throw for an unreachable provider: a search
     * that fails is a missing capability, not a failed request, and the
     * assistant still has to answer the student.
     *
     * `content` is the extracted page text when the provider supplies it
     * (Tavily does) and '' when it returns links only. A provider that fills
     * it saves the research service from fetching the page itself, which is
     * one request instead of several and no SSRF surface at all.
     *
     * @return list<array{title: string, url: string, snippet: string, content?: string}>
     */
    public function search(string $query, int $limit = 5): array;

    /** Whether a key and endpoint are actually present. */
    public function isConfigured(): bool;

    /** Shown in logs and in the "how I answered" metadata. */
    public function label(): string;
}
