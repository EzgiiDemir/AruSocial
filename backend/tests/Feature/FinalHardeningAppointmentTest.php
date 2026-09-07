<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\StaffAvailabilitySlot;
use App\Models\StaffProfile;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinalHardeningAppointmentTest extends TestCase
{
    use RefreshDatabase;

    private function seedStaffWithSlot(string $date = '2099-01-15'): StaffProfile
    {
        $staff = StaffProfile::create([
            'id' => 'staff-final',
            'name' => 'Final Staff',
            'department' => 'Architecture',
            'title' => 'Advisor',
            'is_department_head' => false,
            'active' => true,
        ]);
        StaffAvailabilitySlot::create([
            'id' => 'slot-final-1',
            'staff_profile_id' => $staff->id,
            'slot_date' => $date,
            'start_time' => '10:00',
            'end_time' => '10:30',
            'is_blocked' => false,
        ]);

        return $staff;
    }

    public function test_slots_expose_status_fields(): void
    {
        $this->actingAsUser();
        $this->seedStaffWithSlot();

        $slots = $this->getJson('/api/v1/staff/staff-final/slots?date=2099-01-15')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($slots);
        $this->assertSame('available', $slots[0]['status']);
        $this->assertTrue($slots[0]['available']);
    }

    public function test_book_and_cancel_frees_slot(): void
    {
        $me = $this->actingAsUser();
        $this->seedStaffWithSlot();

        $booked = $this->postJson('/api/v1/appointments', [
            'staffProfileId' => 'staff-final',
            'date' => '2099-01-15',
            'startTime' => '10:00',
            'endTime' => '10:30',
            'subject' => 'Danışmanlık',
        ])->assertCreated()->json('data');

        $this->assertSame('pending', $booked['status']);

        $slots = $this->getJson('/api/v1/staff/staff-final/slots?date=2099-01-15')
            ->assertOk()->json('data');
        $this->assertSame('booked', $slots[0]['status']);

        $this->postJson("/api/v1/appointments/{$booked['id']}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $slotsAfter = $this->getJson('/api/v1/staff/staff-final/slots?date=2099-01-15')
            ->assertOk()->json('data');
        $this->assertSame('available', $slotsAfter[0]['status']);
        $this->assertDatabaseHas('appointments', [
            'id' => $booked['id'],
            'student_user_id' => $me->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_past_slot_cannot_be_booked(): void
    {
        $this->actingAsUser();
        $this->seedStaffWithSlot('2020-01-01');

        $this->postJson('/api/v1/appointments', [
            'staffProfileId' => 'staff-final',
            'date' => '2020-01-01',
            'startTime' => '10:00',
            'endTime' => '10:30',
            'subject' => 'Danışmanlık',
        ])->assertStatus(409)->assertJsonPath('error.code', 'PAST_SLOT');
    }

    public function test_double_book_rejected(): void
    {
        $this->actingAsUser();
        $this->seedStaffWithSlot();

        $this->postJson('/api/v1/appointments', [
            'staffProfileId' => 'staff-final',
            'date' => '2099-01-15',
            'startTime' => '10:00',
            'endTime' => '10:30',
            'subject' => 'Danışmanlık',
        ])->assertCreated();

        $other = \App\Models\User::create([
            'name' => 'Other',
            'email' => 'other-final@arucad.edu.tr',
            'password' => bcrypt('x'),
        ]);
        $this->actingAsUser($other);
        $this->postJson('/api/v1/appointments', [
            'staffProfileId' => 'staff-final',
            'date' => '2099-01-15',
            'startTime' => '10:00',
            'endTime' => '10:30',
            'subject' => 'Danışmanlık',
        ])->assertStatus(409)->assertJsonPath('error.code', 'SLOT_UNAVAILABLE');
    }

    public function test_student_cannot_cancel_another_students_appointment(): void
    {
        $this->actingAsUser();
        $this->seedStaffWithSlot();
        $booked = $this->postJson('/api/v1/appointments', [
            'staffProfileId' => 'staff-final',
            'date' => '2099-01-15',
            'startTime' => '10:00',
            'endTime' => '10:30',
            'subject' => 'Danışmanlık',
        ])->assertCreated()->json('data');

        $other = \App\Models\User::create([
            'name' => 'Other',
            'email' => 'other-cancel@arucad.edu.tr',
            'password' => bcrypt('x'),
        ]);
        $this->actingAsUser($other);
        $this->postJson("/api/v1/appointments/{$booked['id']}/cancel")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'APPOINTMENT_NOT_FOUND');
        $this->assertDatabaseHas('appointments', ['id' => $booked['id'], 'status' => 'pending']);
    }

    public function test_student_cannot_attach_another_students_application(): void
    {
        $owner = $this->actingAsUser();
        $this->seedStaffWithSlot();
        \App\Models\ParticipationApplication::create([
            'id' => 'app-foreign',
            'user_id' => $owner->id,
            'target_type' => 'club',
            'target_id' => 'club-x',
            'status' => \App\Models\ParticipationApplication::STATUS_DETAIL_FORM_PENDING,
            'submitted_at' => now(),
        ]);

        $other = \App\Models\User::create([
            'name' => 'Other',
            'email' => 'other-app@arucad.edu.tr',
            'password' => bcrypt('x'),
        ]);
        $this->actingAsUser($other);
        $this->postJson('/api/v1/appointments', [
            'staffProfileId' => 'staff-final',
            'date' => '2099-01-15',
            'startTime' => '10:00',
            'endTime' => '10:30',
            'subject' => 'Danışmanlık',
            'applicationId' => 'app-foreign',
        ])->assertStatus(404)->assertJsonPath('error.code', 'APPLICATION_NOT_FOUND');
    }

    public function test_admin_lists_booked_appointments_and_student_cannot(): void
    {
        $this->actingAsUser();
        $this->seedStaffWithSlot();
        $this->postJson('/api/v1/appointments', [
            'staffProfileId' => 'staff-final',
            'date' => '2099-01-15',
            'startTime' => '10:00',
            'endTime' => '10:30',
            'subject' => 'Danışmanlık',
        ])->assertCreated();

        $this->getJson('/api/v1/admin/appointments')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->actingAsRole();
        $rows = $this->getJson('/api/v1/admin/appointments')->assertOk()->json('data');
        $this->assertNotEmpty($rows);
        $this->assertSame('pending', $rows[0]['status']);
        $this->assertSame('staff-final', $rows[0]['staffProfileId']);
    }

    public function test_linked_staff_account_can_cancel_their_booking(): void
    {
        $staffUser = \App\Models\User::create([
            'name' => 'Advisor',
            'email' => 'advisor@arucad.edu.tr',
            'password' => bcrypt('x'),
        ]);
        $staff = $this->seedStaffWithSlot();
        $staff->update(['user_id' => $staffUser->id]);

        $this->actingAsUser();
        $booked = $this->postJson('/api/v1/appointments', [
            'staffProfileId' => 'staff-final',
            'date' => '2099-01-15',
            'startTime' => '10:00',
            'endTime' => '10:30',
            'subject' => 'Danışmanlık',
        ])->assertCreated()->json('data');

        $this->actingAsUser($staffUser);
        $this->postJson("/api/v1/appointments/{$booked['id']}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_empty_day_gets_default_office_hours_and_can_book(): void
    {
        $this->actingAsUser();
        StaffProfile::create([
            'id' => 'staff-open',
            'name' => 'Open Staff',
            'department' => 'Architecture',
            'title' => 'Advisor',
            'is_department_head' => false,
            'active' => true,
        ]);

        $slots = $this->getJson('/api/v1/staff/staff-open/slots?date=2099-06-01')
            ->assertOk()
            ->json('data');
        $this->assertGreaterThanOrEqual(8, count($slots));
        $available = collect($slots)->firstWhere('available', true);
        $this->assertNotNull($available);

        $this->postJson('/api/v1/appointments', [
            'staffProfileId' => 'staff-open',
            'date' => '2099-06-01',
            'startTime' => '10:00:00',
            'endTime' => '10:30:00',
            'subject' => 'Danışmanlık',
        ])->assertCreated()->assertJsonPath('data.status', 'pending');
    }

    public function test_booking_succeeds_when_reverb_is_unreachable(): void
    {
        $this->actingAsUser();
        $this->seedStaffWithSlot();
        $this->mock(BroadcastFactory::class, function ($mock) {
            $mock->shouldReceive('event')
                ->andThrow(new \RuntimeException('cURL error 7: Failed to connect to localhost:6001'));
        });

        $this->postJson('/api/v1/appointments', [
            'staffProfileId' => 'staff-final',
            'date' => '2099-01-15',
            'startTime' => '10:00',
            'endTime' => '10:30',
            'subject' => 'Danışmanlık',
        ])->assertCreated()->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseCount('appointments', 1);
    }
}
