<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Real route-level RBAC enforcement (docs/EKSIKLER.md "RBAC permission
// enforcement — roller var ama route bazında yetki kontrolü yok"): before
// EnsurePermission existed, every /admin/* route trusted any request that
// reached it — "hidden in the UI" was the only protection. These tests
// prove the API itself refuses an action a role isn't allowed to perform,
// not just that the Flutter UI hides the button.
class PermissionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plain_student_cannot_create_a_club(): void
    {
        $this->actingAsUser(role: 'student');

        $response = $this->postJson('/api/v1/admin/clubs', [
            'id' => 'club-1', 'name' => 'Test Club', 'category' => 'Sanat',
        ]);

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
        $this->assertDatabaseMissing('clubs', ['id' => 'club-1']);
    }

    public function test_an_account_with_no_role_assignment_at_all_defaults_to_student_and_is_denied(): void
    {
        // No RoleAssignment row for this email at all — must fail closed
        // (treated as 'student'), not open.
        $this->actingAsUser(email: 'nobody-special@arucad.edu.tr');

        $this->postJson('/api/v1/admin/events', ['id' => 'e1', 'title' => 'Sneaky'])
            ->assertStatus(403);
    }

    public function test_a_content_editor_can_manage_content_but_not_moderate(): void
    {
        $this->actingAsUser(role: 'contentEditor');

        $this->postJson('/api/v1/admin/clubs', [
            'id' => 'club-1', 'name' => 'Test Club', 'category' => 'Sanat',
        ])->assertOk();

        $this->getJson('/api/v1/admin/reports')->assertStatus(403);
    }

    public function test_a_moderator_can_moderate_but_not_manage_content(): void
    {
        $this->actingAsUser(role: 'moderator');

        $this->getJson('/api/v1/admin/reports')->assertOk();

        $this->postJson('/api/v1/admin/clubs', [
            'id' => 'club-1', 'name' => 'Test Club', 'category' => 'Sanat',
        ])->assertStatus(403);
    }

    public function test_only_superadmin_can_manage_site_settings_or_roles(): void
    {
        $this->actingAsUser(role: 'contentEditor');

        $this->postJson('/api/v1/admin/settings/moderation', ['apiKey' => 'sk-test'])
            ->assertStatus(403);
        $this->postJson('/api/v1/admin/roles', ['email' => 'x@arucad.edu.tr', 'role' => 'moderator'])
            ->assertStatus(403);

        $this->actingAsUser(email: 'admin2@arucad.edu.tr', role: 'superAdmin');
        $this->postJson('/api/v1/admin/settings/moderation', ['apiKey' => 'sk-test'])->assertOk();
    }

    public function test_any_authenticated_role_can_reach_the_shared_viewadmin_routes(): void
    {
        foreach (['contentEditor', 'moderator', 'superAdmin'] as $role) {
            $this->actingAsUser(email: "$role@arucad.edu.tr", role: $role);
            $this->getJson('/api/v1/admin/stats')->assertOk();
        }

        $this->actingAsUser(role: 'student');
        $this->getJson('/api/v1/admin/stats')->assertStatus(403);
    }

    public function test_every_signed_in_role_can_look_up_its_own_role_via_roleFor(): void
    {
        // Deliberately outside every permission gate — every account needs
        // this right after login (see routes/api.php).
        $this->actingAsUser(role: 'student');

        $this->getJson('/api/v1/admin/roles/test@arucad.edu.tr')->assertOk();
    }
}
