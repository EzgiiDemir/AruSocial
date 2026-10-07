<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// OSRM-compatible directions over two graphs: pedestrian (ROUTING_BASE_URL,
// an OSRM built with foot.lua) and vehicle (ROUTING_DRIVING_BASE_URL, one
// built with car.lua). Both self-hosted from the same Cyprus OSM extract —
// see deploy/osrm/. No key is ever sent to Flutter.
//
// When the graph a mode needs is unset, callers get null and must show an
// honest fallback — never invent a road route from a straight line, and
// never answer a car with a footpath.
class RoutingService
{
    private const PUBLIC_OSRM_HOST = 'router.project-osrm.org';

    private const FOSSGIS_FOOT_BASE = 'https://routing.openstreetmap.de/routed-foot';

    /** FOSSGIS runs one instance per profile, all served under /driving/. */
    private const FOSSGIS_HOST = 'routing.openstreetmap.de';

    public static function isConfigured(): bool
    {
        return self::baseFor('walking') !== '' || self::baseFor('driving') !== '';
    }

    /** Whether THIS mode has a graph to route on. */
    public static function isConfiguredFor(string $mode): bool
    {
        return self::baseFor($mode) !== '';
    }

    /**
     * Which host serves [$mode], for callers that need to probe it (the
     * admin panel's connection test). Empty when that mode has no graph.
     */
    public static function baseForMode(string $mode): string
    {
        return self::baseFor($mode);
    }

    /**
     * The routing host for [$mode].
     *
     * An OSRM instance serves exactly ONE profile, and it will answer
     * `/route/v1/driving/` from a pedestrian graph without complaint. So
     * the graph is chosen by host, never by the profile name in the path:
     * walking goes to the foot instance, car and bus to the car instance.
     *
     * A vehicle deliberately does NOT fall back to the pedestrian graph.
     * That fallback is precisely the bug this split exists to remove: it
     * returned a real-looking route down stairs and footpaths a car
     * cannot use, with nothing on screen saying so. With no vehicle graph
     * the mode is simply unconfigured, the API answers 501, and the app
     * draws its honest straight-line estimate instead.
     *
     * The one exception is the public OSRM demo host, which is car-only
     * by nature, so it is a legitimate vehicle graph when an operator has
     * pointed at it (or opted into it as a fallback).
     */
    private static function baseFor(string $mode): string
    {
        $walking = rtrim((string) config('services.routing.base_url'), '/');
        if ($mode === 'walking') {
            return $walking;
        }

        $driving = rtrim((string) config('services.routing.driving_base_url'), '/');
        if ($driving !== '') {
            return $driving;
        }

        if (self::isPublicOsrm($walking)) {
            return $walking;
        }

        return (bool) config('services.routing.allow_public_fallback', false)
            ? 'https://'.self::PUBLIC_OSRM_HOST
            : '';
    }

    private static function isPublicOsrm(string $base): bool
    {
        return strtolower((string) parse_url($base, PHP_URL_HOST)) === self::PUBLIC_OSRM_HOST;
    }

    /**
     * A host that serves exactly one profile, always under /driving/.
     *
     * Trying other profile names against it wastes a request and, on a
     * shared public service, is what turns a burst into a refused
     * connection.
     */
    private static function isSingleProfileHost(string $base): bool
    {
        return strtolower((string) parse_url($base, PHP_URL_HOST)) === self::FOSSGIS_HOST;
    }

    /**
     * @return array{points: list<array{lat: float, lng: float}>, distanceMeters: float, durationSeconds: float, steps: list<array{instruction: string, type: string, modifier: string, name: string, distanceMeters: float, durationSeconds: float}>}|null
     */
    public static function walkingRoute(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        return self::route($fromLat, $fromLng, $toLat, $toLng, 'walking');
    }

    public static function route(
        float $fromLat,
        float $fromLng,
        float $toLat,
        float $toLng,
        string $mode = 'walking',
    ): ?array {
        $mode = in_array($mode, ['walking', 'driving', 'transit'], true)
            ? $mode : 'walking';
        $configured = self::baseFor($mode);
        if ($configured === '') {
            return null;
        }

        // Campus geography is static — the same A→B walk never produces a
        // different real route from one request to the next, so a repeat
        // request (very common: the same handful of popular building
        // pairs) can be served instantly instead of re-paying the public
        // OSRM demo server's latency (and its sequential-fallback cost on
        // a slow/unreachable primary) every single time. Coordinates are
        // rounded to ~1m precision so trivial float jitter still hits the
        // same cache entry.
        $key = sprintf(
            'routing.%s.%s.%.5F.%.5F.%.5F.%.5F',
            $mode,
            $configured,
            $fromLat,
            $fromLng,
            $toLat,
            $toLng,
        );

        return Cache::remember($key, now()->addHours(6), fn () => self::fetchRoute(
            $fromLat, $fromLng, $toLat, $toLng, $configured, $mode,
        ));
    }

