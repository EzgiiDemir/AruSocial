<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Quest;
use App\Models\XpTransaction;
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

    // Real, permanent XP ledger (docs/EKSIKLER.md harita/check-in/XP —
    // "xp_transactions mantığı") — every grant here traces back to the
    // real check-in/event-join row that caused it, not just a running
    // total with no explanation.
    public function xpTransactions(): JsonResponse
    {
        $u = $this->currentUser();
        $items = XpTransaction::where('user_id', $u->id)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(fn (XpTransaction $t) => [
                'id' => $t->id,
                'amount' => $t->amount,
                'reason' => $t->reason,
                'sourceType' => $t->source_type,
                'sourceId' => $t->source_id,
                'createdAt' => $t->created_at?->toIso8601String(),
            ]);

        return $this->ok($items);
    }
}
