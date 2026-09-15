<?php

namespace App\Services;

use App\Models\DirectoryEntry;
use App\Models\Place;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Imports ARUCAD-operated 360 directory data into the local product API.
 *
 * The remote source is authoritative for room hierarchy and 360 navigation
 * (tourUrl / tourTarget). Upstream coordinates, location, and marker fields
 * are still null for campuses/buildings/rooms — we deliberately never invent
 * map pins from that. Instead we bind real 360 tours onto existing verified
 * Place pins (CampusCatalogSeeder) via building-name aliases and, when a
 * building has no tourUrl of its own, the first room tour under that building.
 */
class CampusDirectory360Sync
{
    public function sync(): array
    {
        $apiKey = trim((string) config('services.campus_directory.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('CAMPUS_DIRECTORY_API_KEY is not configured.');
        }

        $baseUrl = rtrim((string) config('services.campus_directory.base_url'), '/');
        if (! filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('CAMPUS_DIRECTORY_BASE_URL must be an absolute URL.');
        }

        // Directory owns the hierarchy; locations owns navigation/location
        // metadata. They currently return the same catalogue, but consuming
        // both contracts prevents a future endpoint split from silently
        // dropping coordinates, markers or 360 scene targets.
        $directory = $this->fetchPayload($baseUrl, 'directory', $apiKey);
        $locations = $this->fetchPayload($baseUrl, 'locations', $apiKey);
        $payload = $this->mergePayloads($directory, $locations);

        return DB::transaction(function () use ($payload, $baseUrl): array {
            $peopleByRoom = [];
            foreach ($payload['people'] ?? [] as $person) {
                if (! is_array($person) || ! is_string($person['roomId'] ?? null)) {
                    continue;
                }
                $peopleByRoom[$person['roomId']][] = $this->localized($person['name'] ?? null);
            }

            $roomsSynced = 0;
            $roomsWithTours = 0;
            $tourByBuilding = [];
            $syncedEntryIds = [];
            foreach ($payload['rooms'] as $room) {
                if (! is_array($room) || ! is_string($room['id'] ?? null)) {
                    continue;
                }

                $roomId = $room['id'];
                $building = $this->localized(data_get($room, 'building.name'));
                if ($building === '') {
                    // A room without a building cannot participate in the
                    // directory hierarchy, so leave it out rather than put it
                    // under an invented campus label.
                    continue;
                }

                $roomName = $this->localized($room['name'] ?? null);
                $navigation = is_array($room['navigation'] ?? null) ? $room['navigation'] : [];
                $tourUrl = $this->absoluteTourUrl($navigation['tourUrl'] ?? null, $baseUrl);
                $tourTarget = is_string($navigation['tourTarget'] ?? null) ? $navigation['tourTarget'] : null;
                $occupants = array_values(array_filter($peopleByRoom[$roomId] ?? []));
                $entryId = '360-'.$roomId;
                $syncedEntryIds[] = $entryId;
                $existing = DirectoryEntry::find($entryId);

                DirectoryEntry::updateOrCreate(['id' => $entryId], [
                    'building' => $building,
                    // The live API currently supplies neither floor nor room
                    // number. Never mislabel `number` as a floor: retain the
                    // raw number separately and expose an honest bucket.
                    'floor' => $this->localized($room['floor'] ?? null) ?: 'Kat belirtilmemiş',
                    'room' => $roomName !== '' ? $roomName : $roomId,
                    'occupant_name' => implode(', ', $occupants),
                    'occupant_role' => $this->localized(data_get($room, 'category.name')) ?: null,
                    // Never wipe a curated service link seeded by CampusCatalogSeeder.
                    'related_service_id' => $existing?->related_service_id,
                    'tour_url' => $tourUrl,
                    'tour_target' => $tourTarget,
                    'campus_id' => data_get($room, 'campus.id'),
                    'campus_name' => $this->localized(data_get($room, 'campus.name')) ?: null,
                    'building_id' => data_get($room, 'building.id'),
                    'category_id' => data_get($room, 'category.id'),
                    'category_name' => $this->localized(data_get($room, 'category.name')) ?: null,
                    'room_number' => is_scalar($room['number'] ?? null)
                        ? trim((string) $room['number']) ?: null : null,
                    'notes' => $this->localized($room['notes'] ?? null) ?: null,
                    'splat_scene_id' => is_string($navigation['splatSceneId'] ?? null)
                        ? $navigation['splatSceneId'] : null,
                    'splat_scene_url' => $this->absoluteTourUrl(
                        $navigation['splatSceneUrl'] ?? null,
                        $baseUrl,
                    ),
                    'location' => is_array($room['location'] ?? null)
                        ? $room['location'] : null,
                    'navigation_marker' => is_array($navigation['marker'] ?? null)
                        ? $navigation['marker'] : null,
                    'directory_synced_at' => now(),
                ]);

                $roomsSynced++;
                if ($tourUrl !== null) {
                    $roomsWithTours++;
                    // First room tour wins as the building fallback when the
                    // building row itself has a null navigation.tourUrl.
                    $tourByBuilding[$this->key($building)] ??= $tourUrl;
                }
            }

            $roomsRemoved = DirectoryEntry::query()
                ->where('id', 'like', '360-%')
                ->when($syncedEntryIds !== [], fn ($query) => $query->whereNotIn('id', $syncedEntryIds))
                ->delete();

            $placesUpdated = 0;
            foreach ($payload['buildings'] ?? [] as $building) {
                if (! is_array($building)) {
                    continue;
                }
                $name = $this->localized($building['name'] ?? null);
                if ($name === '') {
                    continue;
                }
                $navigation = is_array($building['navigation'] ?? null) ? $building['navigation'] : [];
                // Prefer the building's own tour; many buildings only expose
                // tours on rooms (e.g. Iris) — fall back to first room tour.
                $tourUrl = $this->absoluteTourUrl($navigation['tourUrl'] ?? null, $baseUrl)
                    ?? ($tourByBuilding[$this->key($name)] ?? null);
                $tourTarget = is_string($navigation['tourTarget'] ?? null) ? $navigation['tourTarget'] : null;
                $coordinates = $this->coordinates(
                    $building['coordinates']
                        ?? $building['location']
                        ?? data_get($building, 'navigation.marker'),
                );
                $places = $this->placesForDirectoryBuilding($name);
                if ($places === [] && $coordinates !== null) {
                    Place::create([
                        'id' => '360-building-'.strtolower((string) ($building['id'] ?? md5($name))),
                        'name' => $name,
                        'category' => 'Campus building',
                        'lat' => $coordinates['lat'],
                        'lng' => $coordinates['lng'],
                        'description' => '',
                        'distance' => '',
                        'density' => 'quiet',
                        'street' => $this->localized(data_get($building, 'campus.name')),
                        'tour_url' => $tourUrl,
                        'tour_target' => $tourTarget,
                        'accessible' => true,
                        'photos' => 0,
                        'rating' => 0,
                    ]);
                    $placesUpdated++;

                    continue;
                }
                if ($places === []) {
                    continue;
                }
                $changes = [];
                if ($tourUrl !== null) {
                    $changes['tour_url'] = $tourUrl;
                }
                // Only persist tour_target when the API sends one. If the
                // tour URL already carries a #fragment and tourTarget is
                // null, leave the column as-is — the URL is the target.
                if ($tourTarget !== null) {
                    $changes['tour_target'] = $tourTarget;
                }
                if ($coordinates !== null) {
                    $changes['lat'] = $coordinates['lat'];
                    $changes['lng'] = $coordinates['lng'];
                }
                if ($changes === []) {
                    continue;
                }
                foreach ($places as $place) {
                    $place->update($changes);
                    $placesUpdated++;
                }
            }

            // Second pass: any curated Place whose name matches a directory
            // building key still missing a real tour (empty or legacy
            // /tour?campusId= stubs) gets the building's first room tour.
            foreach (Place::query()->get() as $place) {
                $tour = $this->tourForPlaceName($place->name, $tourByBuilding);
                if ($tour === null || ! $this->tourUrlNeedsUpdate($place->tour_url)) {
                    continue;
                }
                $place->update(['tour_url' => $tour]);
                $placesUpdated++;
            }

            // Backfill tour URLs onto curated service-linked directory rows
            // (seeded with related_service_id) from the building's 360 link.
            $serviceLinksUpdated = 0;
            foreach (DirectoryEntry::query()
                ->whereNotNull('related_service_id')
                ->where('related_service_id', '!=', '')
                ->get() as $entry) {
                $tour = $tourByBuilding[$this->key($entry->building)] ?? null;
                if ($tour === null) {
                    continue;
                }
                if ($entry->tour_url === $tour) {
                    continue;
                }
                $entry->update(['tour_url' => $tour]);
                $serviceLinksUpdated++;
            }

            return [
                'generatedAt' => $payload['generatedAt'] ?? null,
                'campuses' => count($payload['campuses'] ?? []),
                'buildings' => count($payload['buildings'] ?? []),
                'roomsSynced' => $roomsSynced,
                'roomsWithTours' => $roomsWithTours,
                'roomsRemoved' => $roomsRemoved,
                'placesUpdated' => $placesUpdated,
                'serviceLinksUpdated' => $serviceLinksUpdated,
                // Source audit: coords remain null upstream; this count
                // makes that limitation observable, not hidden.
                'coordinatesReceived' => $this->coordinatesReceived($payload),
            ];
        });
    }

    private function fetchPayload(string $baseUrl, string $endpoint, string $apiKey): array
    {
        try {
            $response = Http::acceptJson()
                ->withToken($apiKey)
                ->timeout(20)
                ->retry(2, 250, throw: false)
                ->withOptions(['verify' => (bool) config('services.campus_directory.verify_ssl', true)])
                ->get($baseUrl.'/api/integration/'.$endpoint);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Could not reach the ARUCAD 360 {$endpoint} endpoint.", previous: $e);
        }
        if (! $response->successful()) {
            throw new RuntimeException("ARUCAD 360 {$endpoint} returned HTTP {$response->status()}.");
        }
        $payload = $response->json();
        if (! is_array($payload) || ! is_array($payload['rooms'] ?? null)) {
            throw new RuntimeException("ARUCAD 360 {$endpoint} response has no rooms array.");
        }

        return $payload;
    }

    /** Merge collections by authoritative upstream id; locations wins fields. */
    private function mergePayloads(array $directory, array $locations): array
    {
        $merged = $directory;
        foreach (['campuses', 'buildings', 'categories', 'rooms', 'people'] as $collection) {
            $byId = [];
            foreach ($directory[$collection] ?? [] as $item) {
                if (is_array($item) && is_scalar($item['id'] ?? null)) {
                    $byId[(string) $item['id']] = $item;
                }
            }
            foreach ($locations[$collection] ?? [] as $item) {
                if (! is_array($item) || ! is_scalar($item['id'] ?? null)) {
                    continue;
                }
                $id = (string) $item['id'];
                $byId[$id] = array_replace_recursive($byId[$id] ?? [], $item);
            }
            $merged[$collection] = array_values($byId);
        }
        $merged['generatedAt'] = $locations['generatedAt'] ?? $directory['generatedAt'] ?? null;

        return $merged;
    }

    private function localized(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (! is_array($value)) {
            return '';
        }

        foreach (['tr', 'en', 'ru'] as $locale) {
            if (is_string($value[$locale] ?? null) && trim($value[$locale]) !== '') {
                return trim($value[$locale]);
            }
        }

        return '';
    }

    /**
     * Resolve a relative or absolute tour URL while preserving both the
     * query string (?media-name=…) and the fragment (#media-name=…).
     * 3DVista deep-links need the fragment; FILTER_VALIDATE_URL alone is
     * unreliable across PHP builds for fragment-bearing URLs.
     */
    public function absoluteTourUrl(mixed $url, string $baseUrl): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }
        $url = trim($url);
        if (str_starts_with($url, '/')) {
            return $baseUrl.$url;
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        return null;
    }