    /**
     * Snap a short, ordered GPS trace to the configured OSRM graph.
     *
     * @param  list<array{lat: numeric, lng: numeric, accuracy?: numeric|null, timestamp: int}>  $samples
     * @return array{lat: float, lng: float, confidence: float, points: list<array{lat: float, lng: float}>}|null
     */
    public static function match(array $samples, string $mode = 'walking'): ?array
    {
        $configured = self::baseFor($mode);
        if ($configured === '' || count($samples) < 2) {
            return null;
        }

        $coordinates = implode(';', array_map(
            fn (array $sample): string => sprintf('%.6F,%.6F', (float) $sample['lng'], (float) $sample['lat']),
            $samples,
        ));
        $timestamps = implode(';', array_map(fn (array $sample): int => (int) $sample['timestamp'], $samples));
        $radiuses = implode(';', array_map(
            fn (array $sample): string => (string) max(5, min(50, (float) ($sample['accuracy'] ?? 15))),
            $samples,
        ));
        $query = http_build_query([
            'geometries' => 'geojson',
            'overview' => 'full',
            'tidy' => 'true',
            'timestamps' => $timestamps,
            'radiuses' => $radiuses,
        ]);
        $verifySsl = (bool) config('services.routing.verify_ssl', true);

        foreach (self::endpointAttempts($configured, $mode) as [$base, $profile]) {
            $url = sprintf('%s/match/v1/%s/%s?%s', $base, $profile, $coordinates, $query);
            try {
                $request = Http::timeout(6)
                    ->connectTimeout(3)
                    ->withHeaders([
                        'User-Agent' => 'ARUCAD-Campus-Prototype/1.0 (map matching)',
                        'Accept' => 'application/json',
                    ]);
                if (! $verifySsl) {
                    $request = $request->withoutVerifying();
                }
                $response = $request->get($url);
                if (! $response->successful()) {
                    continue;
                }
                $json = $response->json();
                $tracepoints = is_array($json) ? ($json['tracepoints'] ?? []) : [];
                $last = null;
                foreach ($tracepoints as $tracepoint) {
                    if (is_array($tracepoint) && is_array($tracepoint['location'] ?? null)) {
                        $last = $tracepoint['location'];
                    }
                }
                $matching = is_array($json) ? ($json['matchings'][0] ?? null) : null;
                if (! is_array($last) || count($last) < 2 || ! is_array($matching)) {
                    continue;
                }
                $points = [];
                foreach ($matching['geometry']['coordinates'] ?? [] as $pair) {
                    if (is_array($pair) && count($pair) >= 2) {
                        $points[] = ['lat' => (float) $pair[1], 'lng' => (float) $pair[0]];
                    }
                }

                return [
                    'lat' => (float) $last[1],
                    'lng' => (float) $last[0],
                    'confidence' => (float) ($matching['confidence'] ?? 0),
                    'points' => $points,
                ];
            } catch (\Throwable $e) {
                Log::debug('routing.match_failed', ['message' => $e->getMessage()]);
            }
        }

        return null;
    }

