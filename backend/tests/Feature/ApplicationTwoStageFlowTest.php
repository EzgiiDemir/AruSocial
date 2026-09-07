<?php

namespace Tests\Feature;

use App\Models\ApplicationQuestion;
use App\Models\Club;
use App\Models\Event;
use App\Models\ParticipationApplication;
use App\Models\StaffProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Full end-to-end coverage of the two-stage apply flow described in
// docs/API_CONTRACT.md's Applications section: a real Preview submission
// with category-specific required questions, the emailed token actually
// resolving to a real, working Detail form, and the full decision space
// (approve / reject / revise) each doing what they claim.
class ApplicationTwoStageFlowTest extends TestCase
{
    use RefreshDatabase;

    private function seedClubWithQuestions(): StaffProfile
    {
        $staff = StaffProfile::create([
            'id' => 'staff-club', 'name' => 'Kulüp Sorumlusu', 'department' => 'Student Affairs',
            'is_department_head' => false, 'active' => true,
        ]);
        Club::create([
            'id' => 'club-1', 'name' => 'Satranç Kulübü', 'category' => 'Hobi',
            'description' => 'x', 'responsible_staff_id' => $staff->id,
        ]);
        ApplicationQuestion::create([
            'id' => 'q-preview-experience', 'target_type' => 'club', 'stage' => 'preview',
            'type' => 'single_choice', 'label' => 'Daha önce ilgilendiniz mi?',
            'options' => ['Evet', 'Hayır'], 'required' => true, 'sort_order' => 1, 'active' => true,
        ]);
        ApplicationQuestion::create([
            'id' => 'q-detail-motivation', 'target_type' => 'club', 'stage' => 'detail',
            'type' => 'textarea', 'label' => 'Motivasyon mektubu',
            'required' => true, 'sort_order' => 1, 'active' => true,
        ]);

        return $staff;
    }

    /**
     * Katıl/Başvur applies immediately with no question form shown — an
     * empty Preview submission must always succeed, even for a target type
     * whose (now-unused) preview questions are marked required. Required-
     * answer enforcement still applies at the Detail stage below, which is
     * a real form.
     */
    public function test_preview_submission_never_requires_answers(): void
    {
        $this->actingAsUser();
        $this->seedClubWithQuestions();

        $this->postJson('/api/v1/applications', [
            'targetType' => 'club', 'targetId' => 'club-1', 'formPayload' => [],
        ])->assertCreated()->assertJsonPath('data.status', 'detail_form_pending');
    }

    public function test_preview_submission_stores_an_optional_form_payload(): void
    {
        $this->actingAsUser();
        $this->seedClubWithQuestions();

        $response = $this->postJson('/api/v1/applications', [
            'targetType' => 'club', 'targetId' => 'club-1',
            'formPayload' => ['q-preview-experience' => 'Evet'],
        ])->assertCreated()->assertJsonPath('data.status', 'detail_form_pending');

        // Regression: an empty PHP array and an empty PHP object are both
        // `[]` to json_encode, so `detailPayload` (no answers yet) used to
        // serialize as a JSON array — the frontend's `Map.from(json[...]
        // as Map?)` threw on that for every single fresh application, on
        // every /me/applications and /admin/applications read. Must always
        // be an object, even (especially) when empty.
        $this->assertStringContainsString('"detailPayload":{}', $response->getContent());
        $this->assertStringNotContainsString('"detailPayload":[]', $response->getContent());
    }

