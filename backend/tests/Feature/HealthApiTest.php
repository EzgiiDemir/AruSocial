<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthApiTest extends TestCase
{
    use RefreshDatabase;

    // The Flutter app's API_BASE_URL is this exact URL, so hitting it has
    // to be a real answer rather than Laravel's route-not-found 404.
    public function test_api_v1_root_answers_with_the_standard_envelope(): void
    {
        $response = $this->getJson('/api/v1');

        $response->assertOk();
        $this->assertEquals('ok', $response->json('data.status'));
        $this->assertEquals('v1', $response->json('data.version'));
        $this->assertNull($response->json('error'));
        $this->assertStringStartsWith('req-', $response->json('meta.request_id'));
    }

    public function test_health_reports_a_reachable_database(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk();
        $this->assertEquals('ok', $response->json('data.database'));
    }

    // Diagnostics has to keep answering when the account is blocked,
    // otherwise "the server is down" and "I'm banned" are the same 403 and
    // there's nothing left to tell them apart with.
    public function test_health_stays_reachable_for_a_banned_account(): void
    {
        $banned = User::create(['name' => 'Banned', 'email' => 'banned@arucad.edu.tr', 'password' => bcrypt('x')]);
        $banned->update(['banned_at' => now()]);
        $this->actingAsUser($banned);

        $this->getJson('/api/v1/health')->assertOk();
        $this->getJson('/api/v1/events')->assertStatus(403);
    }

    // It's a reachability probe, not a way around authorization: it must
    // never grow user or domain data that protected endpoints guard.
    public function test_health_exposes_no_user_or_domain_data(): void
    {
        User::create(['name' => 'Someone', 'email' => 'someone@arucad.edu.tr', 'password' => bcrypt('x')]);

        $data = $this->getJson('/api/v1/health')->json('data');

        $this->assertEqualsCanonicalizing(['status', 'service', 'version', 'database'], array_keys($data));
    }
}