    /** Case-fold + fold Turkish letters so BANDABULİYA / Bandabuliya match. */
    private function key(string $value): string
    {
        $lower = mb_strtolower(trim($value));

        return strtr($lower, [
            'ı' => 'i',
            'İ' => 'i',
            'i̇' => 'i',
            'ü' => 'u',
            'ö' => 'o',
            'ş' => 's',
            'ğ' => 'g',
            'ç' => 'c',
            'â' => 'a',
            'î' => 'i',
            'û' => 'u',
        ]);
    }

    /**
     * Directory building label → curated Place.name (lowercased).
     * Explicit aliases first; then fuzzy contains either way.
     *
     * @return array<string, string>
     */
    private function buildingAliases(): array
    {
        return [
            'iris' => 'iris (atelier building)',
            'age of bronze' => 'age of bronze',
            'bandabuliya kampus' => 'nicosia bandabuliya campus',
            'bandabuliya' => 'nicosia bandabuliya campus',
            'rodin' => 'rodin',
            'falling man' => 'falling man',
            'titan' => 'titan',
            'eve' => 'eve',
            'daniede' => 'daniele',
            'daniele' => 'daniele',
            'eternal spring' => 'eternal spring',
            'meditation' => 'meditation',
            'minotaur' => 'minotaur',
            'eternal idol' => 'eternal idol',
            'the kiss' => 'the kiss',
            'the garden' => 'the garden',
            'carpentry studio' => 'carpentry studio',
        ];
    }

