<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Api\Concerns\ModeratesContent;
use App\Http\Controllers\Controller;
use App\Models\ModerationReport;
use App\Models\Notification as InboxNotification;
use App\Models\SocialBlock;
use App\Models\SocialFollow;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Follow/block graph is user_id → user_id. The client may still send a
// display name as `peer`; that string is resolved to a real User and then
// discarded. The rows themselves never store a name.
class SocialGraphController extends Controller
{
    use ApiResponds, ModeratesContent;

    public function following(Request $request): JsonResponse
    {
        $me = $this->currentUser();
        $names = SocialFollow::query()
            ->accepted()
            ->where('follower_user_id', $me->id)
            ->join('users', 'users.id', '=', 'social_follows.followed_user_id')
            ->orderBy('social_follows.created_at')
            ->pluck('users.name');

        return $this->ok($names);
    }

    public function followers(): JsonResponse
    {
        $me = $this->currentUser();
        $names = SocialFollow::query()
            ->accepted()
            ->where('followed_user_id', $me->id)
            ->join('users', 'users.id', '=', 'social_follows.follower_user_id')
            ->orderBy('social_follows.created_at')
            ->pluck('users.name');

        return $this->ok($names);
    }

    public function reportUser(Request $request): JsonResponse
    {
        $me = $this->currentUser();
        $target = $this->resolveTarget($request);
        if ($target instanceof JsonResponse) {
            return $target;
        }
        if ($target->id === $me->id) {
            return $this->fail(400, 'VALIDATION', 'You cannot report yourself.');
        }

        $reason = trim((string) $request->input('reason', ''));
        if ($reason !== '' && ($blocked = $this->moderationBlock(
            $me, $reason, 'report_reason', 'social.reportUser'
        ))) {
            return $blocked;
        }
        if ($reason === '') {
            return $this->fail(400, 'VALIDATION', 'reason is required.');
        }

        ActivityLogger::log($me->id, 'report', "Kullanıcıyı şikayet ettin: {$target->name}", $reason);

        ModerationReport::create([
            'id' => $this->newId('report'),
            'kind' => 'user',
            'target_id' => (string) $target->id,
            'target_label' => $target->name,
            'reason' => $reason,
            'reported_at' => now(),
        ]);

        return $this->ok(['reported' => true]);
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
            // Pending request cancel or accepted unfollow — same toggle-off.
            $existing->delete();

            return $this->ok(['following' => false]);
        }

        $status = $target->is_private_profile ? 'pending' : 'accepted';

