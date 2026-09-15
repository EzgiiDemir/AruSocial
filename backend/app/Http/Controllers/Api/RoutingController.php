<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\RoutingDirectionsRequest;
use App\Services\RoutingService;
use Illuminate\Http\JsonResponse;

class RoutingController extends Controller
{
    use ApiResponds;

    public function directions(RoutingDirectionsRequest $request): JsonResponse
    {
        if (! RoutingService::isConfigured()) {
            return $this->fail(
                501,
                'ROUTING_NOT_CONFIGURED',
                'ROUTING_BASE_URL is not set — configure an OSRM-compatible routing base URL on the backend.'
            );
        }

        $fromLat = (float) $request->input('fromLat');
        $fromLng = (float) $request->input('fromLng');
        $toLat = (float) $request->input('toLat');
        $toLng = (float) $request->input('toLng');
        $mode = (string) $request->input('mode', 'walking');

        if (abs($fromLat) > 90 || abs($toLat) > 90 || abs($fromLng) > 180 || abs($toLng) > 180) {
            return $this->fail(400, 'INVALID_COORDINATE', 'Coordinates are out of range.');
        }

        $route = RoutingService::route($fromLat, $fromLng, $toLat, $toLng, $mode);
        if ($route === null) {
            return $this->fail(502, 'ROUTING_UNAVAILABLE', 'Routing provider returned no route.');
        }

        return $this->ok([
            'points' => $route['points'],
            'distanceMeters' => $route['distanceMeters'],
            'durationSeconds' => $route['durationSeconds'],
            'steps' => $route['steps'],
            'provider' => 'osrm',
            'mode' => $mode,
        ]);
    }
}
