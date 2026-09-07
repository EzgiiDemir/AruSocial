<?php

namespace App\Services;

use App\Models\Event;

// Shared by every path that can create/edit an event at a real place and
// time (student self-service, full admin, and the Trainer Panel) — a
// place can't be double-booked at the same date+time slot regardless of
// which of those three created the conflicting event.
class PlaceConflictChecker
{
    public static function find(
        ?string $placeId,
        ?string $eventDate,
        string $time,
        ?string $excludeEventId = null,
    ): ?Event {
        if (! $placeId || ! $eventDate || $time === '') {
            return null;
        }

        return Event::where('place_id', $placeId)
            ->whereDate('event_date', $eventDate)
            ->where('time', $time)
            ->whereNotIn('workflow_status', ['rejected'])
            ->when($excludeEventId, fn ($q) => $q->where('id', '!=', $excludeEventId))
            ->first();
    }
}
