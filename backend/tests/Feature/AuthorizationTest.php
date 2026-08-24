<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Authentication answered "who are you"; this answers "and may you". Until
// now any signed-in student could call every /admin/* endpoint, because the
// only thing standing in the way was the Flutter UI hiding the buttons.
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** Every admin surface, with the permission it should demand. */
    public static function adminEndpoints(): array
    {
        return [
            ['GET', '/api/v1/admin/stats'],
            ['GET', '/api/v1/admin/audit-log'],
            ['GET', '/api/v1/admin/reports'],
            ['GET', '/api/v1/admin/settings/moderation'],
            ['GET', '/api/v1/admin/settings/site'],
            ['POST', '/api/v1/admin/settings/site'],
            ['POST', '/api/v1/admin/events'],
            ['GET', '/api/v1/admin/events/pending'],
            ['POST', '/api/v1/admin/clubs'],
            ['POST', '/api/v1/admin/sports'],
            ['POST', '/api/v1/admin/services'],
            ['POST', '/api/v1/admin/food-venues'],
            ['POST', '/api/v1/admin/directory'],
            ['POST', '/api/v1/admin/pages'],
            ['GET', '/api/v1/admin/surveys'],
            ['POST', '/api/v1/admin/academic-years'],
            ['GET', '/api/v1/admin/email-logs'],
            ['POST', '/api/v1/admin/email/bulk'],
            ['GET', '/api/v1/admin/roles'],
            ['POST', '/api/v1/admin/roles'],
            ['GET', '/api/v1/media'],
        ];
    }

    public function test_a_signed_in_student_is_refused_every_admin_endpoint(): void
    {
        $this->actingAsUser();

        foreach (self::adminEndpoints() as [$method, $uri]) {
            $response = $this->json($method, $uri);

            $response->assertStatus(403, "$method $uri must refuse a student");
            $this->assertEquals('FORBIDDEN', $response->json('error.code'), $uri);
            $this->assertNull($response->json('data'), $uri);
            $this->assertStringStartsWith('req-', $response->json('meta.request_id'), $uri);
        }
    }

    // 401 and 403 are different answers to different questions, and the
    // distinction has to survive: "no token" must not look like "wrong
    // person", or a token that would work is indistinguishable from one
    // that wouldn't.
    public function test_no_token_is_a_401_not_a_403(): void
    {
        $this->getJson('/api/v1/admin/stats')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
    }

    public function test_a_super_admin_reaches_every_admin_endpoint(): void
    {
        $this->actingAsRole('superAdmin');

        foreach (self::adminEndpoints() as [$method, $uri]) {
            $this->assertNotEquals(403, $this->json($method, $uri)->status(),
                "$method $uri must not refuse a super admin");
        }
    }

    public function test_a_content_editor_manages_content_but_not_roles(): void
    {
        $this->actingAsRole('contentEditor');

        $this->postJson('/api/v1/admin/pages', ['id' => 'p1', 'slug' => 'x', 'title' => 'X'])
            ->assertOk();
        $this->getJson('/api/v1/admin/stats')->assertOk();

        // Roles are the super-admin bucket: they're how someone would grant
        // themselves everything else.
        $this->getJson('/api/v1/admin/roles')->assertStatus(403);
        $this->postJson('/api/v1/admin/roles', ['email' => 'x@arucad.edu.tr', 'role' => 'superAdmin'])
            ->assertStatus(403);
    }

    public function test_a_moderator_moderates_but_does_not_edit_content(): void
    {
        $this->actingAsRole('moderator');

        $this->getJson('/api/v1/admin/reports')->assertOk();
        $this->getJson('/api/v1/admin/settings/moderation')->assertOk();

        $this->postJson('/api/v1/admin/events', ['id' => 'e1', 'title' => 'X'])->assertStatus(403);
        $this->postJson('/api/v1/admin/pages', ['id' => 'p1', 'slug' => 'x', 'title' => 'X'])
            ->assertStatus(403);
    }

    public function test_a_content_editor_does_not_inherit_moderation(): void
    {
        $this->actingAsRole('contentEditor');

        $this->getJson('/api/v1/admin/reports')->assertStatus(403);
    }

    // The per-person column exists so one individual can be given one extra
    // section without inventing a role for them.
    public function test_an_individual_grant_opens_exactly_one_extra_section(): void
    {
        $this->actingAsRole('moderator', ['events.manage']);

        $this->postJson('/api/v1/admin/events', ['id' => 'e1', 'title' => 'Moderatörün etkinliği'])
            ->assertOk();

        // ...and nothing beyond it.
        $this->postJson('/api/v1/admin/pages', ['id' => 'p1', 'slug' => 'x', 'title' => 'X'])
            ->assertStatus(403);
        $this->getJson('/api/v1/admin/roles')->assertStatus(403);
    }

    public function test_an_individual_grant_never_removes_what_the_role_already_gives(): void
    {
        // An override list that doesn't mention moderation at all must not
        // take moderation away from a moderator — this layer only adds.
        $this->actingAsRole('moderator', ['events.manage']);

        $this->getJson('/api/v1/admin/reports')->assertOk();
    }

    public function test_an_unrecognised_granted_key_grants_nothing(): void
    {
        $this->actingAsRole('student', ['everything.manage', 'admin']);

        $this->getJson('/api/v1/admin/stats')->assertStatus(403);
    }

    // Removing someone's role assignment has to actually remove their
    // access — otherwise "revoke" is only a display change.
    public function test_revoking_the_role_assignment_revokes_the_access(): void
    {
        $admin = $this->actingAsRole('superAdmin');
        $this->getJson('/api/v1/admin/stats')->assertOk();

        RoleAssignment::find($admin->email)->delete();

        $this->getJson('/api/v1/admin/stats')->assertStatus(403);
    }

    public function test_a_student_may_read_its_own_role_but_not_anyone_elses(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@arucad.edu.tr', 'password' => bcrypt('x'),
        ]);
        RoleAssignment::create([
            'email' => $admin->email, 'role' => 'superAdmin',
            'assigned_by' => 'seed', 'assigned_at' => now(),
        ]);
        $student = $this->actingAsUser();

        // This is the lookup every account makes right after signing in.
        $this->getJson("/api/v1/admin/roles/{$student->email}")
            ->assertOk()
            ->assertJsonPath('data.role', null);

        // But it must not double as a roster of who the admins are.
        $this->getJson("/api/v1/admin/roles/{$admin->email}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_a_super_admin_may_look_up_anyone(): void
    {
        RoleAssignment::create([
            'email' => 'baskan@arucad.edu.tr', 'role' => 'clubManager',
            'assigned_by' => 'seed', 'assigned_at' => now(),
        ]);
        $this->actingAsRole('superAdmin');

        $this->getJson('/api/v1/admin/roles/baskan@arucad.edu.tr')
            ->assertOk()
            ->assertJsonPath('data.role', 'clubManager');
    }

    // A ban is a property of the account, so it can't be escaped by picking
    // a different URL — there used to be a blanket /admin/* exemption here.
    public function test_a_ban_blocks_admin_routes_too(): void
    {
        $admin = $this->actingAsRole('superAdmin');
        $this->getJson('/api/v1/admin/stats')->assertOk();

        $admin->update(['banned_at' => now()]);

        $this->getJson('/api/v1/admin/stats')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_BANNED');
        $this->getJson('/api/v1/feed')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_BANNED');
    }

    public function test_a_banned_student_is_blocked_on_protected_endpoints(): void
    {
        $this->actingAsUser()->update(['banned_at' => now()]);

        $this->getJson('/api/v1/feed')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_BANNED');
    }

    public function test_diagnostics_need_neither_a_token_nor_a_role(): void
    {
        $this->getJson('/api/v1/health')->assertOk();
        $this->getJson('/api/v1')->assertOk();
    }

    // Authorization must not have quietly closed the student app.
    public function test_a_plain_student_still_uses_the_student_app(): void
    {
        $this->actingAsUser();
        Event::create(['id' => 'e1', 'title' => 'Açık', 'time' => '10:00', 'place_name' => 'X', 'category' => 'C']);

        $this->getJson('/api/v1/me')->assertOk();
        $this->getJson('/api/v1/feed')->assertOk();
        $this->getJson('/api/v1/events')->assertOk();
        $this->postJson('/api/v1/events/e1/join')->assertOk();
        $this->getJson('/api/v1/leaderboard')->assertOk();
        $this->getJson('/api/v1/notifications')->assertOk();
    }

    public function test_me_reports_the_role_that_is_actually_enforced(): void
    {
        $user = $this->actingAsUser();

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.role', 'student');

        RoleAssignment::create([
            'email' => $user->email, 'role' => 'moderator',
            'assigned_by' => 'test', 'assigned_at' => now(),
        ]);

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.role', 'moderator');
    }
}