    /**
     * Curated Place → the directory building whose tour it should borrow.
     *
     * Some catalogue pins are public-facing names for a space inside a
     * building the directory knows under a different label ("Art Rooms" are
     * the studios in AGE OF BRONZE; the Rodin gallery sits in RODIN). Left
     * unmatched they fall back to the campus entrance panorama, which is
     * the wrong room — the whole complaint. Mapping them explicitly is
     * honest: it is a real editorial decision about which space each pin
     * means, not a guess the fuzzy matcher can safely make.
     *
     * @return array<string, string>
     */
    private function placeToBuildingFallback(): array
    {
        return [
            'arucad art space' => 'age of bronze',
            'art rooms' => 'age of bronze',
            'arucad workshops' => 'iris',
            'arucad workshop' => 'iris',
            'carpentry studio' => 'iris',
            'arkin rodin collection gallery' => 'rodin',
            'ana kampus girisi' => 'rodin',
            'arucad dormitory' => 'arucad dormitory',
        ];
    }

    /**
     * Every Place that corresponds to this directory building.
     *
     * Returns a list rather than the first hit: the catalogue genuinely has
     * more than one row for the same real building (a curated pin plus a
     * legacy `place-*` seed, e.g. two "Carpentry Studio" rows). Binding the
     * tour to only the first left the duplicate pointing at the generic
     * campus entrance, which is exactly the "360 opens the wrong place"
     * symptom — and which of the two a student tapped was pure luck.
     *
     * @return list<Place>
     */
    private function placesForDirectoryBuilding(string $name): array
    {
        // The 360 source uses official building labels; curated Places keep
        // a few longer/public-facing names. Explicit aliases are preferred
        // over fuzzy matching so artwork-named buildings land on the right pin.
        $aliases = $this->buildingAliases();
        $key = $this->key($name);
        $target = $aliases[$key] ?? $key;

        $exact = Place::query()
            ->whereRaw('lower(name) = ?', [$target])
            ->orWhereRaw('lower(name) = ?', [$key])
            ->get()
            ->all();
        if ($exact !== []) {
            return $exact;
        }

        // Fuzzy: place name contains the building key (or vice versa).
        $matches = [];
        foreach (Place::query()->get() as $place) {
            $placeKey = $this->key($place->name);
            if ($placeKey === '' || $key === '') {
                continue;
            }
            if (str_contains($placeKey, $key) || str_contains($key, $placeKey)) {
                $matches[] = $place;

                continue;
            }
            if ($target !== $key && (str_contains($placeKey, $target) || str_contains($target, $placeKey))) {
                $matches[] = $place;
            }
        }

        return $matches;
    }

