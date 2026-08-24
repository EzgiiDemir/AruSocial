<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormRequestWriteValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_food_venue_upsert_missing_fields_keeps_envelope_400(): void
    {
        $this->actingAsRole();

        $this->postJson('/api/v1/admin/food-venues', ['id' => 'cafe-1'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION')
            ->assertJsonPath('error.message', 'id and name are required.');
    }

    public function test_session_missing_credentials_keeps_envelope_422(): void
    {
        $this->postJson('/api/v1/auth/session', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION')
            ->assertJsonPath('error.message', 'E-posta ve şifre zorunludur.');
    }

    public function test_club_upsert_missing_fields_keeps_envelope_400(): void
    {
        $this->actingAsRole();

        $this->postJson('/api/v1/admin/clubs', [])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION')
            ->assertJsonPath('error.message', 'id and name are required.');
    }
}
