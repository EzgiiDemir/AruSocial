<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Notification as InboxNotification;
use App\Models\SocialBlock;
use App\Models\SocialFollow;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// Follow/block graph is user_id → user_id. The client may still send a
// display name as `peer`; that string is resolved to a real User and then
// discarded. The rows themselves never store a name.
class SocialGraphController extends Controller
{
    use ApiResponds;

    public function following(Request $request): JsonResponse
    {
        $me = $this->currentUser();
        $names = SocialFollow::query()
            ->where('follower_user_id', $me->id)
            ->join('users', 'users.id', '=', 'social_follows.followed_user_id')
            ->orderBy('social_follows.created_at')
            ->pluck('users.name');

        return $this->ok($names);
    }

    public function blocked(): JsonResponse
    {
        $me = $this->currentUser();
        $names = SocialBlock::query()
            ->where('blocker_user_id', $me->id)
            ->join('users', 'users.id', '=', 'social_blocks.blocked_user_id')
            ->orderBy('social_blocks.created_at')
            ->pluck('users.name');

        return $this->ok($names);
    }

    public function toggleFollow(Request $request): JsonResponse
    {
        $me = $this->currentUser();
        $target = $this->resolveTarget($request);
        if ($target instanceof JsonResponse) {
            return $target;
        }
        if ($target->id === $me->id) {
            return $this->fail(400, 'VALIDATION', 'You cannot follow yourself.');
        }

        $existing = SocialFollow::where('follower_user_id', $me->id)
            ->where('followed_user_id', $target->id)
            ->first();
        if ($existing) {
            $existing->delete();

            return $this->ok(['following' => false]);
        }

        try {
            SocialFollow::create([
                'follower_user_id' => $me->id,
                'followed_user_id' => $target->id,
            ]);
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'UNIQUE') && $e->getCode() !== '23000') {
                throw $e;
            }

            return $this->ok(['following' => true]);
        }

        InboxNotification::notify(
            $target,
            $me,
            'follow',
            'Yeni takipçi',
            "{$me->name} seni takip etmeye başladı.",
        );

        return $this->ok(['following' => true]);
    }

    public function toggleBlock(Request $request): JsonResponse
    {
        $me = $this->currentUser();
        $target = $this->resolveTarget($request);
        if ($target instanceof JsonResponse) {
            return $target;
        }
        if ($target->id === $me->id) {
            return $this->fail(400, 'VALIDATION', 'You cannot block yourself.');
        }

        $existing = SocialBlock::where('blocker_user_id', $me->id)
            ->where('blocked_user_id', $target->id)
            ->first();
        if ($existing) {
            $existing->delete();

            return $this->ok(['blocked' => false]);
        }

        try {
            SocialBlock::create([
                'blocker_user_id' => $me->id,
                'blocked_user_id' => $target->id,
            ]);
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'UNIQUE') && $e->getCode() !== '23000') {
                throw $e;
            }

            return $this->ok(['blocked' => true]);
        }

        SocialFollow::where('follower_user_id', $me->id)
            ->where('followed_user_id', $target->id)
            ->delete();

        return $this->ok(['blocked' => true]);
    }

    private function resolveTarget(Request $request): User|JsonResponse
    {
        $peerId = $request->input('peerId', $request->input('peer_id'));
        if ($peerId !== null && $peerId !== '') {
            $user = User::query()->whereKey($peerId)->first();

            return $user ?? $this->fail(404, 'USER_NOT_FOUND', 'Target user not found.');
        }

        $peer = $request->input('peer');
        if ($peer === null || $peer === '') {
            return $this->fail(400, 'VALIDATION', 'peer is required.');
        }

        $matches = User::query()->where('name', (string) $peer)->get();
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if ($matches->isEmpty()) {
            return $this->fail(404, 'USER_NOT_FOUND', 'Target user not found.');
        }

        return $this->fail(400, 'VALIDATION', 'peer is ambiguous.');
    }
}
