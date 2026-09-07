<?php

namespace Tests\Feature;

use App\Events\AppointmentChanged;
use App\Models\StaffAvailabilitySlot;
use App\Models\StaffProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AppointmentBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_broadcasts_appointment_changed(): void
    {
        Event::fake([AppointmentChanged::class]);
        $this->actingAsUser();
        StaffProfile::create([
            'id' => 'staff-bc',
            'name' => 'Advisor',
            'department' => 'Career',
            'title' => 'Advisor',
            'active' => true,
        ]);
        StaffAvailabilitySlot::create([
            'id' => 'slot-bc-1',
            'staff_profile_id' => 'staff-bc',
            'slot_date' => '2099-04-01',
            'start_time' => '10:00',
            'end_time' => '10:30',
            'is_blocked' => false,
        ]);

        $payload = $this->postJson('/api/v1/appointments', [
            'staffProfileId' => 'staff-bc',
            'date' => '2099-04-01',
            'startTime' => '10:00',
            'endTime' => '10:30',
            'subject' => 'Staj',
        ])->assertCreated()->json('data');

        Event::assertDispatched(AppointmentChanged::class, function (AppointmentChanged $event) use ($payload) {
            $on = collect($event->broadcastOn())->map->name->all();

            return $event->broadcastWith()['id'] === $payload['id']
                && $event->broadcastAs() === 'appointment.changed'
                && in_array('private-user.'.$event->appointment->student_user_id, $on, true)
                && in_array('private-ops.appointments', $on, true);
        });
    }
}
