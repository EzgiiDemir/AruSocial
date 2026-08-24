<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteSettingsReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_super_admin_reads_public_site_settings_without_secrets(): void
    {
        AppSetting::setValue('entra.tenantId', 'tenant-public');
        AppSetting::setValue('entra.clientId', 'client-public');
        AppSetting::setValue('entra.redirectUri', 'app://oauth');
        AppSetting::setValue('wordpress.siteUrl', 'https://www.arucad.edu.tr');
        AppSetting::setValue('wordpress.apiToken', 'wp-secret-must-not-leak');
        AppSetting::setValue('moderation.apiKey', 'sk-moderation-must-not-leak');

        $this->actingAsRole('superAdmin');

        $response = $this->getJson('/api/v1/admin/settings/site')->assertOk();
        $data = $response->json('data');

        $this->assertSame('tenant-public', $data['entra']['tenantId']);
        $this->assertSame('client-public', $data['entra']['clientId']);
        $this->assertSame('app://oauth', $data['entra']['redirectUri']);
        $this->assertSame('https://www.arucad.edu.tr', $data['wordpress']['siteUrl']);
        $this->assertTrue($data['wordpress']['apiTokenConfigured']);
        $this->assertSame(['tenantId', 'clientId', 'redirectUri'], array_keys($data['entra']));
        $this->assertSame(['siteUrl', 'apiTokenConfigured'], array_keys($data['wordpress']));
        $this->assertArrayNotHasKey('apiToken', $data['wordpress']);
        $this->assertArrayNotHasKey('apiKey', $data);
        $this->assertStringNotContainsString('wp-secret-must-not-leak', $response->getContent());
        $this->assertStringNotContainsString('sk-moderation-must-not-leak', $response->getContent());
    }

    public function test_unconfigured_settings_return_empty_public_fields(): void
    {
        $this->actingAsRole('superAdmin');

        $this->getJson('/api/v1/admin/settings/site')
            ->assertOk()
            ->assertJsonPath('data.entra.tenantId', '')
            ->assertJsonPath('data.entra.clientId', '')
            ->assertJsonPath('data.entra.redirectUri', '')
            ->assertJsonPath('data.wordpress.siteUrl', '')
            ->assertJsonPath('data.wordpress.apiTokenConfigured', false);
    }

    public function test_a_signed_in_student_cannot_read_site_settings(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/v1/admin/settings/site')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }
}
