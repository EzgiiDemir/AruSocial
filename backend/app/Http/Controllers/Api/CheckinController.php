<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Checkin;
use App\Models\FeedPost;
use App\Models\Place;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckinController extends Controller
{
    use ApiResponds;

    public function store(Request $request): JsonResponse
    {
        $place = Place::find($request->input('placeId'));
        if (! $place) {
            return $this->fail(404, 'PLACE_NOT_FOUND', 'Place not found.');
        }
        $visibleToOthers = $request->input('visibleToOthers') !== false;
        $me = $this->currentUser();

        Checkin::create([
            'id' => $this->newId('checkin'),
            'place_id' => $place->id,
            'user_id' => $me->id,
            'visible_to_others' => $visibleToOthers,
            'created_at' => now(),
        ]);
        $me->increment('places');
        $me->increment('xp', 10);

        ActivityLogger::log(
            $me->id,
            'checkIn',
            "Check-in: {$place->name}",
            $visibleToOthers ? '+10 XP' : '+10 XP · gizli',
        );

        // A "visible" check-in must actually land in the shared feed —
        // the Flutter client's own confirmation message says "sosyal
        // akışa eklendi" (added to the social feed), so this needs to be
        // real, not just implied by the copy (matches what
        // MockCampusRepository.checkIn() already did for real).
        if ($visibleToOthers) {
            FeedPost::create([
                'id' => $this->newId('post'),
                'author_id' => $me->id,
                'name' => $me->name,
                'text' => "{$place->name} konumunda check-in yaptı",
                'meta' => 'az önce · +10 XP',
                'visibility' => 'everyone',
                'post_type' => 'normal',
                'official' => false,
                'created_at' => now(),
            ]);
        }

        return $this->ok(['checkedIn' => true]);
    }
}
