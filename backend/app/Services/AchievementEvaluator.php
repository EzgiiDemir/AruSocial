<?php

namespace App\Services;

use App\Models\AchievementDefinition;
use App\Models\Checkin;
use App\Models\ClubMember;
use App\Models\EventJoin;
use App\Models\Review;
use App\Models\User;
use App\Models\UserAchievement;
use Illuminate\Database\UniqueConstraintViolationException;

// Server-side unlock only — never called from a client "claim" endpoint.
// Trigger kinds map to existing domain counters (check-in, event join,
// club membership, review). Duplicate unlocks are prevented by the
// unique(user_id, achievement_id) constraint + firstOrCreate.
class AchievementEvaluator
{
    public static function evaluate(User $user): void
    {
        // Real fix: this ran on every check-in — one round trip for total
        // checkins and one more for distinct places, both against the same
        // table for the same user. A single query with two aggregates does
        // the same job for the cost of one.
        $checkinAgg = Checkin::where('user_id', $user->id)
            ->selectRaw('count(*) as total, count(distinct place_id) as distinct_places')
            ->first();
        $counts = [
            'checkin_count' => (int) ($checkinAgg->total ?? 0),
            'distinct_checkins' => (int) ($checkinAgg->distinct_places ?? 0),
            'event_joins' => EventJoin::where('user_id', $user->id)->count(),
            'club_joins' => ClubMember::where('user_id', $user->id)->count(),
            'reviews' => Review::where('user_id', $user->id)->count(),
        ];

        foreach (AchievementDefinition::where('active', true)->orderBy('sort_order')->get() as $def) {
            $progress = $counts[$def->trigger_kind] ?? 0;
            if ($progress < (int) $def->threshold) {
                continue;
            }

            try {
                UserAchievement::firstOrCreate(
                    ['user_id' => $user->id, 'achievement_id' => $def->id],
                    ['unlocked_at' => now()],
                );
            } catch (UniqueConstraintViolationException) {
                // Concurrent unlock race — already unlocked.
            }
        }
    }
}
