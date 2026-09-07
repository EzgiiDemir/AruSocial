<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventJoin;
use App\Models\User;

// One place that turns "this join is now real attendance" into the
// side-effects every surface (student join form, admin yoklama, trainer
// yoklama, application approval) must share: attendees, XP, event count,
// activity log, achievements. Join itself only records intent — awarding
// here is what stops XP/attendee inflation on unapproved or draft events.
class EventAttendance
{
    public static function approve(EventJoin $join, Event $event, ?string $approvedBy = null): EventJoin
    {
        if ($join->approved_at) {
            return $join;
        }

        $join->update([
            'approved_at' => now(),
            'approved_by' => $approvedBy ?? $join->approved_by,
            'form_submitted_at' => $join->form_submitted_at ?? now(),
        ]);

        $event->increment('attendees');

        if ($join->user_id) {
            $user = User::find($join->user_id);
            if ($user) {
                $xp = (int) $event->xp;
                if ($xp > 0) {
                    $user->increment('xp', $xp);
                }
                $user->increment('events');
                ActivityLogger::log(
                    $user->id,
                    'eventJoin',
                    "Katılımın onaylandı: {$event->title}",
                    $xp > 0 ? "+{$xp} XP" : 'Yoklama alındı',
                );
                AchievementEvaluator::evaluate($user);
            }
        }

        return $join->fresh(['user', 'participationType']) ?? $join;
    }
}
