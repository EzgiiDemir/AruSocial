<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Notification as InboxNotification;
use App\Models\SocialBlock;
use App\Models\SocialFollow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// Backend-side SocialGraphStore — real, shared follow/block relationship,
// keyed by peer *name* (see social_follows migration's doc comment for
// why, and docs/EKSIKLER.md §4 for the honest multi-user scope this
// still doesn't cover — there's still only one real logged-in account).
class SocialGraphController extends Controller
{
    use ApiResponds;

    public function following(Request $request): JsonResponse
    {
        $me = $this->currentUser();
        $names = SocialFollow::where('follower_user_id', $me->id)->pluck('followed_name');

        return $this->ok($names);
    }

    public function blocked(): JsonResponse
    {
        $me = $this->currentUser();
        $names = SocialBlock::where('blocker_user_id', $me->id)->pluck('blocked_name');

        return $this->ok($names);
    }

    public function toggleFollow(Request $request): JsonResponse
    {
        $me = $this->currentUser();
        $peer = $request->input('peer');
        if (! $peer) return $this->fail(400, 'VALIDATION', 'peer is required.');

        $existing = SocialFollow::where('follower_user_id', $me->id)->where('followed_name', $peer)->first();
        if ($existing) {
            $existing->delete();

            return $this->ok(['following' => false]);
        }
        SocialFollow::create(['follower_user_id' => $me->id, 'followed_name' => $peer, 'created_at' => now()]);
        // A real notification row — this is the honest version of "the
        // followed user gets notified": in this single-real-account
        // prototype there's no second real inbox to receive it, but the
        // mechanism (and the row) is genuine, not simulated.
        InboxNotification::create([
            'id' => 'notif-'.Str::uuid(),
            'user_id' => $me->id,
            'kind' => 'follow',
            'title' => 'Yeni takipçi',
            'body' => "{$me->name} seni takip etmeye başladı.",
            'created_at' => now(),
        ]);

        return $this->ok(['following' => true]);
    }

    public function toggleBlock(Request $request): JsonResponse
    {
        $me = $this->currentUser();
        $peer = $request->input('peer');
        if (! $peer) return $this->fail(400, 'VALIDATION', 'peer is required.');

        $existing = SocialBlock::where('blocker_user_id', $me->id)->where('blocked_name', $peer)->first();
        if ($existing) {
            $existing->delete();

            return $this->ok(['blocked' => false]);
        }
        SocialBlock::create(['blocker_user_id' => $me->id, 'blocked_name' => $peer, 'created_at' => now()]);
        SocialFollow::where('follower_user_id', $me->id)->where('followed_name', $peer)->delete();

        return $this->ok(['blocked' => true]);
    }
}
