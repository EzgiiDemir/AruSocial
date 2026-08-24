<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Sentry\Laravel\Integration;
use Tests\Concerns\FakesSentry;
use Tests\TestCase;

// P3-6 §21: an exception reaches the fake Sentry transport, and the API
// response envelope/status/request_id are completely unaffected by any of
// this wiring. No real Sentry network call is made (see FakesSentry).
class SentryExceptionTest extends TestCase
{
    use FakesSentry;
    use RefreshDatabase;

    public function test_reportable_exception_is_captured_by_sentry(): void
    {
        $transport = $this->fakeSentry();

        // This is exactly the closure bootstrap/app.php registers via
        // Sentry\Laravel\Integration::handles($exceptions) — the same call
        // Laravel's exception handler makes for every reportable exception.
        Integration::captureUnhandledException(new \RuntimeException('boom for sentry test'));

        $this->assertCount(1, $transport->events);
        $this->assertSame('boom for sentry test', $transport->events[0]->getExceptions()[0]->getValue());
    }

    public function test_existing_validation_error_envelope_and_status_are_unchanged(): void
    {
        $this->fakeSentry();
        $this->actingAsRole('superAdmin');

        // A real, existing controlled-failure path (P3-6 §23: "controlled
        // error endpoint yoksa mevcut validation/404 kullan") — empty bulk
        // email payload already returns 400/VALIDATION before Sentry existed.
        $response = $this->postJson('/api/v1/admin/email/bulk', [
            'recipients' => [],
            'subject' => '',
            'body' => '',
        ]);

        $response->assertStatus(400);
        $response->assertJsonStructure(['data', 'meta' => ['request_id'], 'error' => ['code', 'message']]);
        $this->assertSame('VALIDATION', $response->json('error.code'));
        $this->assertIsString($response->json('meta.request_id'));
        $this->assertStringStartsWith('req-', $response->json('meta.request_id'));
    }

    public function test_authentication_required_envelope_is_unchanged_and_not_reported(): void
    {
        $transport = $this->fakeSentry();

        // No token at all — the existing bootstrap/app.php render() callback
        // for AuthenticationException, untouched by the Sentry wiring.
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401);
        $response->assertJson(['data' => null, 'error' => ['code' => 'AUTH_REQUIRED']]);
        $this->assertIsString($response->json('meta.request_id'));

        // Laravel's default $dontReport already excludes AuthenticationException
        // from report() — expected 401s shouldn't fill a Sentry project.
        $this->assertCount(0, $transport->events);
    }

    public function test_request_id_tag_matches_the_api_response_request_id(): void
    {
        $transport = $this->fakeSentry();
        $this->actingAsRole('superAdmin');

        $response = $this->getJson('/api/v1/admin/email-logs');
        $response->assertOk();
        $requestId = $response->json('meta.request_id');

        // Trigger a manual capture within the same (simulated) request scope
        // to prove AttachSentryContext's tag lines up with the response body
        // request_id (P3-6 §9) — sentry-context runs on this route group.
        \Sentry\captureException(new \RuntimeException('correlation check'));

        $this->assertCount(1, $transport->events);
        $this->assertSame($requestId, $transport->events[0]->getTags()['request_id'] ?? null);
    }
}
