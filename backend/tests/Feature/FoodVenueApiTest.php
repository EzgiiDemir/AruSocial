<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Real bug fix (FAZ 6A §6): re-editing an already-set day's menu used to
// crash — updateOrCreate()'s match clause compared a plain "YYYY-MM-DD"
// string against menu_date's actually-stored "YYYY-MM-DD 00:00:00" value,
// never matched, and tried to INSERT a duplicate that then hit the real
// (food_venue_id, menu_date) unique constraint. Never caught before
// because the Flutter app never actually called this endpoint until now.
class FoodVenueApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_upserting_the_same_day_twice_updates_instead_of_crashing(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/food-venues', ['id' => 'venue-1', 'name' => 'Test Cafe'])
            ->assertOk();

        $this->postJson('/api/v1/admin/food-venues/venue-1/menus', [
            'date' => '2026-08-21', 'items' => ['Çorba'], 'price' => '50₺',
        ])->assertOk();

        // Re-editing the same day must UPDATE the existing row, not crash.
        $response = $this->postJson('/api/v1/admin/food-venues/venue-1/menus', [
            'date' => '2026-08-21', 'items' => ['Çorba', 'Salata'], 'price' => '60₺',
        ]);
        $response->assertOk();

        $this->assertDatabaseCount('food_daily_menus', 1);
        $menu = $response->json('data.dailyMenus.0');
        $this->assertEquals(['Çorba', 'Salata'], $menu['items']);
        $this->assertEquals('60₺', $menu['price']);
    }

    public function test_deleting_a_days_menu_actually_removes_it(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/food-venues', ['id' => 'venue-1', 'name' => 'Test Cafe'])->assertOk();
        $this->postJson('/api/v1/admin/food-venues/venue-1/menus', [
            'date' => '2026-08-21', 'items' => ['Çorba'],
        ])->assertOk();

        $this->postJson('/api/v1/admin/food-venues/venue-1/menus/2026-08-21/delete')->assertOk();

        $this->assertDatabaseCount('food_daily_menus', 0);
    }
}
