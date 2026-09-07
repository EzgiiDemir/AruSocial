<?php

namespace App\Services;

use App\Models\Checkin;
use App\Models\Review;

// Batched recent-check-in / review-average lookup used by PlaceController
// so GET /places doesn't N+1. Density and rating in the Place JSON are
// derived from these counts, not from the static `places.density` /
// `places.rating` columns (those stay as unused seed leftovers).
class PlacePresence
{
    public const WINDOW_HOURS = 2;

    public const MAX_RECENT_ENTRIES = 3;

    public function __construct(
        public readonly array $recentCheckins,
        public readonly array $avgRatings,
        public readonly array $recentCheckinEntries = [],
    ) {}

    public static function forIds(array $ids): self
    {
        if ($ids === []) {
            return new self([], []);
        }

        $checkins = Checkin::query()
            ->selectRaw('place_id, count(*) as total')
            ->whereIn('place_id', $ids)
            ->where('created_at', '>=', now()->subHours(self::WINDOW_HOURS))
            ->where('visible_to_others', true)
            ->groupBy('place_id')
            ->pluck('total', 'place_id')
            ->all();

        $ratings = Review::query()
            ->selectRaw('place_id, avg(rating) as avg_rating')
            ->whereIn('place_id', $ids)
            ->groupBy('place_id')
            ->pluck('avg_rating', 'place_id')
            ->all();

        // Real check-in entries (who + when) for the "recently checked in"
        // list shown on a place's info sheet. Previously the client faked
        // this list from the place name's hashCode; this is the honest
        // replacement, capped and windowed the same as the density count.
        $entries = Checkin::query()
            ->whereIn('place_id', $ids)
            ->where('created_at', '>=', now()->subHours(self::WINDOW_HOURS))
            ->where('visible_to_others', true)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('place_id')
            ->map(fn ($group) => $group
                ->take(self::MAX_RECENT_ENTRIES)
                ->map(fn (Checkin $c) => [
                    'initial' => self::initialFrom($c->user?->name),
                    'checkedInAt' => $c->created_at?->toIso8601String(),
                ])
                ->values()
                ->all())
            ->all();

        return new self($checkins, $ratings, $entries);
    }

    private static function initialFrom(?string $name): string
    {
        $trimmed = trim((string) $name);

        return $trimmed === '' ? '?' : mb_strtoupper(mb_substr($trimmed, 0, 1)).'.';
    }

    public function recentCheckinsFor(string $placeId): int
    {
        return (int) ($this->recentCheckins[$placeId] ?? 0);
    }

    public function recentCheckinEntriesFor(string $placeId): array
    {
        return $this->recentCheckinEntries[$placeId] ?? [];
    }

    public function densityFor(string $placeId): string
    {
        $n = $this->recentCheckinsFor($placeId);
        if ($n >= 5) {
            return 'busy';
        }
        if ($n >= 2) {
            return 'moderate';
        }

        return 'quiet';
    }

    public function ratingFor(string $placeId): float
    {
        if (! array_key_exists($placeId, $this->avgRatings)) {
            return 0.0;
        }

        return round((float) $this->avgRatings[$placeId], 1);
    }
}
