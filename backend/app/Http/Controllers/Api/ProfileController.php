<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Quest;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    use ApiResponds;

    // Matches CampusUserDto.fromJson in lib/core/network/campus_dtos.dart.
    public function me(): JsonResponse
    {
        $u = $this->currentUser();

        return $this->ok([
            'id' => (string) $u->id,
            'name' => $u->name,
            'role' => $u->role,
            'level' => $u->level,
            'xp' => $u->xp,
            'places' => $u->places,
            'events' => $u->events,
            'memories' => $u->memories,
            'interests' => $u->interests ?? [],
            'avatarUrl' => $u->avatar_url,
        ]);
    }

    // Matches QuestDto.fromJson.
    public function quests(): JsonResponse
    {
        $u = $this->currentUser();
        $quests = Quest::where('user_id', $u->id)->get()->map(fn ($q) => [
            'id' => $q->id,
            'title' => $q->title,
            'subtitle' => $q->subtitle,
            'progress' => $q->progress,
            'target' => $q->target,
            'reward' => $q->reward,
        ]);

        return $this->ok($quests);
    }

    // Matches RestCampusRepository.getMyActivity()'s manual parse.
    public function activity(): JsonResponse
    {
        $u = $this->currentUser();
        $items = ActivityLog::where('user_id', $u->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'kind' => $a->kind,
                'title' => $a->title,
                'subtitle' => $a->subtitle,
                'meta' => $a->meta,
            ]);

        return $this->ok($items);
    }
}
