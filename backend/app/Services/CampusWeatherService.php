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
 * When the provider is unreachable the caller gets null and the UI simply
 * omits the weather row — an invented temperature would be worse than none.
 */
class CampusWeatherService
{
    private const ENDPOINT = 'https://api.open-meteo.com/v1/forecast';

    /** ARUCAD Kyrenia campus. */
    private const CAMPUS_LAT = 35.337305;

    private const CAMPUS_LNG = 33.321303;

    private const CACHE_TTL_MINUTES = 15;

    /**
     * @return array{temperatureC: float, feelsLikeC: float, windKph: float, code: int, summary: string, isDay: bool}|null
     */
    public static function current(): ?array
    {
        return Cache::remember('campus.weather.current', now()->addMinutes(self::CACHE_TTL_MINUTES), function (): ?array {
            try {
                $response = Http::timeout(6)
                    ->connectTimeout(3)
                    ->withOptions(['verify' => (bool) config('services.campus_directory.verify_ssl', true)])
                    ->get(self::ENDPOINT, [
                        'latitude' => self::CAMPUS_LAT,
                        'longitude' => self::CAMPUS_LNG,
                        'current' => 'temperature_2m,apparent_temperature,weather_code,wind_speed_10m,is_day',
                        'timezone' => 'Europe/Istanbul',
                        'wind_speed_unit' => 'kmh',
                    ]);
            } catch (\Throwable $e) {
                Log::warning('weather.unreachable', ['message' => $e->getMessage()]);

                return null;
            }

            if (! $response->successful()) {
                Log::warning('weather.http', ['status' => $response->status()]);

                return null;
            }

            $current = $response->json('current');
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
