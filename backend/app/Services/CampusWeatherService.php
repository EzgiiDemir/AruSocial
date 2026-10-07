<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Current conditions over the Kyrenia campus, from Open-Meteo.
 *
 * Open-Meteo is used because it needs no API key and no account, so this is
 * a real live reading rather than a placeholder waiting on someone to buy a
 * weather plan. Responses are cached for 15 minutes: the campus is one
 * point on the map and every student opening the map would otherwise hit
 * the upstream service again for the same number.
 *
 * When the provider is unreachable the caller gets null and the UI keeps an
 * explicit unavailable state — an invented temperature would be worse.
 */
class CampusWeatherService
{
    private const ENDPOINT = 'https://api.open-meteo.com/v1/forecast';

    /** ARUCAD Kyrenia campus. */
    private const CAMPUS_LAT = 35.33745566534394;

    private const CAMPUS_LNG = 33.321512563639914;

    private const CACHE_TTL_MINUTES = 15;

    /**
     * @return array{temperatureC: float, feelsLikeC: float, windKph: float, code: int, summary: string, isDay: bool}|null
     */
    public static function current(): ?array
    {
        return Cache::remember('campus.weather.current', now()->addMinutes(self::CACHE_TTL_MINUTES), function (): ?array {
            $query = [
                'latitude' => self::CAMPUS_LAT,
                'longitude' => self::CAMPUS_LNG,
                'current' => 'temperature_2m,apparent_temperature,weather_code,wind_speed_10m,is_day',
                'timezone' => 'Europe/Nicosia',
                'wind_speed_unit' => 'kmh',
            ];
            $current = null;
            try {
                $response = Http::timeout(6)
                    ->connectTimeout(3)
                    ->withOptions(['verify' => (bool) config('services.campus_directory.verify_ssl', true)])
                    ->get(self::ENDPOINT, $query);
                if ($response->successful()) {
                    $current = $response->json('current');
                } else {
                    Log::warning('weather.http', ['status' => $response->status()]);
                }
            } catch (\Throwable $e) {
                Log::warning('weather.unreachable', ['message' => $e->getMessage()]);
                // Some managed Windows environments block PHP's libcurl
                // socket while PHP's native HTTPS stream remains available.
                // Retry the exact same allow-listed URL without changing
                // provider or fabricating a reading.
                if (str_contains($e->getMessage(), 'cURL error')) {
                    $current = self::streamCurrent($query);
                }
            }

            if (! is_array($current) || ! isset($current['temperature_2m'])) {
                return null;
            }

            $code = (int) ($current['weather_code'] ?? 0);

            return [
                'temperatureC' => round((float) $current['temperature_2m'], 1),
                'feelsLikeC' => round((float) ($current['apparent_temperature'] ?? $current['temperature_2m']), 1),
                'windKph' => round((float) ($current['wind_speed_10m'] ?? 0), 1),
                'code' => $code,
                'summary' => self::describe($code),
                'isDay' => (int) ($current['is_day'] ?? 1) === 1,
            ];
        });
    }

    /** @param array<string, scalar> $query */
    private static function streamCurrent(array $query): ?array
    {
        $verify = (bool) config('services.campus_directory.verify_ssl', true);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 6,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: ARUCAD-Campus-Prototype/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer' => $verify,
                'verify_peer_name' => $verify,
            ],
        ]);
        $body = @file_get_contents(self::ENDPOINT.'?'.http_build_query($query), false, $context);
        if (! is_string($body) || $body === '') {
            return null;
        }
        $json = json_decode($body, true);

        return is_array($json['current'] ?? null) ? $json['current'] : null;
    }

    /**
     * WMO weather codes → a short Turkish label. Grouped rather than
     * enumerated: students need "yağmurlu", not "moderate drizzle, freezing".
     */
    private static function describe(int $code): string
    {
        return match (true) {
            $code === 0 => 'Açık',
            $code <= 2 => 'Parçalı bulutlu',
            $code === 3 => 'Bulutlu',
            $code <= 48 => 'Sisli',
            $code <= 57 => 'Çiseleyen yağmur',
            $code <= 67 => 'Yağmurlu',
            $code <= 77 => 'Karlı',
            $code <= 82 => 'Sağanak yağış',
            $code <= 86 => 'Kar sağanağı',
            default => 'Gök gürültülü fırtına',
        };
    }
}
