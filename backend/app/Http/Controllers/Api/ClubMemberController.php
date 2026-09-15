<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\ClubMember;
use App\Models\ParticipationApplication;
use App\Services\AchievementEvaluator;
use Illuminate\Http\JsonResponse;

// Real, shared club membership. Direct join is only allowed when the student
// already has an approved participation application (or is already a member).
// The student UI submits via /applications; admin approve creates membership.
class ClubMemberController extends Controller
{
    use ApiResponds;

    public function index(): JsonResponse
    {
        $me = $this->currentUser();

        // Restricted to clubs that still exist rather than plucking the
        // membership rows straight out. `clubs` is soft-deleted so the
        // panel can undo a mis-click, which means the membership row
        // outlives a deleted club — without this the student's own list
        // still names it and the app renders a club it cannot open.
        $ids = ClubMember::where('user_id', $me->id)
            ->whereIn('club_id', Club::query()->select('id'))
            ->pluck('club_id');

        return $this->ok($ids);
    }

    public function join(string $id): JsonResponse
    {
        $me = $this->currentUser();

        if (! Club::where('id', $id)->exists()) {
            return $this->fail(404, 'CLUB_NOT_FOUND', 'Club not found.');
        }

        $existing = ClubMember::where('user_id', $me->id)->where('club_id', $id)->first();
        if ($existing) {
            return $this->ok(['joined' => true]);
        }

        $approved = ParticipationApplication::where('user_id', $me->id)
            ->whereIn('target_type', ['club', 'community'])
            ->where('target_id', $id)
            ->where('status', 'approved')
            ->exists();
        if (! $approved) {
            return $this->fail(
                403,
                'APPLICATION_REQUIRED',
                'Club membership requires an approved application. Submit via /applications first.',
            );
        }

        ClubMember::create([
            'user_id' => $me->id,
            'club_id' => $id,
            'created_at' => now(),
        ]);

        AchievementEvaluator::evaluate($me);

        return $this->ok(['joined' => true]);
    }

    public function leave(string $id): JsonResponse
    {
        $me = $this->currentUser();

        ClubMember::where('user_id', $me->id)->where('club_id', $id)->delete();

        return $this->ok(['joined' => false]);
    }
}