    public function test_the_emailed_detail_form_link_is_real_and_category_specific(): void
    {
        $me = $this->actingAsUser();
        $this->seedClubWithQuestions();

        $created = $this->postJson('/api/v1/applications', [
            'targetType' => 'club', 'targetId' => 'club-1',
            'formPayload' => ['q-preview-experience' => 'Evet'],
        ])->assertCreated()->json('data');

        $app = ParticipationApplication::find($created['id']);
        $this->assertNotEmpty($app->detail_form_token);

        // The Preview submission really did send a real, logged email with
        // the detail-form link — and the admin panel can see that it did,
        // not just that the application record exists.
        $this->assertDatabaseHas('email_logs', [
            'application_id' => $app->id,
            'to_email' => $me->email,
            'status' => 'sent',
        ]);
        $this->actingAsRole('superAdmin');
        $this->assertTrue(
            $this->getJson('/api/v1/admin/applications')->assertOk()->json('data.0.emailSent'),
        );
        $this->actingAsUser($me);

        // The real page a student would open from their email — must
        // render this club's own detail question, not a generic form.
        $page = $this->get("/forms/application/{$app->detail_form_token}");
        $page->assertOk();
        $page->assertSee('Motivasyon mektubu');

        // Missing the required detail question re-shows the form with an error.
        $this->post("/forms/application/{$app->detail_form_token}", [])
            ->assertOk()->assertSee('Zorunlu', false);
        $this->assertSame('detail_form_pending', $app->fresh()->status);

        // A wrong/invalid token never reveals anything about a real application.
        $this->get('/forms/application/not-a-real-token')->assertOk()->assertSee('geçersiz');

        $this->post("/forms/application/{$app->detail_form_token}", [
            'q_q-detail-motivation' => 'Satranç oynamayı çok seviyorum.',
        ])->assertOk();

        $app->refresh();
        $this->assertSame('under_review', $app->status);
        $this->assertSame('Satranç oynamayı çok seviyorum.', $app->detail_payload['q-detail-motivation']);
        $this->assertNotNull($app->detail_form_submitted_at);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $me->id, 'kind' => 'application_under_review',
        ]);
    }

    public function test_approve_creates_real_participation_only_after_detail_form(): void
    {
        $me = $this->actingAsUser();
        $this->seedClubWithQuestions();
        $created = $this->postJson('/api/v1/applications', [
            'targetType' => 'club', 'targetId' => 'club-1',
            'formPayload' => ['q-preview-experience' => 'Evet'],
        ])->assertCreated()->json('data');
        $app = ParticipationApplication::find($created['id']);

        // Not decidable before the detail form is in.
        $this->actingAsRole('superAdmin');
        $this->postJson("/api/v1/admin/applications/{$app->id}/approve")
            ->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATE');
        $this->assertDatabaseMissing('club_members', ['user_id' => $me->id, 'club_id' => 'club-1']);

        $this->post("/forms/application/{$app->detail_form_token}", [
            'q_q-detail-motivation' => 'Motivasyonum yüksek.',
        ])->assertOk();

        $this->postJson("/api/v1/admin/applications/{$app->id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('club_members', ['user_id' => $me->id, 'club_id' => 'club-1']);

        // Only the owning student can read their own application's history.
        $this->actingAsUser($me);
        $history = $this->getJson("/api/v1/me/applications/{$app->id}/history")->assertOk()->json('data');
        $toStatuses = collect($history)->pluck('toStatus');
        $this->assertTrue($toStatuses->contains('under_review'));
        $this->assertTrue($toStatuses->contains('approved'));
    }

    public function test_revision_keeps_the_same_token_and_previous_answers_are_replaced_on_resubmit(): void
    {
        $this->actingAsUser();
        $this->seedClubWithQuestions();
        $created = $this->postJson('/api/v1/applications', [
            'targetType' => 'club', 'targetId' => 'club-1',
            'formPayload' => ['q-preview-experience' => 'Evet'],
        ])->assertCreated()->json('data');
        $app = ParticipationApplication::find($created['id']);
        $token = $app->detail_form_token;

        $this->post("/forms/application/{$token}", [
            'q_q-detail-motivation' => 'İlk cevabım.',
        ])->assertOk();

        $this->actingAsRole('superAdmin');
        $this->postJson("/api/v1/admin/applications/{$app->id}/revise", [
            'reviewNote' => 'Lütfen daha detaylı yaz.',
        ])->assertOk()->assertJsonPath('data.status', 'revision_required');

        // Same token, form still fillable, previous answer prefilled.
        $page = $this->get("/forms/application/{$token}");
        $page->assertOk()->assertSee('İlk cevabım.', false);
        $page->assertSee('Lütfen daha detaylı yaz.');

        $this->post("/forms/application/{$token}", [
            'q_q-detail-motivation' => 'Genişletilmiş cevabım.',
        ])->assertOk();

        $app->refresh();
        $this->assertSame('under_review', $app->status);
        $this->assertSame('Genişletilmiş cevabım.', $app->detail_payload['q-detail-motivation']);
    }

    public function test_application_questions_endpoint_returns_only_active_questions_for_the_requested_stage(): void
    {
        $this->actingAsUser();
        $this->seedClubWithQuestions();
        ApplicationQuestion::create([
            'id' => 'q-preview-inactive', 'target_type' => 'club', 'stage' => 'preview',
            'type' => 'text', 'label' => 'Retired question', 'required' => false,
            'sort_order' => 2, 'active' => false,
        ]);

        $labels = collect($this->getJson('/api/v1/application-questions?targetType=club&stage=preview')
            ->assertOk()->json('data'))->pluck('label');
        $this->assertTrue($labels->contains('Daha önce ilgilendiniz mi?'));
        $this->assertFalse($labels->contains('Retired question'));
    }

    public function test_event_application_creates_a_join_only_after_approve(): void
    {
        $me = $this->actingAsUser();
        $staff = StaffProfile::create([
            'id' => 'staff-events-test', 'name' => 'Etkinlik Ofisi', 'department' => 'Student Affairs',
            'is_department_head' => false, 'active' => true,
        ]);
        Event::create([
            'id' => 'e-join-flow', 'title' => 'Bahar Şenliği', 'time' => '14:00',
            'place_name' => 'Garden', 'category' => 'Etkinlik', 'attendees' => 0,
            'responsible_staff_id' => $staff->id,
        ]);
        ApplicationQuestion::create([
            'id' => 'q-event-preview', 'target_type' => 'event', 'stage' => 'preview',
            'type' => 'single_choice', 'label' => 'Amaç',
            'options' => ['Eğlence'], 'required' => true, 'sort_order' => 1, 'active' => true,
        ]);
        ApplicationQuestion::create([
            'id' => 'q-event-detail', 'target_type' => 'event', 'stage' => 'detail',
            'type' => 'textarea', 'label' => 'Not',
            'required' => true, 'sort_order' => 1, 'active' => true,
        ]);

        $created = $this->postJson('/api/v1/applications', [
            'targetType' => 'event', 'targetId' => 'e-join-flow',
            'formPayload' => ['q-event-preview' => 'Eğlence'],
        ])->assertCreated()->json('data');

        $this->assertDatabaseMissing('event_joins', ['event_id' => 'e-join-flow', 'user_id' => $me->id]);

        $app = ParticipationApplication::find($created['id']);
        $this->post("/forms/application/{$app->detail_form_token}", [
            'q_q-event-detail' => 'Katılmak istiyorum.',
        ])->assertOk();

        $this->actingAsRole('superAdmin');
        $this->postJson("/api/v1/admin/applications/{$app->id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('event_joins', ['event_id' => 'e-join-flow', 'user_id' => $me->id]);
        $this->assertSame(1, Event::find('e-join-flow')->attendees);
    }

    public function test_in_app_detail_submission_matches_the_emailed_form(): void
    {
        $this->actingAsUser();
        $this->seedClubWithQuestions();
        $created = $this->postJson('/api/v1/applications', [
            'targetType' => 'club', 'targetId' => 'club-1',
            'formPayload' => ['q-preview-experience' => 'Evet'],
        ])->assertCreated()->json('data');

        $this->assertNotEmpty($created['detailFormUrl']);
        $this->assertSame('detail_form_pending', $created['status']);

        $this->postJson("/api/v1/me/applications/{$created['id']}/detail", [
            'formPayload' => [],
        ])->assertStatus(422)->assertJsonPath('error.code', 'DETAIL_ANSWERS_INCOMPLETE');

        $this->postJson("/api/v1/me/applications/{$created['id']}/detail", [
            'formPayload' => ['q-detail-motivation' => 'Uygulamadan doldurdum.'],
        ])->assertOk()->assertJsonPath('data.status', 'under_review');
    }

    public function test_help_application_accepts_legacy_service_prefix(): void
    {
        $this->actingAsUser();
        StaffProfile::create([
            'id' => 'staff-pdr', 'name' => 'PDR', 'department' => 'Counseling',
            'is_department_head' => false, 'active' => true,
        ]);
        \App\Models\ServiceItem::create([
            'id' => 'pdr', 'title' => 'PDR', 'category' => 'Wellbeing',
            'description' => 'x', 'contact' => 'a@b.c',
            'responsible_staff_id' => 'staff-pdr',
        ]);

        $created = $this->postJson('/api/v1/applications', [
            'targetType' => 'help',
            'targetId' => 'service-pdr',
            'formPayload' => ['note' => 'randevu'],
        ])->assertCreated()->json('data');

        $this->assertSame('pdr', $created['targetId']);
        $this->assertSame('detail_form_pending', $created['status']);
    }
}