    /**
     * @param  array<string, string>  $tourByBuilding
     */
    private function tourForPlaceName(string $placeName, array $tourByBuilding): ?string
    {
        if ($tourByBuilding === []) {
            return null;
        }
        $placeKey = $this->key($placeName);
        $aliases = $this->buildingAliases();

        // Prefer exact alias / key hits before fuzzy contains.
        foreach ($tourByBuilding as $buildingKey => $tourUrl) {
            $aliasTarget = $aliases[$buildingKey] ?? $buildingKey;
            if ($placeKey === $buildingKey || $placeKey === $aliasTarget) {
                return $tourUrl;
            }
        }

        // Editorial mapping for pins the directory does not name directly.
        $fallbackBuilding = $this->placeToBuildingFallback()[$placeKey] ?? null;
        if ($fallbackBuilding !== null && isset($tourByBuilding[$fallbackBuilding])) {
            return $tourByBuilding[$fallbackBuilding];
        }

        foreach ($tourByBuilding as $buildingKey => $tourUrl) {
            $aliasTarget = $aliases[$buildingKey] ?? $buildingKey;
            if ($buildingKey !== '' && (str_contains($placeKey, $buildingKey) || str_contains($buildingKey, $placeKey))) {
                return $tourUrl;
            }
            if ($aliasTarget !== $buildingKey
                && (str_contains($placeKey, $aliasTarget) || str_contains($aliasTarget, $placeKey))) {
                return $tourUrl;
            }
        }

        return null;
    }

