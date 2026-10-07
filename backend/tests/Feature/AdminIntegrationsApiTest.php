<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\AppSetting;
use App\Models\IntegrationState;
use App\Services\Integrations\IntegrationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminIntegrationsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // No test in this file may reach a real provider. Anything not
        // explicitly faked below would throw rather than silently escape.
        Http::preventStrayRequests();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/integrations')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
    }

    public function test_ordinary_student_cannot_list_integrations(): void
    {
        $this->actingAsRole('student');

        $this->getJson('/api/v1/admin/integrations')->assertStatus(403);
    }

    public function test_student_cannot_toggle_or_test_an_integration(): void
    {
        $this->actingAsRole('student');

        $this->postJson('/api/v1/admin/integrations/groq/test')->assertStatus(403);
        $this->postJson('/api/v1/admin/integrations/groq/enabled', ['enabled' => false])
            ->assertStatus(403);
    }

    public function test_admin_sees_every_registered_integration(): void
    {
        $this->actingAsRole();

        $response = $this->getJson('/api/v1/admin/integrations')->assertOk();

        $keys = array_column($response->json('data'), 'key');
        foreach (array_keys(app(IntegrationRegistry::class)->definitions()) as $expected) {
            $this->assertContains($expected, $keys);
        }
    }

    public function test_configured_secret_is_never_returned_only_a_mask(): void
    {
        config()->set('services.groq.key', 'gsk_supersecretvalue_9876543210');
        $this->actingAsRole();

        $response = $this->getJson('/api/v1/admin/integrations')->assertOk();

        // The raw secret must not appear anywhere in the payload.
        $this->assertStringNotContainsString('gsk_supersecretvalue_9876543210', $response->getContent());

        $groq = collect($response->json('data'))->firstWhere('key', 'groq');
        $this->assertTrue($groq['configured']);
        $this->assertSame('••••••••3210', $groq['secretMasked']);
    }

    public function test_env_key_names_are_listed_but_never_their_values(): void
    {
        config()->set('services.campus_directory.api_key', 'cd_live_abcdef0123456789');
        $this->actingAsRole();

        $response = $this->getJson('/api/v1/admin/integrations')->assertOk();
        $row = collect($response->json('data'))->firstWhere('key', 'campus_directory');

        $this->assertContains('CAMPUS_DIRECTORY_API_KEY', $row['envKeys']);
        $this->assertStringNotContainsString('cd_live_abcdef0123456789', $response->getContent());
    }

    public function test_missing_credentials_report_not_configured(): void
    {
        // Routing is two graphs now (walking on ROUTING_BASE_URL, vehicles
        // on ROUTING_DRIVING_BASE_URL) and is "configured" if EITHER can
        // answer, so both have to be cleared for this to mean what it says.
        config()->set('services.routing.base_url', null);
        config()->set('services.routing.driving_base_url', null);
        config()->set('services.routing.allow_public_fallback', false);
        $this->actingAsRole();

        $row = collect($this->getJson('/api/v1/admin/integrations')->json('data'))
            ->firstWhere('key', 'routing');

        $this->assertSame(IntegrationRegistry::STATUS_NOT_CONFIGURED, $row['status']);
        $this->assertFalse($row['configured']);
    }

    public function test_disabled_outranks_configured(): void
    {
        config()->set('services.groq.key', 'gsk_live_key_value_here_1234');
        $this->actingAsRole();

        $this->postJson('/api/v1/admin/integrations/groq/enabled', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.status', IntegrationRegistry::STATUS_DISABLED);

        $row = collect($this->getJson('/api/v1/admin/integrations')->json('data'))
            ->firstWhere('key', 'groq');
        $this->assertSame(IntegrationRegistry::STATUS_DISABLED, $row['status']);
        $this->assertFalse($row['enabled']);
    }

    public function test_a_failed_test_moves_the_integration_to_error(): void
    {
        config()->set('services.groq.key', 'gsk_live_key_value_here_1234');
        Http::fake(['api.groq.com/*' => Http::response(['error' => 'nope'], 401)]);
        $this->actingAsRole();

        $this->postJson('/api/v1/admin/integrations/groq/test')
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.integration.status', IntegrationRegistry::STATUS_ERROR);

        $state = IntegrationState::find('groq');
        $this->assertFalse($state->last_test_ok);
        $this->assertNotNull($state->last_error_at);
        $this->assertNull($state->last_success_at);
    }

    public function test_a_successful_test_records_last_success_and_clears_the_error(): void
    {
        config()->set('services.groq.key', 'gsk_live_key_value_here_1234');
        IntegrationState::create([
            'key' => 'groq',
            'last_test_ok' => false,
            'last_error' => 'eski hata',
            'last_error_at' => now()->subDay(),
        ]);
        Http::fake(['api.groq.com/*' => Http::response(['data' => []], 200)]);
        $this->actingAsRole();

        $this->postJson('/api/v1/admin/integrations/groq/test')
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.integration.status', IntegrationRegistry::STATUS_CONNECTED);

        $state = IntegrationState::find('groq')->refresh();
        $this->assertTrue($state->last_test_ok);
        $this->assertNotNull($state->last_success_at);
        $this->assertNull($state->last_error);
    }

    public function test_testing_an_unconfigured_integration_is_skipped_not_an_error(): void
    {
        config()->set('services.groq.key', null);
        $this->actingAsRole();

        $this->postJson('/api/v1/admin/integrations/groq/test')
            ->assertOk()
            ->assertJsonPath('data.skipped', true)
            ->assertJsonPath('data.integration.status', IntegrationRegistry::STATUS_NOT_CONFIGURED);

        // A skipped test must not poison the row into Error.
        $this->assertNull(IntegrationState::find('groq')->last_test_ok);
    }

    public function test_provider_error_body_is_not_echoed_to_the_operator(): void
    {
        config()->set('services.campus_directory.api_key', 'cd_live_abcdef0123456789');
        Http::fake([
            '360.arucad.edu.tr/*' => Http::response(
                ['message' => 'Bearer cd_live_abcdef0123456789 rejected'],
                403,
            ),
        ]);
        $this->actingAsRole();

        $response = $this->postJson('/api/v1/admin/integrations/campus_directory/test')->assertOk();

        $this->assertStringNotContainsString('cd_live_abcdef0123456789', $response->getContent());
        $this->assertSame(
            'API anahtarı reddedildi (yetkisiz).',
            $response->json('data.message'),
        );
    }

    public function test_unknown_integration_is_a_404_not_a_500(): void
    {
        $this->actingAsRole();

        $this->postJson('/api/v1/admin/integrations/does-not-exist/test')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'INTEGRATION_NOT_FOUND');
    }

    public function test_configuration_and_status_changes_are_audit_logged(): void
    {
        config()->set('services.groq.key', 'gsk_live_key_value_here_1234');
        Http::fake(['api.groq.com/*' => Http::response(['data' => []], 200)]);
        $this->actingAsRole();

        $this->postJson('/api/v1/admin/integrations/groq/enabled', ['enabled' => false])->assertOk();
        $this->postJson('/api/v1/admin/integrations/groq/test')->assertOk();

        $entries = AdminAuditLog::where('target_type', 'integration')->pluck('target_label');
        $this->assertTrue($entries->contains(fn ($l) => str_contains($l, 'devre dışı bırakıldı')));
        $this->assertTrue($entries->contains(fn ($l) => str_contains($l, 'bağlantı testi')));
        // The audit trail must never carry the credential either.
        $this->assertFalse($entries->contains(fn ($l) => str_contains($l, 'gsk_live')));
    }

    public function test_admin_managed_integration_reads_its_secret_from_app_settings(): void
    {
        AppSetting::setValue('wordpress.siteUrl', 'https://example.edu.tr');
        AppSetting::setValue('wordpress.apiToken', 'wp_token_value_abcdefgh');
        $this->actingAsRole();

        $row = collect($this->getJson('/api/v1/admin/integrations')->json('data'))
            ->firstWhere('key', 'wordpress');

        $this->assertTrue($row['configured']);
        $this->assertSame('admin', $row['managedVia']);
        $this->assertSame('••••••••efgh', $row['secretMasked']);
    }

    /**
     * Each press of "test" is a real outbound request carrying a real key, so
     * the endpoint is throttled even though only staff can reach it — an
     * operator leaning on the button could get the credential rate-limited or
     * blocked at the provider's end. 10/minute per account
     * (RateLimiter::for('integration-tests')).
     */
    public function test_connection_tests_are_rate_limited(): void
    {
        config()->set('services.groq.key', 'gsk_live_key_value_here_1234');
        Http::fake(['api.groq.com/*' => Http::response(['data' => []], 200)]);
        $this->actingAsRole();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/admin/integrations/groq/test')->assertOk();
        }

        $this->postJson('/api/v1/admin/integrations/groq/test')->assertStatus(429);
    }

    public function test_providers_without_a_live_test_say_so_rather_than_faking_one(): void
    {
        config()->set('services.fcm.project_id', 'p');
        config()->set('services.fcm.client_email', 'a@b.c');
        config()->set('services.fcm.private_key', '-----BEGIN PRIVATE KEY-----abc');
        $this->actingAsRole();

        $row = collect($this->getJson('/api/v1/admin/integrations')->json('data'))
            ->firstWhere('key', 'fcm');
        $this->assertFalse($row['remotelyTestable']);

        // Still answers, but the wording must not claim a connection.
        $response = $this->postJson('/api/v1/admin/integrations/fcm/test')->assertOk();
        $this->assertStringContainsString('canlı bağlantı testi yapılmıyor', $response->json('data.message'));
    }
}
