<?php

namespace Tests\Feature;

use App\Models\RoleAssignment;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FilamentPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(?string $role, string $email): User
    {
        $user = User::create(['name' => 'Panel User', 'email' => $email, 'password' => bcrypt('x')]);

        if ($role !== null) {
            RoleAssignment::create([
                'email' => $email, 'role' => $role, 'permissions' => [],
                'assigned_by' => 'test', 'assigned_at' => now(),
            ]);
        }

        return $user;
    }

    /** @return array<string, array{string, bool}> */
    public static function roles(): array
    {
        return [
            'student' => ['student', false],
            'content editor' => ['contentEditor', true],
            'moderator' => ['moderator', true],
            'club manager' => ['clubManager', true],
            'trainer' => ['trainer', true],
            'super admin' => ['superAdmin', true],
        ];
    }

    #[DataProvider('roles')]
    public function test_only_authorized_roles_reach_the_single_admin_panel(string $role, bool $allowed): void
    {
        $user = $this->userWithRole($role, str_replace(' ', '', $role).'@arucad.edu.tr');

        $this->assertSame($allowed, $user->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_only_admin_panel_is_registered(): void
    {
        $this->assertArrayHasKey('admin', Filament::getPanels());
        $this->assertArrayNotHasKey('trainer', Filament::getPanels());
    }

    public function test_signed_out_visitor_reaches_only_admin_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/trainer')->assertNotFound();
        $this->get('/trainer/login')->assertNotFound();
    }

    public function test_student_is_refused_but_trainer_reaches_the_single_admin_panel(): void
    {
        $student = $this->userWithRole('student', 'student@arucad.edu.tr');
        $this->actingAs($student)->get('/admin')->assertForbidden();

        auth()->logout();

        $trainer = $this->userWithRole('trainer', 'trainer@arucad.edu.tr');
        $this->actingAs($trainer)->get('/admin')->assertOk();
    }

    public function test_unknown_panel_is_refused(): void
    {
        $admin = $this->userWithRole('superAdmin', 'super@arucad.edu.tr');
        $this->assertFalse($admin->canAccessPanel(Panel::make()->id('reports')));
    }
}
