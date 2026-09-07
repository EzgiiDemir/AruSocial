<?php

namespace Tests\Feature;

use App\Models\StaffProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LaunchFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_login_cannot_claim_an_unverified_campus_email(): void
    {
        $this->app->instance('env', 'production');
        $this->postJson('/api/v1/auth/session', [
            'email' => 'unverified@arucad.edu.tr', 'password' => 'anything',
        ])->assertUnauthorized();
        $this->assertDatabaseMissing('users', ['email' => 'unverified@arucad.edu.tr']);
    }

    public function test_production_cannot_install_demo_accounts(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(\RuntimeException::class);
        (new \Database\Seeders\DatabaseSeeder)->run();
    }

    public function test_admin_links_a_teacher_by_email_and_teacher_can_enter_own_portal(): void
    {
        $teacher = $this->actingAsRole('trainer');
        $this->actingAsRole('superAdmin');
        $this->postJson('/api/v1/admin/staff', [
            'id' => 'teacher-launch', 'name' => 'Test Teacher',
            'email' => strtoupper($teacher->email), 'department' => 'Architecture',
            'active' => true, 'isDepartmentHead' => false,
        ])->assertCreated()->assertJsonPath('data.userId', (string) $teacher->id);
        $this->actingAsUser($teacher);
        $this->getJson('/api/v1/trainer/events')->assertOk();
        StaffProfile::where('id', 'teacher-launch')->update(['active' => false]);
        $this->getJson('/api/v1/trainer/events')->assertForbidden();
    }

    public function test_admin_cannot_link_a_nonexistent_user(): void
    {
        $this->actingAsRole('superAdmin');
        $this->postJson('/api/v1/admin/staff', [
            'name' => 'Test Teacher', 'userId' => 99999999,
        ])->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION');
    }

    public function test_directory_room_without_floor_and_its_tour_are_reachable(): void
    {
        $this->actingAsRole('superAdmin');
        $this->postJson('/api/v1/admin/directory', [
            'id' => 'room-launch', 'building' => 'Rodin', 'occupantName' => 'Reception',
            'tourUrl' => 'https://360.arucad.edu.tr/index.htm', 'tourTarget' => 'scene=12',
        ])->assertOk();
        $this->actingAsUser();
        $this->getJson('/api/v1/directory/buildings/Rodin/floors')
            ->assertOk()->assertJsonPath('data.0.name', 'Kat belirtilmemiş');
        $floor = rawurlencode('Kat belirtilmemiş');
        $this->getJson('/api/v1/directory/buildings/Rodin/floors/'.$floor.'/rooms')
            ->assertOk()->assertJsonPath('data.0.tourTarget', 'scene=12');
    }

    public function test_invalid_map_coordinates_and_executable_tour_urls_are_rejected(): void
    {
        $this->actingAsRole('superAdmin');
        $this->postJson('/api/v1/admin/places', [
            'id' => 'invalid', 'name' => 'Invalid', 'category' => 'Social',
            'lat' => 999, 'lng' => 999, 'tourUrl' => 'javascript:alert(1)',
        ])->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION');
    }
}
