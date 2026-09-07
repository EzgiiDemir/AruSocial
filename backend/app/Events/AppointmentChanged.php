<?php

namespace App\Events;

use App\Models\Appointment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppointmentChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Appointment $appointment) {}

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('user.'.$this->appointment->student_user_id),
            new PrivateChannel('ops.appointments'),
        ];
        $staffUserId = $this->appointment->staff?->user_id;
        if ($staffUserId) {
            $channels[] = new PrivateChannel('user.'.$staffUserId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'appointment.changed';
    }

    public function broadcastWith(): array
    {
        return $this->appointment->toApiArray();
    }
}