    /**
     * Whether a Place's current tour link should be replaced.
     *
     * Empty and legacy `/tour?campusId=` stubs obviously qualify. So does a
     * bare tour URL with no deep link: `…/Main/index.htm` opens the campus
     * entrance panorama no matter which pin was tapped, so "360 tour" on the
     * student office showed the front gate. A URL that already carries a
     * `media-name` / `media-index` deep link is left alone — it is aimed at
     * a specific panorama and is the best answer we have.
     */
    private function tourUrlNeedsUpdate(?string $current): bool
    {
        if ($current === null || trim($current) === '') {
            return true;
        }
        if (str_contains($current, '/tour?campusId=')) {
            return true;
        }

        return ! $this->isDeepLink($current);
    }

    /** A tour URL aimed at one panorama rather than a tour's default view. */
    private function isDeepLink(string $url): bool
    {
        return str_contains($url, 'media-name=') || str_contains($url, 'media-index=');
    }

    /** @return array{lat: float, lng: float}|null */
    private function coordinates(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $lat = $value['lat'] ?? $value['latitude'] ?? $value[0] ?? null;
        $lng = $value['lng'] ?? $value['longitude'] ?? $value[1] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }
        $lat = (float) $lat;
        $lng = (float) $lng;

        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180
            ? ['lat' => $lat, 'lng' => $lng] : null;
    }

    private function coordinatesReceived(array $payload): int
    {
        $items = array_merge($payload['campuses'] ?? [], $payload['buildings'] ?? [], $payload['rooms'] ?? []);

        return count(array_filter($items, function ($item): bool {
            if (! is_array($item)) {
                return false;
            }

            return $this->coordinates(
                $item['coordinates'] ?? $item['location'] ?? data_get($item, 'navigation.marker'),
            ) !== null;
        }));
    }
}
