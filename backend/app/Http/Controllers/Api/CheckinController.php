<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCheckinRequest;
use App\Models\Checkin;
use App\Models\FeedPost;
use App\Models\Place;
use App\Services\AchievementEvaluator;
use App\Services\ActivityLogger;
use App\Support\CampusGeofence;
use App\Support\SchemaColumnCache;
use Illuminate\Http\JsonResponse;

class CheckinController extends Controller
{
    use ApiResponds;

    private const XP = 10;

    public function store(StoreCheckinRequest $request): JsonResponse
    {
        $place = Place::find($request->input('placeId'));
        if (! $place) {
            return $this->fail(404, 'PLACE_NOT_FOUND', 'Place not found.');
        }
        if ($place->lat === null || $place->lng === null) {
            return $this->fail(422, 'PLACE_LOCATION_UNKNOWN', 'This place has no coordinates; check-in is unavailable.');
        }

        $lat = (float) $request->input('latitude');
        $lng = (float) $request->input('longitude');
        if (! CampusGeofence::contains($lat, $lng)) {
            return $this->fail(403, 'CHECKIN_OFF_CAMPUS', 'Check-in is only allowed on an ARUCAD campus.', [
                'geofence' => CampusGeofence::mode(),
                'allowedRadiusMeters' => CampusGeofence::radiusMeters(),
            ]);
        }
        $allowed = (float) config('services.checkin.radius_meters', 150);
        $distance = $this->distanceMeters($lat, $lng, (float) $place->lat, (float) $place->lng);
        if ($distance > $allowed) {
            return $this->fail(403, 'CHECKIN_TOO_FAR', 'You are too far from this place to check in.', [
                'distanceMeters' => round($distance, 1),
                'allowedRadiusMeters' => $allowed,
            ]);
        }

        $me = $this->currentUser();
        $cooldownMinutes = (int) config('services.checkin.cooldown_minutes', 30);
        $recent = Checkin::where('user_id', $me->id)
            ->where('place_id', $place->id)
            ->where('created_at', '>=', now()->subMinutes($cooldownMinutes))
            ->exists();
        if ($recent) {
            return $this->fail(409, 'ALREADY_CHECKED_IN', 'You already checked in at this place recently.');
        }

        $visibleToOthers = $request->boolean('visibleToOthers', true);

        Checkin::create([
            'id' => $this->newId('checkin'),
            'place_id' => $place->id,
            'user_id' => $me->id,
            'visible_to_others' => $visibleToOthers,
            'created_at' => now(),
        ]);
        $me->increment('places');
        $me->increment('xp', self::XP);

        ActivityLogger::log(
            $me->id,
            'checkIn',
            "Check-in: {$place->name}",
            $visibleToOthers ? '+'.self::XP.' XP' : '+'.self::XP.' XP · gizli',
        );

        if ($visibleToOthers) {
            $post = [
                'id' => $this->newId('post'),
                'author_id' => $me->id,
                'name' => $me->name,
                'text' => "{$place->name} konumunda check-in yaptı",
                'meta' => 'az önce · +'.self::XP.' XP',
                'visibility' => 'everyone',
                'post_type' => 'normal',
                'official' => false,
                'created_at' => now(),
            ];
            // Real fix for "check-in is slow": Schema::hasColumn() used to
            // re-query the database's own schema metadata on every single
            // check-in. SchemaColumnCache answers it once per worker process
            // instead, while still supporting a database that hasn't run
            // this migration yet (see LegacySchemaCompatTest).
            if (SchemaColumnCache::hasColumn('feed_posts', 'workflow_status')) {
                $post['workflow_status'] = 'published';
            }
            FeedPost::create($post);
        }

        AchievementEvaluator::evaluate($me);

        return $this->ok([
            'checkedIn' => true,
            'xpAwarded' => self::XP,
            'visibleToOthers' => $visibleToOthers,
            'distanceMeters' => round($distance, 1),
        ]);
    }

    private function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000.0;
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLam = deg2rad($lng2 - $lng1);
        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLam / 2) ** 2;

        return 2 * $earth * asin(min(1.0, sqrt($a)));
    }
}
