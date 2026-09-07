<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemHealthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_real_configuration_status_for_a_super_admin(): void
    {
        config([
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

    public function test_a_non_admin_cannot_reach_system_health(): void
    {
        $this->actingAsRole('student');

        $this->getJson('/api/v1/admin/system-health')->assertStatus(403);
    }
}
