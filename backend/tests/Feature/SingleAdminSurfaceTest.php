<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SingleAdminSurfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_filament_registers_only_the_admin_management_panel(): void
    {
        $this->assertArrayHasKey('admin', Filament::getPanels());
        $this->assertArrayNotHasKey('trainer', Filament::getPanels());
    }

    public function test_no_trainer_web_or_api_routes_are_exposed(): void
    {
        $uris = collect(Route::getRoutes())->map(fn ($route) => $route->uri());

        $this->assertFalse($uris->contains('trainer'));
        $this->assertFalse($uris->contains(fn (string $uri) => str_starts_with($uri, 'trainer/')));
        $this->assertFalse($uris->contains(fn (string $uri) => str_starts_with($uri, 'api/v1/trainer/')));
    }
}
