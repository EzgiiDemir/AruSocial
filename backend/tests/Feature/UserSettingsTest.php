<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_match_the_previous_client_defaults(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/v1/me/settings')
            ->assertOk()
            ->assertJsonPath('data.locationVisibility', 'ghost')
            ->assertJsonPath('data.nearbyDiscoverable', false)
            ->assertJsonPath('data.checkInVisible', true)
            ->assertJsonPath('data.personalization', true)
            ->assertJsonPath('data.isPrivateProfile', false)
            ->assertJsonPath('data.preferredLanguage', 'TR');
    }

    public function test_updating_settings_persists_them(): void
    {
        $this->actingAsUser();

        $data = $this->postJson('/api/v1/me/settings', [
            'locationVisibility' => 'friends',
            'nearbyDiscoverable' => true,
            'checkInVisible' => false,
            'personalization' => false,
            'isPrivateProfile' => true,
            'preferredLanguage' => 'EN',
        ])->assertOk()->json('data');

        $this->assertEquals('friends', $data['locationVisibility']);
        $this->assertTrue($data['nearbyDiscoverable']);
        $this->assertFalse($data['checkInVisible']);
        $this->assertFalse($data['personalization']);
        $this->assertTrue($data['isPrivateProfile']);
        $this->assertEquals('EN', $data['preferredLanguage']);
        $this->assertEquals('friends', $this->getJson('/api/v1/me/settings')->json('data.locationVisibility'));
    }

    public function test_omitted_keys_keep_their_previous_value(): void
    {
        $this->actingAsUser();
        $this->postJson('/api/v1/me/settings', [
            'locationVisibility' => 'public',
            'checkInVisible' => false,
        ])->assertOk();

        $data = $this->postJson('/api/v1/me/settings', ['personalization' => false])
            ->assertOk()->json('data');

        $this->assertEquals('public', $data['locationVisibility']);
        $this->assertFalse($data['checkInVisible']);
        $this->assertFalse($data['personalization']);
        $this->assertFalse($data['nearbyDiscoverable']);
    }

    public function test_unknown_visibility_is_validation(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/me/settings', ['locationVisibility' => 'everyone'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION');
    }

    public function test_settings_are_isolated_per_account(): void
    {
        $other = User::create(['name' => 'Other', 'email' => 'other@arucad.edu.tr', 'password' => bcrypt('x')]);
        $this->actingAsUser($other);
        $this->postJson('/api/v1/me/settings', ['locationVisibility' => 'public'])->assertOk();

        $this->actingAsUser();
        $this->assertEquals('ghost', $this->getJson('/api/v1/me/settings')->json('data.locationVisibility'));
    }

    public function test_settings_require_a_signed_in_account(): void
    {
        $this->getJson('/api/v1/me/settings')->assertStatus(401)->assertJsonPath('error.code', 'AUTH_REQUIRED');
        $this->postJson('/api/v1/me/settings', ['locationVisibility' => 'friends'])
            ->assertStatus(401)->assertJsonPath('error.code', 'AUTH_REQUIRED');
    }
}