        try {
            SocialFollow::create([
                'follower_user_id' => $me->id,
                'followed_user_id' => $target->id,
                'status' => $status,
            ]);
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'UNIQUE') && $e->getCode() !== '23000') {
                throw $e;
            }

            return $this->ok(['following' => true]);
        }

        if ($status === 'pending') {
            InboxNotification::notify(
                $target,
                $me,
                'follow_request',
                'Takip isteği',
                "{$me->name} takip isteği gönderdi.",
                ['actorName' => $me->name],
            );

            return $this->ok(['following' => false, 'requested' => true]);
        }

        InboxNotification::notify(
            $target,
            $me,
            'follow',
            'Yeni takipçi',
            "{$me->name} seni takip etmeye başladı.",
            ['actorName' => $me->name],
        );

        return $this->ok(['following' => true]);
    }

    public function followRequests(): JsonResponse
    {
        $me = $this->currentUser();
        $rows = SocialFollow::query()
            ->pending()
            ->where('followed_user_id', $me->id)
            ->with('follower')
            ->orderByDesc('created_at')
            ->get();

        return $this->ok($rows->map(fn (SocialFollow $f) => [
            'id' => (string) $f->follower->id,
            'name' => $f->follower->name,
            'avatarUrl' => $f->follower->avatar_url,
        ]));
    }

    public function acceptFollowRequest(Request $request): JsonResponse
    {
        $me = $this->currentUser();
        $actor = $this->resolveRequester($request);
        if ($actor instanceof JsonResponse) {
            return $actor;
        }

        $row = SocialFollow::query()
            ->pending()
            ->where('follower_user_id', $actor->id)
            ->where('followed_user_id', $me->id)
            ->first();
        if (! $row) {
            return $this->fail(404, 'FOLLOW_REQUEST_NOT_FOUND', 'Follow request not found.');
        }

        $row->status = 'accepted';
        $row->save();

        return $this->ok(['accepted' => true]);
    }

    public function declineFollowRequest(Request $request): JsonResponse
    {
        $me = $this->currentUser();
        $actor = $this->resolveRequester($request);
        if ($actor instanceof JsonResponse) {
            return $actor;
        }

        $deleted = SocialFollow::query()
            ->pending()
            ->where('follower_user_id', $actor->id)
            ->where('followed_user_id', $me->id)
            ->delete();
        if ($deleted === 0) {
            return $this->fail(404, 'FOLLOW_REQUEST_NOT_FOUND', 'Follow request not found.');
        }

        return $this->ok(['declined' => true]);
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

    public function friends(): JsonResponse
    {
        $me = $this->currentUser();
        $followingIds = SocialFollow::query()
            ->accepted()
            ->where('follower_user_id', $me->id)
            ->pluck('followed_user_id');

        $friendIds = SocialFollow::query()
            ->accepted()
            ->where('followed_user_id', $me->id)
            ->whereIn('follower_user_id', $followingIds)
            ->pluck('follower_user_id');

        $friends = User::query()
            ->whereIn('id', $friendIds)
            ->orderBy('name')
            ->get();

        return $this->ok($friends->map(fn (User $u) => [
            'id' => (string) $u->id,
            'name' => $u->name,
            'avatarUrl' => $u->avatar_url,
            'department' => $u->department,
        ]));
    }

    public function showUser(string $id): JsonResponse
    {
        $me = $this->currentUser();
        $user = User::query()->whereKey($id)->first()
            ?? User::query()->where('name', $id)->first();
        if (! $user) {
            return $this->fail(404, 'USER_NOT_FOUND', 'User not found.');
        }

        $following = $me->following()->where('users.id', $user->id)->exists();
        $followerCount = SocialFollow::query()->accepted()->where('followed_user_id', $user->id)->count();
        $followingCount = SocialFollow::query()->accepted()->where('follower_user_id', $user->id)->count();
        $allowed = \App\Services\PrivateProfileGate::allows($me, $user);
        if (! $allowed) {
            return $this->ok([
                'id' => (string) $user->id,
                'name' => $user->name,
                'isPrivateProfile' => true,
                'isLocked' => true,
                'following' => $following,
                'followerCount' => $followerCount,
                'followingCount' => $followingCount,
            ]);
        }

        $payload = $user->toApiArray();
        $payload['isLocked'] = false;
        $payload['following'] = $following;
        $payload['isFriend'] = \App\Services\PrivateProfileGate::isFriend($me, $user);
        $payload['followerCount'] = $followerCount;
        $payload['followingCount'] = $followingCount;

        return $this->ok($payload);
    }

    private function resolveTarget(Request $request): User|JsonResponse
    {
        $peerId = $request->input('peerId', $request->input('peer_id', $request->input('userId', $request->input('user_id'))));
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

    private function resolveRequester(Request $request): User|JsonResponse
    {
        $userId = $request->input('userId', $request->input('user_id'));
        if ($userId !== null && $userId !== '') {
            $user = User::query()->whereKey($userId)->first();

            return $user ?? $this->fail(404, 'USER_NOT_FOUND', 'User not found.');
        }

        $name = $request->input('name');
        if ($name === null || $name === '') {
            return $this->fail(400, 'VALIDATION', 'userId or name is required.');
        }

        $matches = User::query()->where('name', (string) $name)->get();
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if ($matches->isEmpty()) {
            return $this->fail(404, 'USER_NOT_FOUND', 'User not found.');
        }

        return $this->fail(400, 'VALIDATION', 'name is ambiguous.');
    }
}
