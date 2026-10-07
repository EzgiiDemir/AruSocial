<?php

namespace App\Services;

use App\Models\CampusPresence;
use App\Models\Place;
use App\Models\User;
use App\Support\CampusGeofence;
use Illuminate\Support\Facades\Cache;

/**
 * Where people actually are right now, as opposed to where they chose to
 * announce themselves.
 *
 * A check-in is a deliberate social act: most students never make one, so
 * the map's "how busy is this building" signal was only ever counting the
 * handful who did. This counts location pings instead — the app sends its
 * current fix while the map is open, the server resolves it to the nearest
 * place and stores only that, and the map reports the number of distinct
 * people resolved to each place inside [WINDOW_MINUTES].
 *
 * Privacy is enforced here, server-side, not by the client choosing not to
 * send:
 *  - a student whose `location_visibility` is `ghost` is never recorded,
 *    and any row they already had is deleted on their next ping;
 *  - coordinates are never stored, only the resolved place id;
 *  - the read side returns counts only — never who, never when.
 */
class LiveCrowd
{
    /** A ping older than this no longer counts as "here now". */
    public const WINDOW_MINUTES = 10;

    public static function radiusMeters(): float
    {
        return (float) config('services.presence.radius_meters', 75);
    }

    /**
     * Record [$me]'s current position as presence at the nearest place.
     *
     * Returns the resolved place, or null when nothing was recorded —
     * ghost mode, off campus, or simply not close enough to any place. In
     * every null case an existing row is removed rather than left to go
     * stale, so "I walked away" is as honest as "I arrived".
     */
    public static function record(User $me, float $lat, float $lng): ?Place
    {
        self::pruneOccasionally();

        if (! self::sharesLocation($me)) {
            self::forget($me);

            return null;
        }

        if (! CampusGeofence::contains($lat, $lng)) {
            self::forget($me);

            return null;
        }

        $place = self::nearestPlaceWithin($lat, $lng, self::radiusMeters());
        if ($place === null) {
            self::forget($me);

            return null;
        }

        CampusPresence::query()->updateOrCreate(
            ['user_id' => $me->id],
            ['place_id' => $place->id, 'updated_at' => now()],
        );

        return $place;
    }

    /** Ghost means "share with nobody" — including anonymous head counts. */
    public static function sharesLocation(User $me): bool
    {
        return ($me->location_visibility ?: 'ghost') !== 'ghost';
    }

    public static function forget(User $me): void
    {
        CampusPresence::query()->where('user_id', $me->id)->delete();
    }

    /**
     * Live head count per place id, highest first. Places nobody is at are
     * absent rather than present with a zero — an empty result is an
     * honest "nobody is sharing right now", not a broken feed.
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        return CampusPresence::query()
            ->selectRaw('place_id, count(*) as total')
            ->where('updated_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->groupBy('place_id')
            ->orderByDesc('total')
            ->pluck('total', 'place_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * The busiest places right now, resolved to real place rows.
     *
     * @return list<array{placeId: string, name: string, category: string, lat: float, lng: float, count: int}>
     */
    public static function busiest(int $limit = 5): array
    {
        $counts = self::counts();
        if ($counts === []) {
            return [];
        }

        $places = Place::query()
            ->whereIn('id', array_keys($counts))
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($counts as $placeId => $count) {
            $place = $places->get($placeId);
            if (! $place) {
                continue;
            }
            $out[] = [
                'placeId' => $place->id,
                'name' => $place->name,
                'category' => (string) $place->category,
                'lat' => (float) $place->lat,
                'lng' => (float) $place->lng,
                'count' => $count,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** Everyone currently sharing, across all places. */
    public static function totalPresent(): int
    {
        return CampusPresence::query()
            ->where('updated_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->count();
    }

    private static function nearestPlaceWithin(float $lat, float $lng, float $radiusMeters): ?Place
    {
        $best = null;
        $bestDistance = null;

        foreach (self::placeCoordinates() as $row) {
            $distance = CampusGeofence::distanceMeters($lat, $lng, $row['lat'], $row['lng']);
            if ($distance > $radiusMeters) {
                continue;
            }
            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $row['id'];
            }
        }

        return $best === null ? null : Place::find($best);
    }

    /**
     * Place coordinates change only when an admin edits a building, so the
     * nearest-place scan reads them from a short cache instead of loading
     * every place row on every ping.
     *
     * @return list<array{id: string, lat: float, lng: float}>
     */
    private static function placeCoordinates(): array
    {
        return Cache::remember('presence.place_coordinates', now()->addMinutes(10), function (): array {
            return Place::query()
                ->whereNotNull('lat')
                ->whereNotNull('lng')
                ->get(['id', 'lat', 'lng'])
                ->map(fn (Place $p) => [
                    'id' => $p->id,
                    'lat' => (float) $p->lat,
                    'lng' => (float) $p->lng,
                ])
                ->all();
        });
    }

    /**
     * A row outside the window is already invisible to every reader; this
     * is what actually deletes it, so the table never holds a record of
     * where someone was an hour ago. At most once a minute — the delete is
     * indexed and cheap, but it does not need to run on every ping.
     */
    private static function pruneOccasionally(): void
    {
        if (! Cache::add('presence.prune', true, now()->addMinute())) {
            return;
        }

        CampusPresence::query()
            ->where('updated_at', '<', now()->subMinutes(self::WINDOW_MINUTES))
            ->delete();
    }
}
