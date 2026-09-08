<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Services\CampusWeatherService;
use Illuminate\Http\JsonResponse;

/**
 * Current campus weather for the map info panel and the home header.
 *
 * Served from the backend rather than called from Flutter directly so the
 * 15-minute cache is shared by every student instead of each device hitting
 * the upstream provider on its own.
 */
class WeatherController extends Controller
{
    use ApiResponds;

    public function current(): JsonResponse
    {
        $weather = CampusWeatherService::current();

        // A missing reading is a normal, non-error state: the client hides
        // the row rather than showing a fabricated temperature.
        return $this->ok(['weather' => $weather]);
    }
}
