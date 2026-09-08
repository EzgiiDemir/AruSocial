<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Imports the university's published events into the app.
 *
 * arucad.edu.tr is WordPress, so this reads its REST API rather than
 * scraping the rendered /etkinlikler/ page — the markup changes whenever
 * the theme is touched, the JSON does not. Two sources are read: the
 * `etkinlikler` custom post type, and The Events Calendar's `tribe_events`
 * when that plugin has entries with real start/end dates.
 *
 * Imported rows are marked with the `arucad-web-` id prefix so a re-run
 * updates them in place and never touches events created inside the app.
 */
class ArucadEventsSync
{
    private const BASE = 'https://arucad.edu.tr';

    private const ID_PREFIX = 'arucad-web-';

    /** @return array{imported: int, skipped: int, source: string} */
    public static function sync(int $limit = 50): array
    {
        $imported = 0;
        $skipped = 0;

        foreach (self::fetchTribeEvents($limit) as $event) {
            self::store($event) ? $imported++ : $skipped++;
        }

        foreach (self::fetchPostType('etkinlikler', $limit) as $event) {
            self::store($event) ? $imported++ : $skipped++;
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'source' => self::BASE];
    }

    /**
     * The Events Calendar exposes real start/end times, so it is preferred
     * when present.
     *
     * @return list<array<string, mixed>>
     */
    private static function fetchTribeEvents(int $limit): array
    {
        $json = self::get('/wp-json/tribe/events/v1/events', ['per_page' => min($limit, 50)]);
        $events = $json['events'] ?? null;
        if (! is_array($events)) {
            return [];
        }

        $out = [];
        foreach ($events as $item) {
            if (! is_array($item)) {
                continue;
            }
            $start = self::parseDate($item['start_date'] ?? null);
            $out[] = [
                'sourceId' => 'tribe-'.($item['id'] ?? Str::uuid()),
                'title' => self::text($item['title'] ?? ''),
                'description' => self::text($item['description'] ?? ''),
                'date' => $start,
                'time' => $start?->format('H:i') ?? '',
                'placeName' => self::text($item['venue']['venue'] ?? ''),
                'organizer' => self::text($item['organizer'][0]['organizer'] ?? ''),
                'url' => $item['url'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * The plain custom post type carries no structured event date, so the
     * publish date is used and the body is kept as the description.
     *
     * @return list<array<string, mixed>>
     */
    private static function fetchPostType(string $type, int $limit): array
    {
        $json = self::get("/wp-json/wp/v2/{$type}", [
            'per_page' => min($limit, 100),
            '_fields' => 'id,link,date,title,excerpt,content',
        ]);
        if (! is_array($json)) {
            return [];
        }

        $out = [];
        foreach ($json as $item) {
            if (! is_array($item) || ! isset($item['id'])) {
                continue;
            }
            $date = self::parseDate($item['date'] ?? null);
            $out[] = [
                'sourceId' => $type.'-'.$item['id'],
                'title' => self::text($item['title']['rendered'] ?? ''),
                'description' => self::text($item['excerpt']['rendered'] ?? ($item['content']['rendered'] ?? '')),
                'date' => $date,
                'time' => $date?->format('H:i') ?? '',
                'placeName' => '',
                'organizer' => '',
                'url' => $item['link'] ?? null,
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $event */
    private static function store(array $event): bool
    {
        $title = trim((string) $event['title']);
        if ($title === '') {
            return false;
        }

        $description = Str::limit(trim((string) $event['description']), 900);
        if ($event['url']) {
            $description = trim($description."\n\n".$event['url']);
        }

        Event::updateOrCreate(['id' => self::ID_PREFIX.$event['sourceId']], [
            'title' => $title,
            'time' => $event['time'] ?: '',
            'event_date' => $event['date'],
            'place_name' => $event['placeName'] ?: 'ARUCAD',
            'category' => 'Üniversite',
            'attendees' => 0,
            'xp' => 0,
            'draft' => false,
            'audience' => 'Tümü',
            'organizer' => $event['organizer'] ?: 'ARUCAD',
            'description' => $description,
            // Must be 'published', not 'approved': Event::publiclyListed()
            // — the scope every student-facing query goes through — matches
            // only 'published'. Synced events sat in the database as
            // 'approved' and were invisible in the app, with nothing in the
            // admin queue to publish them either, because they never entered
            // the review flow. They are already public on arucad.edu.tr, so
            // mirroring them as published is also the correct state.
            'workflow_status' => 'published',
        ]);

        return true;
    }

    /** @param array<string, mixed> $query */
    private static function get(string $path, array $query): mixed
    {
        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withHeaders(['User-Agent' => 'ARUCAD-Campus-App/1.0 (event sync)'])
                // Same env-gated switch the other outbound ARUCAD calls use:
                // verification stays on by default and is only relaxed on
                // local Windows PHP builds whose cert store cannot complete
                // the chain for this host.
                ->withOptions(['verify' => (bool) config('services.campus_directory.verify_ssl', true)])
                ->get(self::BASE.$path, $query);
        } catch (\Throwable $e) {
            Log::warning('events.sync_unreachable', ['path' => $path, 'message' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('events.sync_http', ['path' => $path, 'status' => $response->status()]);

            return null;
        }

        return $response->json();
    }

    private static function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** WordPress returns rendered HTML with entities; students want text. */
    private static function text(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
