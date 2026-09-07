<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditSecretTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_rows_do_not_contain_wordpress_or_moderation_secrets(): void
    {
        $this->actingAsRole('superAdmin');
        $wpToken = 'wp-audit-secret-token';
        $moderationKey = 'moderation-audit-secret-key';

        $this->postJson('/api/v1/admin/settings/site', [
            'wordpress' => [
                'siteUrl' => 'https://cms.example.com',
                'apiToken' => $wpToken,
            ],
        ])->assertOk();
        $this->assertSame($wpToken, AppSetting::getValue('wordpress.apiToken'));

        $this->postJson('/api/v1/admin/settings/moderation', [
            'apiKey' => $moderationKey,
        ])->assertOk()
            ->assertJsonPath('data.mode', 'local_review');

        // The moderation workflow is intentionally self-hosted.  Legacy
        // provider credentials must not be persisted even if a stale client
        // sends one with an old settings request.
        $this->assertNull(AppSetting::getValue('moderation.apiKey'));

        $this->assertGreaterThanOrEqual(2, AdminAuditLog::count());

        foreach (AdminAuditLog::all() as $row) {
            foreach ([$row->actor_name, $row->action, $row->target_type, $row->target_label] as $field) {
                $this->assertStringNotContainsString($wpToken, (string) $field);
                $this->assertStringNotContainsString($moderationKey, (string) $field);
            }
        }

        $read = $this->getJson('/api/v1/admin/audit-log')->assertOk();
        $this->assertStringNotContainsString($wpToken, $read->getContent());
        $this->assertStringNotContainsString($moderationKey, $read->getContent());
    }
}
