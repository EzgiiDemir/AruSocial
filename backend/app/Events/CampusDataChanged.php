<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A deliberately small invalidation signal for public campus data.
 *
 * It contains no event title, attendee data, or identity; clients refresh
 * the authoritative REST collection after receiving it. This keeps a newly
 * published trainer event visible to an already-open student Home screen
 * without attempting to treat a websocket payload as a source of truth.
 */
class CampusDataChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param list<string> $resources */
    public function __construct(
        public array $resources,
        public string $action,
        public string $id,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('campus.live')];
    }

    public function broadcastAs(): string
    {
        return 'campus.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'resources' => $this->resources,
            'action' => $this->action,
            'id' => $this->id,
        ];
    }
}
