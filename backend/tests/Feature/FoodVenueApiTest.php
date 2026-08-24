<?php

namespace Tests\Feature;

use App\Models\FoodDailyMenu;
use App\Models\FoodVenue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FoodVenueApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_student_can_read_food_venues_with_daily_menus(): void
    {
        $this->actingAsRole();
        $this->postJson('/api/v1/admin/food-venues', [
            'id' => 'food-garden',
            'name' => 'The Garden',
            'hours' => '08:00–20:00',
        ])->assertOk();
        $this->postJson('/api/v1/admin/food-venues/food-garden/menus', [
            'date' => '2026-08-23',
            'items' => ['Mercimek Çorbası', 'Pilav'],
            'price' => '85₺',
            'hours' => '11:00–14:00',
        ])->assertOk();

        $this->actingAsUser();
        $response = $this->getJson('/api/v1/food-venues')->assertOk();
        $venue = collect($response->json('data'))->firstWhere('id', 'food-garden');

        $this->assertNotNull($venue);
        $this->assertSame('The Garden', $venue['name']);
        $this->assertSame('08:00–20:00', $venue['hours']);
        $this->assertArrayHasKey('menuFileUrl', $venue);
        $this->assertSame('2026-08-23', $venue['dailyMenus'][0]['date']);
        $this->assertSame(['Mercimek Çorbası', 'Pilav'], $venue['dailyMenus'][0]['items']);
        $this->assertSame('85₺', $venue['dailyMenus'][0]['price']);
    }

    public function test_admin_can_create_update_and_delete_a_food_venue(): void
    {
        $this->actingAsRole();

        $this->postJson('/api/v1/admin/food-venues', [
            'id' => 'food-cafe',
            'name' => 'Test Cafe',
            'hours' => '09:00–17:00',
        ])->assertOk();
        $this->assertDatabaseHas('food_venues', ['id' => 'food-cafe', 'name' => 'Test Cafe']);

        $this->postJson('/api/v1/admin/food-venues', [
            'id' => 'food-cafe',
            'name' => 'Renamed Cafe',
            'hours' => '10:00–18:00',
        ])->assertOk();
        $this->assertDatabaseHas('food_venues', ['id' => 'food-cafe', 'name' => 'Renamed Cafe']);

        $this->postJson('/api/v1/admin/food-venues/food-cafe/delete')->assertOk();
        $this->assertDatabaseMissing('food_venues', ['id' => 'food-cafe']);
    }

    public function test_admin_can_upsert_and_delete_a_daily_menu_without_changing_its_id(): void
    {
        $this->actingAsRole();
        $this->postJson('/api/v1/admin/food-venues', [
            'id' => 'food-garden', 'name' => 'The Garden',
        ])->assertOk();

        $this->postJson('/api/v1/admin/food-venues/food-garden/menus', [
            'date' => '2026-08-23',
            'items' => ['Çorba'],
            'price' => '70₺',
        ])->assertOk();
        $firstId = FoodDailyMenu::where('food_venue_id', 'food-garden')->value('id');

        $this->postJson('/api/v1/admin/food-venues/food-garden/menus', [
            'date' => '2026-08-23',
            'items' => ['Çorba', 'Pilav'],
            'price' => '85₺',
        ])->assertOk();
        $this->assertSame($firstId, FoodDailyMenu::where('food_venue_id', 'food-garden')->value('id'));
        $this->assertEquals(['Çorba', 'Pilav'], FoodDailyMenu::where('food_venue_id', 'food-garden')->value('items'));

        $this->postJson('/api/v1/admin/food-venues/food-garden/menus/2026-08-23/delete')->assertOk();
        $this->assertDatabaseMissing('food_daily_menus', ['food_venue_id' => 'food-garden']);
    }

    public function test_a_student_cannot_write_food_venues(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/admin/food-venues', [
            'id' => 'food-x', 'name' => 'Nope',
        ])->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_a_content_editor_can_write_food_venues_but_a_moderator_cannot(): void
    {
        $this->actingAsRole('contentEditor');
        $this->postJson('/api/v1/admin/food-venues', [
            'id' => 'food-editor', 'name' => 'Editor Cafe',
        ])->assertOk();
        $this->assertDatabaseHas('food_venues', ['id' => 'food-editor']);

        $this->actingAsRole('moderator');
        $this->postJson('/api/v1/admin/food-venues', [
            'id' => 'food-mod', 'name' => 'Nope',
        ])->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        $this->postJson('/api/v1/admin/food-venues/food-editor/delete')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertDatabaseHas('food_venues', ['id' => 'food-editor']);
    }

    public function test_unauthenticated_read_and_write_are_401(): void
    {
        $this->getJson('/api/v1/food-venues')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
        $this->postJson('/api/v1/admin/food-venues', ['id' => 'food-x', 'name' => 'Nope'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
    }

    public function test_food_venue_upsert_requires_id_and_name(): void
    {
        $this->actingAsRole();

        $this->postJson('/api/v1/admin/food-venues', ['id' => 'food-x'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION');
    }
}
