<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Services\Ai\AiBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemHealthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_real_configuration_status_for_a_super_admin(): void
    {
        config([
            // `aiConfigured` asks the provider manager about the SELECTED
            // provider, not about Groq specifically, so emptying the Groq key
            // alone left this passing or failing according to whichever
            // AI_PROVIDER the developer happened to have in their own .env.
            // Name the provider the assertion is about and leave it
            // unconfigured, so the case is "nothing is set up" on every
            // machine.
            'ai.provider' => 'groq',
            'services.groq.key' => '',
            'broadcasting.default' => 'null',
            'broadcasting.connections.reverb.key' => null,
        ]);
        $this->actingAsRole('superAdmin');
        AppSetting::setValue('moderation.apiKey', 'test-key');
        AppSetting::setValue('entra.tenantId', 'tenant-1');
        AppSetting::setValue('entra.clientId', 'client-1');
        AppSetting::setValue('wordpress.apiToken', null);

        $response = $this->getJson('/api/v1/admin/system-health');

        $response->assertOk();
        $this->assertTrue($response->json('data.database'));
        $this->assertTrue($response->json('data.moderationConfigured'));
        $this->assertTrue($response->json('data.entraConfigured'));
        $this->assertFalse($response->json('data.wordpressConfigured'));
        $this->assertFalse($response->json('data.aiConfigured'));
        $this->assertFalse($response->json('data.broadcastingConfigured'));
    }

    /**
     * Capacity lives here, not on the public health endpoint.
     *
     * "How much of today's quota is left" tells an outsider exactly when
     * the assistant is one push away from degrading, which is why
     * HealthApiTest pins the public payload to a fixed key list.
     */
    public function test_it_reports_assistant_capacity_and_degradation(): void
    {
        config([
            'ai.budget.enabled' => true,
            'ai.budget.daily_requests' => 100,
            'ai.budget.max_concurrent' => 8,
        ]);
        $this->actingAsRole('superAdmin');

        $assistant = $this->getJson('/api/v1/admin/system-health')
            ->assertOk()
            ->json('data.assistant');

        $this->assertSame('normal', $assistant['mode']);
        $this->assertSame(100, $assistant['dailyCap']);
        $this->assertSame(8, $assistant['maxConcurrent']);
        $this->assertIsInt($assistant['indexedPassages']);
    }

    public function test_it_says_when_the_assistant_has_degraded(): void
    {
        config(['ai.budget.enabled' => true, 'ai.budget.daily_requests' => 1]);
        $this->actingAsRole('superAdmin');

        // Spend the day's allowance.
        app(AiBudget::class)->claim();

        $this->getJson('/api/v1/admin/system-health')
            ->assertOk()
            ->assertJsonPath('data.assistant.mode', 'knowledge_only');
    }

    public function test_a_non_admin_cannot_reach_system_health(): void
    {
        $this->actingAsRole('student');

        $this->getJson('/api/v1/admin/system-health')->assertStatus(403);
    }
}
