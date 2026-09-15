<?php

namespace Tests\Feature;

use App\Events\CampusDataChanged;
use App\Models\Event;
use App\Models\Place;
use App\Models\RoleAssignment;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Tests\TestCase;

class TrainerEventApiTest extends TestCase
{
    use RefreshDatabase;

    private function seedTrainer(string $staffId = 'staff-arch-head'): array
    {
        $user = $this->actingAsRole('trainer');
        $staff = StaffProfile::create([
            'id' => $staffId, 'name' => 'Architecture Head', 'department' => 'Architecture',
            'is_department_head' => true, 'active' => true, 'user_id' => $user->id,
        ]);

        return [$user, $staff];
    }

    public function test_a_trainer_can_publish_an_event_that_is_live_immediately(): void
    {
        EventFacade::fake([CampusDataChanged::class]);
        [$user, $staff] = $this->seedTrainer();
        Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Social', 'lat' => 1, 'lng' => 1]);

        $response = $this->postJson('/api/v1/trainer/events', [
            'title' => 'Mimarlık Sergisi', 'placeId' => 'p1', 'category' => 'Art',
        ]);
        $response->assertStatus(201);
        $this->assertSame('published', $response->json('data.workflowStatus'));
        $this->assertSame($staff->id, $response->json('data.responsibleStaffId'));

        // Live in the public events list immediately — no review queue.
        $publicIds = collect($this->getJson('/api/v1/events')->json('data'))->pluck('id');
        $this->assertTrue($publicIds->contains($response->json('data.id')));
        EventFacade::assertDispatched(CampusDataChanged::class, function (CampusDataChanged $change) use ($response) {
            return $change->resources === ['events']
                && $change->action === 'created'
                && $change->id === $response->json('data.id');
        });
    }

    public function test_a_non_department_head_account_is_rejected_even_with_the_trainer_role(): void
    {
        $this->actingAsRole('trainer');
        // No StaffProfile linked at all.

        $this->postJson('/api/v1/trainer/events', ['title' => 'X', 'placeId' => 'p1'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'NOT_A_DEPARTMENT_HEAD');
    }

    public function test_a_student_cannot_reach_trainer_routes_at_all(): void
    {
        $this->actingAsRole('student');

        $this->getJson('/api/v1/trainer/events')->assertStatus(403);
    }

    public function test_a_trainer_only_sees_and_can_only_edit_their_own_departments_events(): void
    {
        [$userA, $staffA] = $this->seedTrainer('staff-arch-head');
        Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Social', 'lat' => 1, 'lng' => 1]);
        $ownEvent = $this->postJson('/api/v1/trainer/events', [
            'title' => 'Own Event', 'placeId' => 'p1',
        ])->json('data');

        // A second trainer, different department.
        $userB = User::create(['name' => 'Other Head', 'email' => 'other-head@arucad.edu.tr', 'password' => bcrypt('x')]);
        RoleAssignment::create(['email' => $userB->email, 'role' => 'trainer', 'assigned_by' => 'test', 'assigned_at' => now()]);
        StaffProfile::create([
            'id' => 'staff-other-head', 'name' => 'Other Head', 'department' => 'Photography',
            'is_department_head' => true, 'active' => true, 'user_id' => $userB->id,
        ]);
        $this->actingAsUser($userB);

        // B's list doesn't include A's event.
        $ids = collect($this->getJson('/api/v1/trainer/events')->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($ownEvent['id']));

        // B can't edit or delete A's event by id-guessing.
        $this->postJson('/api/v1/trainer/events', ['id' => $ownEvent['id'], 'title' => 'Hijacked', 'placeId' => 'p1'])
            ->assertStatus(404);
        $this->postJson("/api/v1/trainer/events/{$ownEvent['id']}/delete")->assertStatus(404);
        $this->assertDatabaseHas('events', ['id' => $ownEvent['id'], 'title' => 'Own Event']);
    }

    public function test_a_trainer_can_update_and_delete_their_own_event(): void
    {
        [$user, $staff] = $this->seedTrainer();
        Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Social', 'lat' => 1, 'lng' => 1]);
        $event = $this->postJson('/api/v1/trainer/events', ['title' => 'V1', 'placeId' => 'p1'])->json('data');

        $update = $this->postJson('/api/v1/trainer/events', ['id' => $event['id'], 'title' => 'V2', 'placeId' => 'p1']);
        $update->assertOk();
        $this->assertSame('V2', $update->json('data.title'));

        $this->postJson("/api/v1/trainer/events/{$event['id']}/delete")->assertOk();
        $this->assertSoftDeleted('events', ['id' => $event['id']]);
    }

    public function test_place_conflict_is_still_enforced_for_trainers(): void
    {
        [$user, $staff] = $this->seedTrainer();
        Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Social', 'lat' => 1, 'lng' => 1]);
        Event::create([
            'id' => 'e-existing', 'title' => 'Existing', 'time' => '14:00',
            'event_date' => '2026-09-01', 'place_id' => 'p1', 'place_name' => 'Garden',
            'category' => 'Art', 'attendees' => 0, 'xp' => 10, 'draft' => false, 'workflow_status' => 'published',
        ]);

        $response = $this->postJson('/api/v1/trainer/events', [
            'title' => 'New', 'placeId' => 'p1', 'eventDate' => '2026-09-01', 'time' => '14:00',
        ]);

        $response->assertStatus(409);
        $this->assertSame('PLACE_UNAVAILABLE', $response->json('error.code'));
    }

    public function test_a_trainer_can_list_and_approve_participants_on_their_own_event(): void
    {
        [$trainer, $staff] = $this->seedTrainer();
        Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Social', 'lat' => 1, 'lng' => 1]);
        $event = $this->postJson('/api/v1/trainer/events', ['title' => 'Sergi', 'placeId' => 'p1'])->json('data');

        $student = User::create(['name' => 'Student', 'email' => 'student@arucad.edu.tr', 'password' => bcrypt('x')]);
        $this->actingAsUser($student);
        $this->postJson("/api/v1/events/{$event['id']}/join")->assertOk();

        $this->actingAsUser($trainer);
        $list = $this->getJson("/api/v1/trainer/events/{$event['id']}/participants");
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));
        $joinId = $list->json('data.0.id');

