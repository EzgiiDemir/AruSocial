<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePresencePingRequest;
use App\Services\LiveCrowd;
use Illuminate\Http\JsonResponse;

/**
 * The map's live crowd signal: where people actually are, rather than
 * where they chose to check in. See [LiveCrowd] for what is stored (a
 * resolved place id, never coordinates) and what is refused (ghost mode,
 * off-campus pings).
 */
class PresenceController extends Controller
{
    use ApiResponds;

    /** Record my current position as presence at the nearest place. */
    public function ping(StorePresencePingRequest $request): JsonResponse
    {
        $me = $this->currentUser();
        $place = LiveCrowd::record(
            $me,
            (float) $request->input('latitude'),
            (float) $request->input('longitude'),
        );

        return $this->ok([
            'placeId' => $place?->id,
            'placeName' => $place?->name,
            'sharing' => LiveCrowd::sharesLocation($me),
        ]);
    }

    /** Stop counting me immediately — ghost mode, or leaving the map. */
    public function forget(): JsonResponse
    {
        LiveCrowd::forget($this->currentUser());

        return $this->ok(['placeId' => null, 'sharing' => false]);
    }

    /**
     * Anonymous head counts for the busiest places right now. No names, no
     * timestamps, no coordinates of people — only place + how many.
     */
    public function live(): JsonResponse
    {
        return $this->ok([
            'places' => LiveCrowd::busiest(8),
            'total' => LiveCrowd::totalPresent(),
            'windowMinutes' => LiveCrowd::WINDOW_MINUTES,
        ]);
    }
}
