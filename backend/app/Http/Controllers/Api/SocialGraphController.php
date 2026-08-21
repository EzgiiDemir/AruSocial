<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Notification as InboxNotification;
use App\Models\SocialBlock;
use App\Models\SocialFollow;
use App\Models\User;
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
        // Real bug fix (docs/EKSIKLER.md sosyal §1/§9): this used to write
        // the notification to the *follower's own* inbox — the exact
        // wrong side (the notification "yeni takipçi" should reach the
        // person who got followed, not the person doing the following).
        // Resolved by name the same way ChatController resolves a real
        // peer account: if it's a real second user, they genuinely get
        // notified; if not, there's honestly no one real to notify.
        $followedUser = User::where('name', $peer)->first();
        if ($followedUser && $followedUser->id !== $me->id) {
            InboxNotification::create([
                'id' => 'notif-'.Str::uuid(),
                'user_id' => $followedUser->id,
                'kind' => 'follow',
                'title' => 'Yeni takipçi',
                'body' => "{$me->name} seni takip etmeye başladı.",
                'created_at' => now(),
            ]);
        }

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
