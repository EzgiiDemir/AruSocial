<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ParticipationApplication;
use App\Models\RoleAssignment;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrainerApplicationsApiTest extends TestCase
{
    use RefreshDatabase;

    private function seedTrainer(string $staffId, string $department): array
    {
        $user = $this->actingAsRole('trainer');
        $staff = StaffProfile::create([
            'id' => $staffId, 'name' => "$department Head", 'department' => $department,
            'is_department_head' => true, 'active' => true, 'user_id' => $user->id,
        ]);

        return [$user, $staff];
    }

    /** Preview + Detail (via the real token'd web form) — leaves the application at `under_review`, ready for a trainer decision. */
    private function submitApplication(string $studentEmail, string $responsibleStaffId): ParticipationApplication
    {
        $student = User::create(['name' => 'Student', 'email' => $studentEmail, 'password' => bcrypt('x')]);
        Club::create(['id' => "club-{$responsibleStaffId}", 'name' => 'Club', 'category' => 'Art', 'responsible_staff_id' => $responsibleStaffId]);
        $this->actingAsUser($student);
        $data = $this->postJson('/api/v1/applications', [
            'targetType' => 'club', 'targetId' => "club-{$responsibleStaffId}",
        ])->json('data');

        $app = ParticipationApplication::find($data['id']);
        $this->post("/forms/application/{$app->detail_form_token}", [])->assertOk();

        return $app->fresh();
    }

    public function test_a_trainer_only_sees_applications_for_their_own_department(): void
    {
        [, $staffA] = $this->seedTrainer('staff-a-head', 'Architecture');
        $ownApp = $this->submitApplication('student-a@arucad.edu.tr', $staffA->id);

        // A second, unrelated department's application.
        $userB = User::create(['name' => 'Other Head', 'email' => 'other-head@arucad.edu.tr', 'password' => bcrypt('x')]);
        RoleAssignment::create(['email' => $userB->email, 'role' => 'trainer', 'assigned_by' => 'test', 'assigned_at' => now()]);
        $staffB = StaffProfile::create([
            'id' => 'staff-b-head', 'name' => 'Photography Head', 'department' => 'Photography',
            'is_department_head' => true, 'active' => true, 'user_id' => $userB->id,
        ]);
        $otherApp = $this->submitApplication('student-b@arucad.edu.tr', $staffB->id);

        $trainerA = StaffProfile::find($staffA->id)->user;
        $this->actingAsUser($trainerA);

        $ids = collect($this->getJson('/api/v1/trainer/applications')->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($ownApp->id));
        $this->assertFalse($ids->contains($otherApp->id));
    }

    public function test_a_trainer_can_approve_their_own_departments_application(): void
    {
        [$trainer, $staff] = $this->seedTrainer('staff-arch-head', 'Architecture');
        $app = $this->submitApplication('student@arucad.edu.tr', $staff->id);
        $this->actingAsUser($trainer);

        $this->postJson("/api/v1/trainer/applications/{$app->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_a_trainer_cannot_decide_another_departments_application(): void
    {
        [, $staffA] = $this->seedTrainer('staff-a-head', 'Architecture');
        $userB = User::create(['name' => 'Other Head', 'email' => 'other-head@arucad.edu.tr', 'password' => bcrypt('x')]);
        RoleAssignment::create(['email' => $userB->email, 'role' => 'trainer', 'assigned_by' => 'test', 'assigned_at' => now()]);
        $staffB = StaffProfile::create([
            'id' => 'staff-b-head', 'name' => 'Photography Head', 'department' => 'Photography',
            'is_department_head' => true, 'active' => true, 'user_id' => $userB->id,
        ]);
        $otherApp = $this->submitApplication('student-b@arucad.edu.tr', $staffB->id);

        $trainerA = StaffProfile::find($staffA->id)->user;
        $this->actingAsUser($trainerA);

        $this->postJson("/api/v1/trainer/applications/{$otherApp->id}/approve")->assertStatus(404);
        $this->assertDatabaseHas('participation_applications', ['id' => $otherApp->id, 'status' => 'under_review']);
    }

    public function test_reject_and_revise_require_a_review_note(): void
    {
        [$trainer, $staff] = $this->seedTrainer('staff-arch-head', 'Architecture');
        $app = $this->submitApplication('student@arucad.edu.tr', $staff->id);
        $this->actingAsUser($trainer);

        $this->postJson("/api/v1/trainer/applications/{$app->id}/reject", [])
            ->assertStatus(422)->assertJsonPath('error.code', 'REVIEW_NOTE_REQUIRED');

        $this->postJson("/api/v1/trainer/applications/{$app->id}/revise", ['reviewNote' => 'Lütfen tekrar başvur.'])
            ->assertOk()->assertJsonPath('data.status', 'revision_required');
    }

    public function test_roster_only_shows_the_trainers_own_department(): void
    {
        [, $staff] = $this->seedTrainer('staff-arch-head', 'Architecture');
        StaffProfile::create([
            'id' => 'staff-arch-adv', 'name' => 'Architecture Advisor', 'department' => 'Architecture',
            'is_department_head' => false, 'active' => true,
        ]);
        StaffProfile::create([
            'id' => 'staff-photo-head', 'name' => 'Photography Head', 'department' => 'Photography',
            'is_department_head' => true, 'active' => true,
        ]);
        StaffProfile::create([
            'id' => 'staff-arch-inactive', 'name' => 'Retired Advisor', 'department' => 'Architecture',
            'is_department_head' => false, 'active' => false,
        ]);

        $names = collect($this->getJson('/api/v1/trainer/roster')->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Architecture Head'));
        $this->assertTrue($names->contains('Architecture Advisor'));
        $this->assertFalse($names->contains('Photography Head'));
        $this->assertFalse($names->contains('Retired Advisor'));
    }
}
