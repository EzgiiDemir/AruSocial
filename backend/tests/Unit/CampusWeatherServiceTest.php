<?php

namespace Tests\Unit;

use App\Services\CampusWeatherService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CampusWeatherServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('campus.weather.current');
    }

    public function test_it_requests_live_weather_for_the_official_kyrenia_location(): void
    {
        Http::fake([
            'api.open-meteo.com/*' => Http::response([
                'current' => [
                    'temperature_2m' => 29.8,
                    'apparent_temperature' => 30.9,
                    'weather_code' => 1,
                    'wind_speed_10m' => 20.5,
                    'is_day' => 1,
                ],
            ]),
        ]);

        $weather = CampusWeatherService::current();

        $this->assertSame(29.8, $weather['temperatureC']);
        $this->assertSame('Parçalı bulutlu', $weather['summary']);
        Http::assertSent(function (Request $request): bool {
            $query = $request->data();

            return abs((float) $query['latitude'] - 35.33745566534394) < 0.000001
                && abs((float) $query['longitude'] - 33.321512563639914) < 0.000001
                && $query['timezone'] === 'Europe/Nicosia';
        });
    }

    public function test_it_returns_null_instead_of_inventing_weather_when_provider_fails(): void
    {
        Http::fake([
            'api.open-meteo.com/*' => Http::response([], 503),
        ]);

        $this->assertNull(CampusWeatherService::current());
    }
}
