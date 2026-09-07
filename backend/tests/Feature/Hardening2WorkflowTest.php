<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Club;
use App\Models\ClubMember;
use App\Models\ParticipationApplication;
use App\Models\StaffAvailabilitySlot;
use App\Models\StaffProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Hardening2WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function seedStaff(): StaffProfile
    {
        return StaffProfile::create([
            'id' => 'staff-1',
            'name' => 'Ayşe Öğretim',
            'faculty' => 'Fine Arts',
            'department' => 'Architecture',
            'title' => 'Department Head',
            'email' => null,
            'is_department_head' => true,
            'active' => true,
        ]);
    }

    public function test_staff_filters_department_head(): void
    {
        $this->actingAsUser();
        $this->seedStaff();
        StaffProfile::create([
            'id' => 'staff-2', 'name' => 'Other', 'department' => 'Music',
            'title' => 'Advisor', 'is_department_head' => false, 'active' => true,
        ]);

        $heads = $this->getJson('/api/v1/staff?departmentHeadOnly=1&department=Architecture')
            ->assertOk()->json('data');
        $this->assertCount(1, $heads);
        $this->assertTrue($heads[0]['isDepartmentHead']);
    }

    /** Stage 1 (Preview) + stage 2 (Detail, via the real token'd web form) — brings an application from creation to `under_review`, ready for a decision. */
    private function completeDetailForm(string $applicationId): void
    {
        $app = \App\Models\ParticipationApplication::find($applicationId);
        $this->post("/forms/application/{$app->detail_form_token}", [])->assertOk();
    }

    public function test_application_submit_approve_creates_club_membership(): void
    {
        $me = $this->actingAsUser();
        $staff = $this->seedStaff();
        Club::create([
            'id' => 'club-1', 'name' => 'Art Club', 'category' => 'Art',
            'description' => 'x', 'responsible_staff_id' => $staff->id,
        ]);

        $created = $this->postJson('/api/v1/applications', [
            'targetType' => 'club',
            'targetId' => 'club-1',
            'formPayload' => ['reason' => 'ilgi'],
        ])->assertCreated()->json('data');

        $this->assertSame('detail_form_pending', $created['status']);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $me->id, 'kind' => 'application_preview_submitted',
        ]);

        $this->completeDetailForm($created['id']);
        $this->assertDatabaseHas('participation_applications', [
            'id' => $created['id'], 'status' => 'under_review',
        ]);

        $this->actingAsRole('superAdmin');
        $this->postJson("/api/v1/admin/applications/{$created['id']}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertTrue(
            ClubMember::where('user_id', $me->id)->where('club_id', 'club-1')->exists()
        );
    }

    public function test_admin_can_request_a_revision_instead_of_approve_or_reject(): void
    {
        $me = $this->actingAsUser();
        $staff = $this->seedStaff();
        Club::create([
            'id' => 'club-1', 'name' => 'Art Club', 'category' => 'Art',
            'description' => 'x', 'responsible_staff_id' => $staff->id,
        ]);
        $created = $this->postJson('/api/v1/applications', [
            'targetType' => 'club', 'targetId' => 'club-1',
        ])->assertCreated()->json('data');
        $this->completeDetailForm($created['id']);

        $this->actingAsRole('superAdmin');
        $this->postJson("/api/v1/admin/applications/{$created['id']}/revise", [])
            ->assertStatus(422)->assertJsonPath('error.code', 'REVIEW_NOTE_REQUIRED');

        $this->postJson("/api/v1/admin/applications/{$created['id']}/revise", [
            'reviewNote' => 'Lütfen ilgi alanını daha detaylı yaz.',
        ])->assertOk()->assertJsonPath('data.status', 'revision_required');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $me->id, 'kind' => 'application_revision_requested',
        ]);

        // A revision-required application isn't a dead end: the SAME
        // detail_form_token stays valid, so the student resubmits the
        // detail form (not a brand-new Preview) to get back to review.
        $this->completeDetailForm($created['id']);
        $this->assertDatabaseHas('participation_applications', [
            'id' => $created['id'], 'status' => 'under_review',
        ]);
    }

    public function test_career_and_service_applications_resolve_a_real_responsible_staff(): void
    {
        StaffProfile::create([
            'id' => 'staff-career', 'name' => 'Career Office', 'department' => 'Career',
            'title' => 'Career Staff', 'is_department_head' => false, 'active' => true,
        ]);
        \App\Models\CareerOpportunity::create([
            'id' => 'career-1', 'title' => 'Internship', 'kind' => 'internship', 'organization' => 'x',
        ]);
        $this->actingAsUser();

        $created = $this->postJson('/api/v1/applications', [
            'targetType' => 'career', 'targetId' => 'career-1',
        ])->assertCreated()->json('data');

        $this->assertSame('staff-career', $created['responsibleStaffId']);
    }

    public function test_duplicate_pending_application_is_rejected(): void
    {
        $this->actingAsUser();
        Club::create(['id' => 'club-1', 'name' => 'Art', 'category' => 'Art', 'description' => 'x']);
        $this->postJson('/api/v1/applications', [
            'targetType' => 'club', 'targetId' => 'club-1',
        ])->assertCreated();
        $this->postJson('/api/v1/applications', [
            'targetType' => 'club', 'targetId' => 'club-1',
        ])->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_APPLIED');
    }

    public function test_appointment_booking_prevents_double_book(): void
    {
        $this->actingAsUser();
        $staff = $this->seedStaff();
        $date = now()->addDays(14)->toDateString();
        StaffAvailabilitySlot::create([
            'id' => 'slot-1',
            'staff_profile_id' => $staff->id,
            'slot_date' => $date,
            'start_time' => '10:00',
            'end_time' => '10:30',
            'is_blocked' => false,
        ]);

        $this->postJson('/api/v1/appointments', [
            'staffProfileId' => $staff->id,
            'date' => $date,
            'startTime' => '10:00',
            'endTime' => '10:30',
            'subject' => 'Ofis saati',
        ])->assertCreated();

        $this->postJson('/api/v1/appointments', [
            'staffProfileId' => $staff->id,
            'date' => $date,
            'startTime' => '10:00',
            'endTime' => '10:30',
            'subject' => 'Ofis saati',
        ])->assertStatus(409)->assertJsonPath('error.code', 'APPOINTMENT_ALREADY_EXISTS');

        $this->assertEquals(1, Appointment::where('status', 'pending')->count());
    }

    public function test_achievement_admin_crud(): void
    {
        $this->actingAsRole('superAdmin');
        $this->postJson('/api/v1/admin/achievements', [
            'id' => 'ach-test',
            'title' => 'Test',
            'triggerKind' => 'checkin_count',
            'threshold' => 1,
            'active' => true,
        ])->assertCreated();

        $this->getJson('/api/v1/admin/achievements')->assertOk()
            ->assertJsonFragment(['id' => 'ach-test']);

        $this->postJson('/api/v1/admin/achievements/ach-test/delete')->assertOk();
        $this->assertDatabaseMissing('achievement_definitions', ['id' => 'ach-test']);
    }
}