        $this->postJson("/api/v1/trainer/events/{$event['id']}/participants/{$joinId}/approve")
            ->assertStatus(400)->assertJsonPath('error.code', 'FORM_NOT_SUBMITTED');

        $this->actingAsUser($student);
        $this->postJson("/api/v1/events/{$event['id']}/join/form")->assertOk();

        $this->actingAsUser($trainer);
        $this->postJson("/api/v1/trainer/events/{$event['id']}/participants/{$joinId}/approve")->assertOk();

        $after = $this->getJson("/api/v1/trainer/events/{$event['id']}/participants");
        $this->assertNotNull($after->json('data.0.approvedAt'));
        $this->assertEquals($staff->name, $after->json('data.0.approvedBy'));
    }

    public function test_a_trainer_cannot_see_or_approve_participants_on_another_departments_event(): void
    {
        [, $staffA] = $this->seedTrainer('staff-a-head');
        Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Social', 'lat' => 1, 'lng' => 1]);
        $event = $this->postJson('/api/v1/trainer/events', ['title' => 'A Event', 'placeId' => 'p1'])->json('data');

        $userB = User::create(['name' => 'Other Head', 'email' => 'other-head@arucad.edu.tr', 'password' => bcrypt('x')]);
        RoleAssignment::create(['email' => $userB->email, 'role' => 'trainer', 'assigned_by' => 'test', 'assigned_at' => now()]);
        StaffProfile::create([
            'id' => 'staff-b-head', 'name' => 'Other Head', 'department' => 'Photography',
            'is_department_head' => true, 'active' => true, 'user_id' => $userB->id,
        ]);
        $this->actingAsUser($userB);

        $this->getJson("/api/v1/trainer/events/{$event['id']}/participants")->assertStatus(404);
        $this->postJson("/api/v1/trainer/events/{$event['id']}/participants/some-join-id/approve")->assertStatus(404);
    }
}
