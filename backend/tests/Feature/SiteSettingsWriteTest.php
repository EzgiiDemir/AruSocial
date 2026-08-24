<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteSettingsWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_super_admin_persists_public_fields(): void
    {
        $this->actingAsRole('superAdmin');

        $this->postJson('/api/v1/admin/settings/site', [
            'entra' => [
                'tenantId' => 'tenant-1',
                'clientId' => 'client-1',
                'redirectUri' => 'app://redirect',
            ],
            'wordpress' => [
                'siteUrl' => 'https://cms.example.com',
            ],
        ])->assertOk()
            ->assertJsonPath('data.entra.tenantId', 'tenant-1')
            ->assertJsonPath('data.wordpress.siteUrl', 'https://cms.example.com')
            ->assertJsonPath('data.wordpress.apiTokenConfigured', false);

        $this->assertSame('tenant-1', AppSetting::getValue('entra.tenantId'));
        $this->assertSame('client-1', AppSetting::getValue('entra.clientId'));
        $this->assertSame('app://redirect', AppSetting::getValue('entra.redirectUri'));
        $this->assertSame('https://cms.example.com', AppSetting::getValue('wordpress.siteUrl'));

        $this->getJson('/api/v1/admin/settings/site')
            ->assertOk()
            ->assertJsonPath('data.entra.clientId', 'client-1')
            ->assertJsonPath('data.wordpress.siteUrl', 'https://cms.example.com');
    }

    public function test_omitting_the_token_leaves_the_stored_secret_in_place(): void
    {
        AppSetting::setValue('wordpress.apiToken', 'keep-me');
        $this->actingAsRole('superAdmin');

        $this->postJson('/api/v1/admin/settings/site', [
            'wordpress' => ['siteUrl' => 'https://new.example.com'],
        ])->assertOk()->assertJsonPath('data.wordpress.apiTokenConfigured', true);

        $this->assertSame('keep-me', AppSetting::getValue('wordpress.apiToken'));
        $this->assertSame('https://new.example.com', AppSetting::getValue('wordpress.siteUrl'));
    }

    public function test_an_empty_token_clears_the_stored_secret(): void
    {
        AppSetting::setValue('wordpress.apiToken', 'wipe-me');
        $this->actingAsRole('superAdmin');

        $this->postJson('/api/v1/admin/settings/site', [
            'wordpress' => ['apiToken' => ''],
        ])->assertOk()->assertJsonPath('data.wordpress.apiTokenConfigured', false);

        $this->assertNull(AppSetting::getValue('wordpress.apiToken'));
    }

    public function test_a_content_editor_cannot_write_site_settings(): void
    {
        $this->actingAsRole('contentEditor');

        $this->postJson('/api/v1/admin/settings/site', [
            'entra' => ['tenantId' => 'nope'],
        ])->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertNull(AppSetting::getValue('entra.tenantId'));
    }

    public function test_a_moderator_cannot_write_site_settings(): void
    {
        $this->actingAsRole('moderator');

        $this->getJson('/api/v1/admin/settings/moderation')->assertOk();
        $this->postJson('/api/v1/admin/settings/site', [
            'wordpress' => ['siteUrl' => 'https://nope.example.com'],
        ])->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_a_student_cannot_write_site_settings(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/admin/settings/site', [
            'entra' => ['tenantId' => 'nope'],
        ])->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_unauthenticated_read_and_write_are_401(): void
    {
        $this->getJson('/api/v1/admin/settings/site')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
        $this->postJson('/api/v1/admin/settings/site', ['entra' => ['tenantId' => 'x']])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
    }

    public function test_an_individual_users_manage_grant_can_write_site_settings(): void
    {
        $this->actingAsRole('contentEditor', ['users.manage']);

        $this->postJson('/api/v1/admin/settings/site', [
            'entra' => ['tenantId' => 'granted-tenant'],
        ])->assertOk()->assertJsonPath('data.entra.tenantId', 'granted-tenant');
    }
}
