<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\RoutingDirectionsRequest;
use App\Http\Requests\RoutingMatchRequest;
use App\Services\RoutingService;
use Illuminate\Http\JsonResponse;

class RoutingController extends Controller
{
    use ApiResponds;

    public function directions(RoutingDirectionsRequest $request): JsonResponse
    {
        $fromLat = (float) $request->input('fromLat');
        $fromLng = (float) $request->input('fromLng');
        $toLat = (float) $request->input('toLat');
        $toLng = (float) $request->input('toLng');
        $mode = (string) $request->input('mode', 'walking');

        // Per mode, not globally: a deployment can have a pedestrian graph
        // and no vehicle one (or the reverse), and answering "not
        // configured" for the mode that actually has nothing to route on
        // is more use than a blanket yes or no.
        if (! RoutingService::isConfiguredFor($mode)) {
            // Name the variable that is actually missing. Walking and
            // vehicles run on separate OSRM graphs, so "routing is not
            // configured" on a deployment that plainly has walking
            // routes working sent people to the wrong setting.
            $envKey = $mode === 'walking' ? 'ROUTING_BASE_URL' : 'ROUTING_DRIVING_BASE_URL';

            return $this->fail(
                501,
                'ROUTING_NOT_CONFIGURED',
                "{$envKey} is not set — configure an OSRM-compatible routing base URL on the backend.",
                ['mode' => $mode, 'missing' => $envKey],
            );
        }

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

    public function match(RoutingMatchRequest $request): JsonResponse
    {
        $mode = (string) $request->input('mode', 'walking');
        if (! RoutingService::isConfiguredFor($mode)) {
            return $this->fail(501, 'ROUTING_NOT_CONFIGURED', 'Map matching is not configured for this mode.');
        }

        $matched = RoutingService::match($request->validated('samples'), $mode);
        if ($matched === null) {
            return $this->fail(502, 'MAP_MATCHING_UNAVAILABLE', 'The GPS trace could not be matched to the road network.');
        }

        return $this->ok($matched);
    }
}
