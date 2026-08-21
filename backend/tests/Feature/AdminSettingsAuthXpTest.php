<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Real, admin-configurable auth/XP settings (docs/EKSIKLER.md admin
// §13) — both genuinely enforced server-side, not just displayed.
class AdminSettingsAuthXpTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowed_domains_default_to_arucad_when_unconfigured(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/v1/admin/settings/auth');

        $response->assertOk();
        $this->assertEquals(['@arucad.edu.tr'], $response->json('data.allowedDomains'));
    }

    public function test_admin_can_widen_the_allowed_domains_and_it_is_really_enforced_at_sign_in(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/settings/auth', ['allowedDomains' => ['@arucad.edu.tr', '@partner.edu.tr']])
            ->assertOk();

        // A real sign-in from the newly-allowed domain now succeeds...
        $this->postJson('/api/v1/auth/session', ['email' => 'student@partner.edu.tr', 'name' => 'Partner Student'])
            ->assertOk();
        // ...and an email from neither domain still fails, same as before.
        $this->postJson('/api/v1/auth/session', ['email' => 'nope@somewhere-else.com', 'name' => 'Nope'])
            ->assertStatus(400);
    }

    public function test_entra_client_secret_is_write_only_and_never_echoed_back(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/settings/auth', ['entraClientSecret' => 'super-secret-value'])
            ->assertOk();

        $response = $this->getJson('/api/v1/admin/settings/auth');
        $response->assertOk();
        $this->assertTrue($response->json('data.entraClientSecretConfigured'));
        $this->assertStringNotContainsString('super-secret-value', $response->getContent());
    }

    public function test_admin_can_change_the_default_checkin_xp_and_checkin_actually_grants_that_amount(): void
    {
        $me = $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/settings/xp', ['checkinXp' => 25])->assertOk();

        $place = \App\Models\Place::create([
            'id' => 'place-xp-test', 'name' => 'XP Test Place', 'category' => 'Test',
            'lat' => 10.0, 'lng' => 20.0, 'density' => 'quiet',
        ]);

        $response = $this->postJson('/api/v1/checkins', ['placeId' => $place->id, 'lat' => 10.0, 'lng' => 20.0]);
        $response->assertOk();
        $this->assertEquals(25, $me->fresh()->xp);
    }
}
