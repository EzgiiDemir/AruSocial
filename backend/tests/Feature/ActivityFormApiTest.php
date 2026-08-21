<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Place;
use Database\Seeders\AcademicStaffSeeder;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

// Real, single-use signed-URL activity form (docs/EKSIKLER.md aktivite/onay
// workflow §1/§2/§3) — the actual browser-facing web page/route behind
// the link ActivityFormRequestedMail sends, not the JSON API.
class ActivityFormApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->seed(AcademicStaffSeeder::class);
    }

    private function createActivity(): Event
    {
        $this->actingAsAdmin();
        Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);
        $response = $this->postJson('/api/v1/events/mine', ['title' => 'Kendi Aktivitem', 'placeId' => 'p1']);

        return Event::find($response->json('data.id'));
    }

    public function test_the_signed_form_url_is_reachable_and_shows_the_activity(): void
    {
        $event = $this->createActivity();

        $formUrl = URL::signedRoute('activity.form.show', ['event' => $event->id]);
        $response = $this->get($formUrl);

        $response->assertOk();
        $response->assertSee($event->title);
    }

    public function test_an_unsigned_or_tampered_url_is_rejected(): void
    {
        $event = $this->createActivity();

        $response = $this->get("/activity-form/{$event->id}");

        $response->assertStatus(403);
    }

    public function test_submitting_the_form_routes_to_the_real_department_head_and_moves_to_pending_approval(): void
    {
        $event = $this->createActivity();
        $formUrl = URL::signedRoute('activity.form.show', ['event' => $event->id]);

        $response = $this->post($formUrl, [
            'studentNumber' => '20231234',
            'phone' => '05551234567',
            'faculty' => 'İletişim Fakültesi',
            'department' => 'Yeni Medya ve İletişim',
            'purpose' => 'Öğrenci kulübü tanıtım etkinliği.',
        ]);

        $response->assertOk();
        $fresh = $event->fresh();
        $this->assertEquals('pending_approval', $fresh->workflow_status);
        $this->assertEquals('20231234', $fresh->student_number);
        $this->assertNotNull($fresh->assigned_staff_id);
        $this->assertEquals('Çağdaş Öğüç', $fresh->assignedStaff->name);
    }

    public function test_the_form_cannot_be_submitted_twice(): void
    {
        $event = $this->createActivity();
        $formUrl = URL::signedRoute('activity.form.show', ['event' => $event->id]);
        $payload = [
            'studentNumber' => '20231234', 'phone' => '05551234567',
            'faculty' => 'İletişim Fakültesi', 'department' => 'Yeni Medya ve İletişim',
            'purpose' => 'İlk gönderim.',
        ];
        $this->post($formUrl, $payload)->assertOk();

        $second = $this->post($formUrl, [...$payload, 'purpose' => 'İkinci gönderim.']);

        $second->assertOk();
        // The "unavailable" page is still a 200 (a real, rendered page,
        // not an error) — the real assertion is that the second submit
        // never overwrote the first real answer.
        $this->assertEquals('İlk gönderim.', $event->fresh()->purpose);
    }

    public function test_missing_required_fields_are_rejected_with_real_validation(): void
    {
        $event = $this->createActivity();
        $formUrl = URL::signedRoute('activity.form.show', ['event' => $event->id]);

        $response = $this->post($formUrl, ['studentNumber' => '20231234']);

        $response->assertSessionHasErrors(['phone', 'faculty', 'department', 'purpose']);
        $this->assertEquals('form_required', $event->fresh()->workflow_status);
    }

    public function test_a_department_with_no_head_falls_back_to_the_dean_when_routing(): void
    {
        $event = $this->createActivity();
        $formUrl = URL::signedRoute('activity.form.show', ['event' => $event->id]);

        $this->post($formUrl, [
            'studentNumber' => '20231234', 'phone' => '05551234567',
            'faculty' => 'Sanat Fakültesi', 'department' => 'Seramik',
            'purpose' => 'Seramik atölyesi etkinliği.',
        ])->assertOk();

        $this->assertEquals('Nur Onat', $event->fresh()->assignedStaff->name);
    }
}
