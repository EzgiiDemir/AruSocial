<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// OSRM-compatible walking directions. Configured via ROUTING_BASE_URL
// (e.g. a self-hosted OSRM instance). No key is ever sent to Flutter.
// When unset, callers get null and must show an honest fallback — never
// invent a road route from a straight line.
class RoutingService
{
    private const PUBLIC_OSRM_HOST = 'router.project-osrm.org';

    private const FOSSGIS_FOOT_BASE = 'https://routing.openstreetmap.de/routed-foot';

    public static function isConfigured(): bool
    {
        return (bool) config('services.routing.base_url');
    }

    /**
     * @return array{points: list<array{lat: float, lng: float}>, distanceMeters: float, durationSeconds: float, steps: list<array{instruction: string, distanceMeters: float, durationSeconds: float}>}|null
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
    ): ?array
    {
        $mode = in_array($mode, ['walking', 'driving', 'transit'], true)
            ? $mode : 'walking';
        $configured = rtrim((string) config('services.routing.base_url'), '/');
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
     * @return array{points: list<array{lat: float, lng: float}>, distanceMeters: float, durationSeconds: float, steps: list<array{instruction: string, distanceMeters: float, durationSeconds: float}>}|null
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

        Log::warning('routing.unavailable', ['lastError' => $lastError]);

        return null;
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
        $host = strtolower((string) parse_url($configured, PHP_URL_HOST));
        $isPublicOsrm = $host === self::PUBLIC_OSRM_HOST;
        $allowPublicFallback = $isPublicOsrm || config('app.env') === 'local';

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

        if ($isPublicOsrm) {
            $push($configured, 'driving');
        } else {
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
    private static function poolRequest(Pool $pool, bool $verifySsl): \Illuminate\Http\Client\PendingRequest
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
     * @param  mixed  $json
     * @return array{points: list<array{lat: float, lng: float}>, distanceMeters: float, durationSeconds: float, steps: list<array{instruction: string, distanceMeters: float, durationSeconds: float}>}|null
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
                $maneuver = $step['maneuver'] ?? [];
                $instruction = trim((string) ($maneuver['modifier'] ?? '').' '.(string) ($maneuver['type'] ?? ''));
                if ($instruction === '') {
                    $instruction = (string) ($step['name'] ?? 'continue');
                }
                $steps[] = [
                    'instruction' => $instruction,
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
