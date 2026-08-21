<?php

namespace App\Services;

use App\Models\RealtimeEvent;

// Durable, typed event bus (docs/EKSIKLER.md "Gerçek realtime / event-based
// mimari"). Every important mutation writes a real `realtime_events` row;
// clients poll `GET /realtime/events?after=` and apply those types. This is
// not a decorative in-memory list — the row survives process restarts, and
// a second device polling the same cursor sees the same events.
class RealtimePublisher
{
    public static function emit(
        string $type,
        string $audience = 'all',
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $actorId = null,
        array $payload = [],
    ): void {
        RealtimeEvent::create([
            'type' => $type,
            'audience' => $audience,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'actor_id' => $actorId,
            'payload' => $payload === [] ? null : $payload,
            'created_at' => now(),
        ]);
    }

    public static function toUser(
        string $userId,
        string $type,
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $actorId = null,
        array $payload = [],
    ): void {
        self::emit($type, $userId, $entityType, $entityId, $actorId, $payload);
    }
}