    /**
     * @return array{points: list<array{lat: float, lng: float}>, distanceMeters: float, durationSeconds: float, steps: list<array{instruction: string, type: string, modifier: string, name: string, distanceMeters: float, durationSeconds: float}>}|null
     */
    private static function fetchRoute(float $fromLat, float $fromLng, float $toLat, float $toLng, string $configured, string $mode): ?array
    {
        $verifySsl = (bool) config('services.routing.verify_ssl', true);
        $attempts = self::endpointAttempts($configured, $mode);
        if ($attempts === []) {
            return null;
        }

        $query = http_build_query([
            'overview' => 'full',
            'geometries' => 'geojson',
            'steps' => 'true',
        ]);
        $urls = array_map(
            fn (array $attempt): string => sprintf(
                '%s/route/v1/%s/%.6F,%.6F;%.6F,%.6F?%s',
                $attempt[0],
                $attempt[1],
                $fromLng,
                $fromLat,
                $toLng,
                $toLat,
                $query,
            ),
            $attempts,
        );

        // Every candidate host is asked at once instead of one after
        // another — a slow/unreachable primary no longer makes the request
        // wait its full timeout before the fallback host even gets to
        // start. Http::pool() returns responses in the same order the
        // requests were submitted, so the existing priority (pedestrian
        // geometry first, then the configured host, then the public demo)
        // is preserved exactly — only the wall-clock cost of a dead/slow
        // attempt changes, from "sum of every attempt" to "the slowest one".
        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (string $url) => self::poolRequest($pool, $verifySsl)->get($url),
            $urls,
        ));

        $lastError = null;
        foreach ($attempts as $i => [$base, $profile]) {
            $response = $responses[$i] ?? null;

            if ($response instanceof \Throwable) {
                $lastError = $response->getMessage();
                Log::warning('routing.provider_exception', [
                    'base' => $base,
                    'profile' => $profile,
                    'message' => $lastError,
                ]);

                continue;
            }

            if (! $response instanceof Response || ! $response->successful()) {
                $lastError = 'HTTP '.($response instanceof Response ? $response->status() : 'null');
                Log::warning('routing.provider_http', [
                    'base' => $base,
                    'profile' => $profile,
                    'status' => $response instanceof Response ? $response->status() : null,
                    'body' => $response instanceof Response ? substr($response->body(), 0, 240) : null,
                ]);

                continue;
            }

            $parsed = self::parseOsrmRoute($response->json());
            if ($parsed !== null) {
                return $parsed;
            }

            $lastError = 'empty_or_invalid_geometry';
        }

        // Windows can permit PHP's native HTTPS stream while denying the
        // libcurl socket used by Guzzle (cURL error 7/60). Only retry for a
        // transport-level cURL failure: mocked/provider HTTP errors retain
        // their normal unavailable behavior and never escape to the network.
        if (is_string($lastError) && str_contains($lastError, 'cURL error')) {
            foreach ($urls as $url) {
                $json = self::streamJson($url, $verifySsl);
                $parsed = self::parseOsrmRoute($json);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        Log::warning('routing.unavailable', ['lastError' => $lastError]);

        return null;
    }

    /** @return array<string, mixed>|null */
    private static function streamJson(string $url, bool $verifySsl): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 6,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: ARUCAD-Campus-Prototype/1.0 (campus routing)\r\n",
            ],
            'ssl' => [
                'verify_peer' => $verifySsl,
                'verify_peer_name' => $verifySsl,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (! is_string($body) || $body === '') {
            return null;
        }
        $json = json_decode($body, true);

        return is_array($json) ? $json : null;
    }

    /**
     * Public OSRM demo is driving-only. FOSSGIS `/routed-foot` is walking
     * geometry exposed as OSRM `/route/v1/driving`. Local Windows often
     * fails TLS (cURL 60) or IPv6; those hosts are retried with verify off
     * and IPv4 when APP_ENV=local or the configured host is the public demo.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function endpointAttempts(string $configured, string $mode): array
    {
        $isPublicOsrm = self::isPublicOsrm($configured);
        // A public third-party OSRM server must never be REQUIRED. Falling back
        // to one is now opt-in (ROUTING_ALLOW_PUBLIC_FALLBACK, default false) —
        // except when the operator explicitly configured the public demo host
        // itself. So a self-hosted ARUCAD OSRM never silently reaches out to a
        // third party; if it is down, the caller gets an honest 501 instead.
        $allowPublicFallback = $isPublicOsrm
            || (bool) config('services.routing.allow_public_fallback', false);

        $out = [];
        $seen = [];
        $push = function (string $base, string $profile) use (&$out, &$seen): void {
            $key = $base.'|'.$profile;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $out[] = [$base, $profile];
        };

        if ($mode === 'walking' && $allowPublicFallback) {
            // Pedestrian geometry first — better campus footpaths than the
            // public OSRM demo's driving graph.
            $push(self::FOSSGIS_FOOT_BASE, 'driving');
        }

        if ($isPublicOsrm || self::isSingleProfileHost($configured)) {
            /*
             * One request, not three.
             *
             * These hosts run ONE profile per instance and serve it under
             * /route/v1/driving/ regardless — FOSSGIS's routed-foot answers
             * walking geometry at /driving/, and 400s at /foot/ and
             * /walking/. Asking for all three fired two guaranteed-useless
             * requests per route, concurrently, at a public service we are
             * asked to be gentle with. Measured effect: bursts came back as
             * "cURL error 7 ... after 0 ms" and every route 502'd, while the
             * same coordinates succeeded when tried on their own.
             */
            $push($configured, 'driving');
        } else {
            // These profile names are only alternative spellings a given
            // deployment might answer to; a single-profile OSRM ignores
            // the name in the path entirely. WHICH graph is reached is
            // decided by baseFor($mode), not by this. Vehicles ask once:
            // every OSRM answers `driving`, so a second spelling would
            // just double the outbound requests.
            $profiles = $mode === 'walking'
                ? ['foot', 'walking', 'driving']
                : ['driving'];
            foreach ($profiles as $profile) {
                $push($configured, $profile);
            }
        }

        if ($allowPublicFallback && ! $isPublicOsrm) {
            $push('https://'.self::PUBLIC_OSRM_HOST, 'driving');
        }

        return $out;
    }

    /**
     * A configured-but-not-yet-dispatched request builder from the given
     * pool. Every attempt shares the same short timeout budget — a real
     * self-hosted/reachable OSRM answers in well under a second, and since
     * every attempt now fires concurrently (see fetchWalkingRoute) this
     * timeout is what actually bounds a dead/slow host's contribution to
     * total wait time, not a sequential fallback chain.
     */
    private static function poolRequest(Pool $pool, bool $verifySsl): PendingRequest
    {
        $options = [
            'verify' => $verifySsl,
            'force_ip_resolve' => 'v4',
            'connect_timeout' => 3,
            'timeout' => 6,
        ];
        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            $options['curl'] = [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ];
            if (! $verifySsl && defined('CURLOPT_SSL_VERIFYPEER')) {
                $options['curl'][CURLOPT_SSL_VERIFYPEER] = 0;
                $options['curl'][CURLOPT_SSL_VERIFYHOST] = 0;
            }
        }

        $pending = $pool->timeout(6)
            ->connectTimeout(3)
            ->withHeaders([
                'User-Agent' => 'ARUCAD-Campus-Prototype/1.0 (campus routing)',
                'Accept' => 'application/json',
            ])
            ->withOptions($options);

        return $verifySsl ? $pending : $pending->withoutVerifying();
    }

    /**
     * @return array{points: list<array{lat: float, lng: float}>, distanceMeters: float, durationSeconds: float, steps: list<array{instruction: string, type: string, modifier: string, name: string, distanceMeters: float, durationSeconds: float}>}|null
     */
    private static function parseOsrmRoute(mixed $json): ?array
    {
        if (! is_array($json)) {
            return null;
        }

        $route = $json['routes'][0] ?? null;
        if (! is_array($route)) {
            return null;
        }

        $coords = $route['geometry']['coordinates'] ?? [];
        if (! is_array($coords) || count($coords) < 2) {
            return null;
        }

        $points = [];
        foreach ($coords as $pair) {
            if (! is_array($pair) || count($pair) < 2) {
                continue;
            }
            $points[] = ['lat' => (float) $pair[1], 'lng' => (float) $pair[0]];
        }
        if (count($points) < 2) {
            return null;
        }

        $steps = [];
        foreach ($route['legs'] ?? [] as $leg) {
            if (! is_array($leg)) {
                continue;
            }
            foreach ($leg['steps'] ?? [] as $step) {
                if (! is_array($step)) {
                    continue;
                }
                $maneuver = is_array($step['maneuver'] ?? null) ? $step['maneuver'] : [];
                $type = (string) ($maneuver['type'] ?? '');
                $modifier = (string) ($maneuver['modifier'] ?? '');
                $instruction = trim($modifier.' '.$type);
                if ($instruction === '') {
                    $instruction = (string) ($step['name'] ?? 'continue');
                }
                // `instruction` is the flattened provider text and stays
                // for backward compatibility. `type`/`modifier` are the
                // OSRM maneuver fields it was built from: the client can
                // only phrase "turn left" in the student's own language
                // if it gets the maneuver, not an English sentence to
                // pattern-match against.
                $steps[] = [
                    'instruction' => $instruction,
                    'type' => $type,
                    'modifier' => $modifier,
                    'name' => (string) ($step['name'] ?? ''),
                    'distanceMeters' => (float) ($step['distance'] ?? 0),
                    'durationSeconds' => (float) ($step['duration'] ?? 0),
                ];
            }
        }

        return [
            'points' => $points,
            'distanceMeters' => (float) ($route['distance'] ?? 0),
            'durationSeconds' => (float) ($route['duration'] ?? 0),
            'steps' => $steps,
        ];
    }
}
