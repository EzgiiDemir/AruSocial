<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\Checkin;
use App\Models\FeedPost;
use App\Models\Place;
use App\Services\ActivityLogger;
use App\Services\RealtimePublisher;
use App\Services\XpLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckinController extends Controller
{
    use ApiResponds;

    // Real, server-side Haversine distance — the client also measures this
    // itself (so it can prompt before even trying), but the server is what
    // actually decides, never a client-asserted "I'm close enough" flag
    // (docs/EKSIKLER.md §18/§35: "Check-in için sadece frontend'den gelen
    // koordinata güvenme").
    private function metersBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000; // meters
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    public function store(Request $request): JsonResponse
    {
        $place = Place::find($request->input('placeId'));
        if (! $place) {
            return $this->fail(404, 'PLACE_NOT_FOUND', 'Place not found.');
        }

        $lat = $request->input('lat');
        $lng = $request->input('lng');
        if ($lat === null || $lng === null) {
            return $this->fail(400, 'LOCATION_REQUIRED',
                'Check-in için konumunun alınması gerekiyor. Lütfen konum iznini kontrol et.');
        }

        $radiusMeters = (int) (AppSetting::getValue('checkin.radiusMeters') ?? 150);
        $meters = $this->metersBetween((float) $lat, (float) $lng, (float) $place->lat, (float) $place->lng);
        if ($meters > $radiusMeters) {
            $distanceLabel = $meters >= 1000
                ? number_format($meters / 1000, 1).' km'
                : round($meters).' m';

            return $this->fail(400, 'TOO_FAR',
                "Bu konuma yeterince yakın değilsiniz. Konuma yaklaşık {$distanceLabel} uzaktasınız.");
        }

        $visibleToOthers = $request->input('visibleToOthers') !== false;
        $me = $this->currentUser();

        $checkinId = $this->newId('checkin');
        Checkin::create([
            'id' => $checkinId,
            'place_id' => $place->id,
            'user_id' => $me->id,
            'visible_to_others' => $visibleToOthers,
            'created_at' => now(),
        ]);
        $checkinXp = (int) (AppSetting::getValue('xp.checkinAmount') ?? 10);
        $me->increment('places');
        XpLedger::grant($me, $checkinXp, "Check-in: {$place->name}", 'checkin', $checkinId);

        ActivityLogger::log(
            $me->id,
            'checkIn',
            "Check-in: {$place->name}",
            $visibleToOthers ? "+{$checkinXp} XP" : "+{$checkinXp} XP · gizli",
        );

        // A "visible" check-in must actually land in the shared feed —
        // the Flutter client's own confirmation message says "sosyal
        // akışa eklendi" (added to the social feed), so this needs to be
        // real, not just implied by the copy (matches what
        // MockCampusRepository.checkIn() already did for real).
        if ($visibleToOthers) {
            FeedPost::create([
                'id' => $this->newId('post'),
                'author_id' => (string) $me->id,
                'name' => $me->name,
                'text' => "{$place->name} konumunda check-in yaptı",
                'meta' => "az önce · +{$checkinXp} XP",
                'likes' => 0,
                'visibility' => 'everyone',
                'post_type' => 'normal',
                'official' => false,
                'created_at' => now(),
            ]);
        }

        RealtimePublisher::emit('checkin.created', 'all', 'checkin', $checkinId, (string) $me->id, [
            'placeId' => $place->id,
            'visibleToOthers' => $visibleToOthers,
        ]);
        if ($visibleToOthers) {
            RealtimePublisher::emit('post.created', 'all', 'post', null, (string) $me->id);
        }

        return $this->ok(['checkedIn' => true]);
    }
}
