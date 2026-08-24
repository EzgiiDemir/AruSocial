<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteSettingsSecretTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_wordpress_token_is_usable_server_side_and_never_echoed(): void
    {
        $this->actingAsRole('superAdmin');
        $secret = 'wp-secret-token-xyz';

        $write = $this->postJson('/api/v1/admin/settings/site', [
            'wordpress' => [
                'siteUrl' => 'https://cms.example.com',
                'apiToken' => $secret,
            ],
        ])->assertOk();

        $this->assertSame($secret, AppSetting::getValue('wordpress.apiToken'));
        $this->assertTrue($write->json('data.wordpress.apiTokenConfigured'));
        $this->assertArrayNotHasKey('apiToken', $write->json('data.wordpress'));
        $this->assertStringNotContainsString($secret, $write->getContent());

        $read = $this->getJson('/api/v1/admin/settings/site')->assertOk();
        $this->assertTrue($read->json('data.wordpress.apiTokenConfigured'));
        $this->assertArrayNotHasKey('apiToken', $read->json('data.wordpress'));
        $this->assertStringNotContainsString($secret, $read->getContent());

        foreach (AdminAuditLog::all() as $row) {
            $this->assertStringNotContainsString($secret, $row->actor_name);
            $this->assertStringNotContainsString($secret, $row->action);
            $this->assertStringNotContainsString($secret, $row->target_type);
            $this->assertStringNotContainsString($secret, $row->target_label);
        }
    }
}
