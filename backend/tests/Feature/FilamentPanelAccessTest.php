<?php

namespace Tests\Feature;

use App\Models\RoleAssignment;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who can open which panel.
 *
 * The Admin and Trainer panels are separate Filament panels, and the
 * requirement is that their permissions are "clearly separated and
 * enforced". Enforcement is one method — `User::canAccessPanel()` — which
 * defers to the same `GranularPermissions` the JSON API already uses, so
 * there is one answer to "is this person an admin" rather than two that
 * can drift.
 *
 * These are the tests that fail if someone widens a role bucket without
 * noticing that it also hands out a panel.
 */
class FilamentPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(?string $role, string $email): User
    {
        $user = User::create([
            'name' => 'Panel User',
            'email' => $email,
            'password' => bcrypt('x'),
        ]);

        if ($role !== null) {
            RoleAssignment::create([
                'email' => $email,
                'role' => $role,
                'permissions' => [],
                'assigned_by' => 'test',
                'assigned_at' => now(),
            ]);
        }

        return $user;
    }

    /** @return array<string, list<string|bool>> role => [admin?, trainer?] */
    public static function roles(): array
    {
        return [
            //                        role             admin  trainer
            'a student' => ['student', false, false],
            'a content editor' => ['contentEditor', true,  false],
            'a moderator' => ['moderator', true,  false],
            'a club manager' => ['clubManager', true,  false],
            'a trainer' => ['trainer', false, true],
            'a super admin' => ['superAdmin', true,  true],
        ];
    }

    #[DataProvider('roles')]
    public function test_each_role_reaches_only_its_own_panel(
        string $role,
        bool $admin,
        bool $trainer,
    ): void {
        $user = $this->userWithRole($role, str_replace(['.', ' '], '', $role).'@arucad.edu.tr');

        $this->assertSame($admin, $user->canAccessPanel(Filament::getPanel('admin')),
            "{$role} and the admin panel");
        $this->assertSame($trainer, $user->canAccessPanel(Filament::getPanel('trainer')),
            "{$role} and the trainer panel");
    }

    /**
     * The important negative: a trainer is not a small admin. They hold
     * `campusOps` for applications and appointments, which is a real
     * overlap with admin roles — so this asserts the *panel* stays shut
     * even though some permissions are shared.
     */
    public function test_a_trainer_cannot_open_the_admin_panel(): void
    {
        $trainer = $this->userWithRole('trainer', 'trainer@arucad.edu.tr');

        $this->assertFalse(
            $trainer->canAccessPanel(Filament::getPanel('admin')),
            'A trainer reached the admin panel.',
        );
    }

    /** No role assignment at all means no panel, not a default one. */
    public function test_an_account_with_no_role_assignment_reaches_nothing(): void
    {
        $user = $this->userWithRole(null, 'nobody@arucad.edu.tr');

        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('trainer')));
    }

    /** An unknown panel id is refused rather than defaulting to allowed. */
    public function test_an_unknown_panel_is_refused(): void
    {
        $admin = $this->userWithRole('superAdmin', 'super@arucad.edu.tr');
        $unknown = Panel::make()->id('reports');

        $this->assertFalse($admin->canAccessPanel($unknown));
    }

    // ---- the routes themselves ---------------------------------------

    public function test_both_panels_are_registered(): void
    {
        $this->assertNotNull(Filament::getPanel('admin'));
        $this->assertNotNull(Filament::getPanel('trainer'));
    }

    public function test_a_signed_out_visitor_is_sent_to_the_login_page(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/trainer')->assertRedirect('/trainer/login');
    }

    public function test_a_student_is_refused_at_the_panel_door(): void
    {
        $student = $this->userWithRole('student', 'student@arucad.edu.tr');

        $this->actingAs($student)->get('/admin')->assertForbidden();
        $this->actingAs($student)->get('/trainer')->assertForbidden();
    }

    public function test_a_trainer_is_refused_at_the_admin_door_over_http(): void
    {
        $trainer = $this->userWithRole('trainer', 'trainer2@arucad.edu.tr');

        $this->actingAs($trainer)->get('/admin')->assertForbidden();
        $this->actingAs($trainer)->get('/trainer')->assertSuccessful();
    }
}
