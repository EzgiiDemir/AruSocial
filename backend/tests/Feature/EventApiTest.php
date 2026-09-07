<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventParticipationType;
use App\Models\Place;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // These tests walk both sides of the same flow — a student joining
        // an event and an admin approving the roster — so the acting
        // account holds the admin permissions and simply also does the
        // student-facing calls, which no permission restricts.
        $this->actingAsRole();
    }

    private function seedUser(): User
    {
        return $this->actingAsRole();
    }

    private function seedDepartmentHead(string $id = 'staff-arch-head'): StaffProfile
    {
        return StaffProfile::create([
            'id' => $id,
            'name' => 'Architecture Head',
            'faculty' => 'Fine Arts',
            'department' => 'Architecture',
            'title' => 'Department Head',
            'is_department_head' => true,
            'active' => true,
        ]);
    }

    public function test_events_index_only_returns_published_by_default(): void
    {
        Event::create(['id' => 'e1', 'title' => 'Published', 'time' => '10:00', 'place_name' => 'X', 'category' => 'C']);
        Event::create(['id' => 'e2', 'title' => 'Draft', 'time' => '10:00', 'place_name' => 'X', 'category' => 'C', 'draft' => true]);

        $response = $this->getJson('/api/v1/events');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains('e1'));
        $this->assertFalse($ids->contains('e2'));
    }

    public function test_joining_an_event_is_real_and_idempotent(): void
    {
        $this->seedUser();
        $event = Event::create(['id' => 'e1', 'title' => 'Test', 'time' => '10:00', 'place_name' => 'X', 'category' => 'C', 'xp' => 20]);

        $this->postJson("/api/v1/events/{$event->id}/join")->assertOk();
        $this->assertEquals(0, $event->fresh()->attendees);

        // Joining again must not double-count, and attendees stay 0 until
        // attendance is actually approved.
        $this->postJson("/api/v1/events/{$event->id}/join")->assertOk();
        $this->assertEquals(0, $event->fresh()->attendees);
    }

    public function test_join_rejects_a_participation_type_from_a_different_event(): void
    {
        $this->seedUser();
        $eventA = Event::create(['id' => 'ea', 'title' => 'A', 'time' => '10:00', 'place_name' => 'X', 'category' => 'C']);
        $eventB = Event::create(['id' => 'eb', 'title' => 'B', 'time' => '10:00', 'place_name' => 'X', 'category' => 'C']);
        $wrongType = EventParticipationType::create(['id' => 'pt1', 'event_id' => $eventB->id, 'label' => 'Gönüllü']);

        $response = $this->postJson("/api/v1/events/{$eventA->id}/join", ['participationTypeId' => $wrongType->id]);

        $response->assertStatus(400);
        $this->assertEquals('INVALID_PARTICIPATION_TYPE', $response->json('error.code'));
    }

    public function test_student_created_activity_starts_pending_and_is_not_publicly_listed(): void
    {
        $this->seedUser();
        $this->seedDepartmentHead();
        Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);

        $response = $this->postJson('/api/v1/events/mine', [
            'title' => 'Kendi Aktivitem',
            'placeId' => 'p1',
            'responsibleStaffId' => 'staff-arch-head',
        ]);

        $response->assertStatus(201);
        $this->assertEquals('pending_review', $response->json('data.workflowStatus'));
        $this->assertEquals('staff-arch-head', $response->json('data.responsibleStaffId'));
        $publicIds = collect($this->getJson('/api/v1/events')->json('data'))->pluck('id');
        $this->assertFalse($publicIds->contains($response->json('data.id')));
        $pending = collect($this->getJson('/api/v1/admin/events/pending')->json('data'))->pluck('id');
        $this->assertTrue($pending->contains($response->json('data.id')));
    }

    public function test_get_events_mine_is_not_swallowed_by_the_events_id_wildcard(): void
    {
        // Regression test: /events/mine used to 404 with EVENT_NOT_FOUND
        // because Route::get('/events/{id}', ...) was registered before
        // Route::get('/events/mine', ...) — Laravel matches routes in
        // registration order, so "mine" was captured as {id} and looked
        // up as a literal event id. Fixed by reordering in routes/api.php.
        $this->seedUser();

        $response = $this->getJson('/api/v1/events/mine');

        $response->assertOk();
    }

    public function test_own_activity_requires_a_real_place(): void
    {
        $this->seedUser();
        $this->seedDepartmentHead();

        $response = $this->postJson('/api/v1/events/mine', [
            'title' => 'X',
            'placeId' => 'does-not-exist',
            'responsibleStaffId' => 'staff-arch-head',
        ]);

        $response->assertStatus(400);
        $this->assertEquals('INVALID_PLACE', $response->json('error.code'));
    }

    public function test_own_activity_requires_a_department_head(): void
    {
        $this->seedUser();
        Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);
        StaffProfile::create([
            'id' => 'staff-advisor',
            'name' => 'Advisor',
            'is_department_head' => false,
            'active' => true,
        ]);

        $response = $this->postJson('/api/v1/events/mine', [
            'title' => 'X',
            'placeId' => 'p1',
            'responsibleStaffId' => 'staff-advisor',
        ]);

        $response->assertStatus(400);
        $this->assertEquals('INVALID_STAFF', $response->json('error.code'));
    }

    public function test_own_activity_accepts_an_explicitly_empty_time_field(): void
    {
        // Regression test: Laravel's default ConvertEmptyStringsToNull
        // middleware used to turn an explicitly-sent "" into null before
        // it reached the controller, which bypassed `input('time', '')`'s
        // fallback and violated events.time's NOT NULL constraint with a
        // real 500 — see bootstrap/app.php's removal of that middleware.
        $this->seedUser();
        $this->seedDepartmentHead();
        Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);

        $response = $this->postJson('/api/v1/events/mine', [
            'title' => 'No Time',
            'placeId' => 'p1',
            'responsibleStaffId' => 'staff-arch-head',
            'time' => '',
            'description' => '',
        ]);

        $response->assertStatus(201);
        $this->assertSame('', $response->json('data.time'));
    }

    // docs/EKSIKLER.md §4: a place can't be double-booked at the same
    // date+time slot, in either creation path.
    public function test_own_activity_is_rejected_when_the_place_is_already_booked_that_slot(): void
    {
        $this->seedUser();
        $this->seedDepartmentHead();
        Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);
        Event::create([
            'id' => 'taken', 'title' => 'Zaten Var', 'time' => '14:00', 'event_date' => '2026-09-01',
            'place_id' => 'p1', 'place_name' => 'Garden', 'category' => 'C',
        ]);

        $response = $this->postJson('/api/v1/events/mine', [
            'title' => 'Çakışan',
            'placeId' => 'p1',
            'responsibleStaffId' => 'staff-arch-head',
            'time' => '14:00',
            'eventDate' => '2026-09-01',
        ]);

        $response->assertStatus(409);
        $this->assertEquals('PLACE_UNAVAILABLE', $response->json('error.code'));
    }

    public function test_own_activity_at_a_different_time_the_same_day_is_allowed(): void
    {
        $this->seedUser();
        $this->seedDepartmentHead();
        Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);
        Event::create([
            'id' => 'taken', 'title' => 'Zaten Var', 'time' => '14:00', 'event_date' => '2026-09-01',
            'place_id' => 'p1', 'place_name' => 'Garden', 'category' => 'C',
        ]);

        $this->postJson('/api/v1/events/mine', [
            'title' => 'Farklı Saat',
            'placeId' => 'p1',
            'responsibleStaffId' => 'staff-arch-head',
            'time' => '18:00',
            'eventDate' => '2026-09-01',
        ])->assertStatus(201);
    }

    public function test_a_rejected_event_does_not_block_the_slot_it_held(): void
    {
        $this->seedUser();
        $this->seedDepartmentHead();
        Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);
        Event::create([
            'id' => 'rejected-one', 'title' => 'Reddedildi', 'time' => '14:00', 'event_date' => '2026-09-01',
            'place_id' => 'p1', 'place_name' => 'Garden', 'category' => 'C', 'workflow_status' => 'rejected',
        ]);

        $this->postJson('/api/v1/events/mine', [
            'title' => 'Yeniden Dene',
            'placeId' => 'p1',
            'responsibleStaffId' => 'staff-arch-head',
            'time' => '14:00',
            'eventDate' => '2026-09-01',
        ])->assertStatus(201);
    }

    public function test_admin_upsert_is_also_rejected_for_a_double_booked_slot(): void
    {
        Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);
        Event::create([
            'id' => 'taken', 'title' => 'Zaten Var', 'time' => '14:00', 'event_date' => '2026-09-01',
            'place_id' => 'p1', 'place_name' => 'Garden', 'category' => 'C',
        ]);

        $response = $this->postJson('/api/v1/admin/events', [
            'id' => 'new-one', 'title' => 'Çakışan Admin', 'placeId' => 'p1', 'time' => '14:00',
            'eventDate' => '2026-09-01',
        ]);

        $response->assertStatus(409);
        $this->assertEquals('PLACE_UNAVAILABLE', $response->json('error.code'));
    }

    public function test_admin_editing_an_event_in_place_does_not_conflict_with_itself(): void
    {
        Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);
        Event::create([
            'id' => 'e1', 'title' => 'Mine', 'time' => '14:00', 'event_date' => '2026-09-01',
            'place_id' => 'p1', 'place_name' => 'Garden', 'category' => 'C',
        ]);

        $this->postJson('/api/v1/admin/events', [
            'id' => 'e1', 'title' => 'Mine (renamed)', 'placeId' => 'p1', 'time' => '14:00',
            'eventDate' => '2026-09-01',
        ])->assertOk();
    }

    public function test_place_availability_lists_active_bookings_and_excludes_rejected(): void
    {
        Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);
        Event::create([
            'id' => 'e1', 'title' => 'Booked', 'time' => '14:00', 'event_date' => '2026-09-01',
            'place_id' => 'p1', 'place_name' => 'Garden', 'category' => 'C',
        ]);
        Event::create([
            'id' => 'e2', 'title' => 'Rejected', 'time' => '18:00', 'event_date' => '2026-09-01',
            'place_id' => 'p1', 'place_name' => 'Garden', 'category' => 'C', 'workflow_status' => 'rejected',
        ]);
        Event::create([
            'id' => 'e3', 'title' => 'Other Day', 'time' => '14:00', 'event_date' => '2026-09-02',
            'place_id' => 'p1', 'place_name' => 'Garden', 'category' => 'C',
        ]);

        $response = $this->getJson('/api/v1/places/p1/availability?date=2026-09-01');

        $response->assertOk();
        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Booked'));
        $this->assertFalse($titles->contains('Rejected'));
        $this->assertFalse($titles->contains('Other Day'));
    }

    public function test_admin_can_list_and_approve_event_participants(): void
    {
        $user = $this->seedUser();
        $event = Event::create(['id' => 'e1', 'title' => 'Test', 'time' => '10:00', 'place_name' => 'X', 'category' => 'C', 'xp' => 20]);
        $this->postJson("/api/v1/events/{$event->id}/join")->assertOk();

        $list = $this->getJson("/api/v1/admin/events/{$event->id}/participants");
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));
        $joinId = $list->json('data.0.id');
        $this->assertNull($list->json('data.0.approvedAt'));
        $this->assertNull($list->json('data.0.formSubmittedAt'));

        // Approval must be gated on the student actually completing the
        // form — see EventController::submitForm().
        $this->postJson("/api/v1/admin/events/{$event->id}/participants/{$joinId}/approve",
            ['actorName' => 'Hoca'])->assertStatus(400)->assertJsonPath('error.code', 'FORM_NOT_SUBMITTED');

        $this->postJson("/api/v1/events/{$event->id}/join/form")->assertOk()
            ->assertJsonPath('data.formSubmitted', true);

        $approve = $this->postJson("/api/v1/admin/events/{$event->id}/participants/{$joinId}/approve",
            ['actorName' => 'Hoca']);
        $approve->assertOk();

        $after = $this->getJson("/api/v1/admin/events/{$event->id}/participants");
        $this->assertNotNull($after->json('data.0.formSubmittedAt'));
        $this->assertNotNull($after->json('data.0.approvedAt'));
        $this->assertEquals('Test superAdmin', $after->json('data.0.approvedBy'));
        $this->assertEquals(1, $event->fresh()->attendees);
        $this->assertEquals(20, $user->fresh()->xp);
    }

    public function test_submitting_the_form_before_joining_is_rejected(): void
    {
        $this->seedUser();
        $event = Event::create(['id' => 'e1', 'title' => 'Test', 'time' => '10:00', 'place_name' => 'X', 'category' => 'C']);

        $this->postJson("/api/v1/events/{$event->id}/join/form")
            ->assertStatus(400)->assertJsonPath('error.code', 'NOT_JOINED');
    }

    public function test_approving_a_pending_activity_actually_makes_it_publicly_visible(): void
    {
        $this->seedUser();
        $this->seedDepartmentHead();
        Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);
        $created = $this->postJson('/api/v1/events/mine', [
            'title' => 'Kendi Aktivitem',
            'placeId' => 'p1',
            'responsibleStaffId' => 'staff-arch-head',
        ]);
        $id = $created->json('data.id');

        $this->postJson("/api/v1/admin/events/{$id}/approve")->assertOk();

        $publicIds = collect($this->getJson('/api/v1/events')->json('data'))->pluck('id');
        $this->assertTrue($publicIds->contains($id));
    }
}
