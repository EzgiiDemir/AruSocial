<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WordpressVersionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_without_wordpress_config_is_honest_501(): void
    {
        $this->actingAsRole('superAdmin');
        AppSetting::setValue('wordpress.siteUrl', null);
        AppSetting::setValue('wordpress.apiToken', null);

        $this->postJson('/api/v1/admin/wordpress/versions')
            ->assertStatus(501)
            ->assertJsonPath('error.code', 'WORDPRESS_NOT_CONFIGURED');
    }

    public function test_manual_payload_creates_a_versioned_snapshot(): void
    {
        $this->actingAsRole('superAdmin');

        $this->postJson('/api/v1/admin/wordpress/versions', [
            'payload' => ['forms' => [['id' => 1, 'title' => 'Katılım']]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.unchanged', false);

        $again = $this->postJson('/api/v1/admin/wordpress/versions', [
            'payload' => ['forms' => [['id' => 1, 'title' => 'Katılım']]],
        ]);
        $again->assertOk()->assertJsonPath('data.unchanged', true);

        $this->getJson('/api/v1/admin/wordpress/versions')
            ->assertOk()
            ->assertJsonPath('data.0.version', 1);
    }
}
