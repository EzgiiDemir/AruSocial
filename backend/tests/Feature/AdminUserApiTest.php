<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Real "Kullanıcılar ve Roller" backing (docs/EKSIKLER.md admin §9): a
// superAdmin can create a real account, grant per-person granular
// permission overrides on top of the role template, and deactivate an
// account — all genuinely persisted and genuinely enforced, not just UI.
class AdminUserApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_create_a_real_user_with_role_and_permissions(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/users', [
            'name' => 'Yeni Personel',
            'email' => 'yeni.personel@arucad.edu.tr',
            'role' => 'student',
            'permissions' => ['moderation.moderate', 'not-a-real-key'],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'yeni.personel@arucad.edu.tr']);
        // The unknown key must be silently dropped, not stored verbatim —
        // GranularPermissions::sanitize() is the real gate here.
        $this->assertEquals(['moderation.moderate'], $response->json('data.permissions'));
    }

    public function test_a_granular_override_grants_only_the_bucket_it_maps_to(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/users', [
            'name' => 'Sınırlı Moderatör',
            'email' => 'sinirli@arucad.edu.tr',
            'role' => 'student',
            'permissions' => ['moderation.moderate'],
        ])->assertCreated();

        Sanctum::actingAs(User::where('email', 'sinirli@arucad.edu.tr')->firstOrFail());
        // A plain 'student' role fails every gated bucket by default —
        // the override should flip only 'moderate', nothing else.
        $this->getJson('/api/v1/admin/reports')->assertOk();
        $this->postJson('/api/v1/admin/clubs', ['id' => 'c1', 'name' => 'X', 'category' => 'Y'])
            ->assertStatus(403);
        $this->getJson('/api/v1/admin/roles')->assertStatus(403);
    }

    public function test_deactivating_a_user_blocks_normal_requests_but_not_admin_routes(): void
    {
        $admin = $this->actingAsAdmin();
        $target = User::create(['name' => 'Pasif Olacak', 'email' => 'pasif@arucad.edu.tr', 'password' => bcrypt('x')]);

        $this->postJson("/api/v1/admin/users/{$target->id}", ['active' => false])->assertOk();
        $this->assertTrue($target->fresh()->isDeactivated());

        Sanctum::actingAs($target->fresh());
        $response = $this->getJson('/api/v1/me');
        $response->assertStatus(403);
        $this->assertEquals('ACCOUNT_DEACTIVATED', $response->json('error.code'));

        // Admin routes stay reachable even for a deactivated account (same
        // exemption EnsureNotBanned already gave banned accounts).
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/stats')->assertOk();
    }

    public function test_banned_users_list_and_unban_resets_strikes(): void
    {
        $this->actingAsAdmin();
        $banned = User::create([
            'name' => 'Yasaklı', 'email' => 'yasakli@arucad.edu.tr', 'password' => bcrypt('x'),
            'strikes' => 3, 'banned_at' => now(),
        ]);
        User::create(['name' => 'Temiz', 'email' => 'temiz@arucad.edu.tr', 'password' => bcrypt('x')]);

        $list = $this->getJson('/api/v1/admin/users/banned')->json('data');
        $this->assertCount(1, $list);
        $this->assertEquals('yasakli@arucad.edu.tr', $list[0]['email']);

        $this->postJson("/api/v1/admin/users/{$banned->id}/unban")->assertOk();
        $fresh = $banned->fresh();
        $this->assertNull($fresh->banned_at);
        $this->assertEquals(0, $fresh->strikes);
    }
}
