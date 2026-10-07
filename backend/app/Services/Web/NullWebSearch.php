<?php

namespace App\Services\Web;

/**
 * The provider used when no search key is configured.
 *
 * Deliberately a real class rather than a null check scattered through the
 * pipeline: the rest of the system asks the same questions of it as of a live
 * provider, so "web search is off" is exercised by every test that does not
 * opt in, instead of being a branch nobody runs.
 *
 * It returns nothing and says so. It never invents a result, and the
 * assistant's answer must say it could not search rather than implying it
 * searched and found nothing — those are different facts for a student
 * deciding whether to go and look themselves.
 */
class NullWebSearch implements WebSearchProvider
{
    public function search(string $query, int $limit = 5): array
    {
        return [];
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function label(): string
    {
        return 'disabled';
    }
}
