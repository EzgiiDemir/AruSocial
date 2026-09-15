<?php

namespace Tests\Feature;

use App\Filament\Trainer\Resources\Events\EventResource as TrainerEventResource;
use App\Models\Event;
use App\Models\RoleAssignment;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\GranularPermissions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The two panels are one product.
 *
 * The brief asks that Admin and Trainer share a design system, screen
 * structure, logo treatment and navigation, and differ only in what a
 * person is allowed to do. That is easy to satisfy on the day it is built
 * and easy to lose a month later, because nothing fails when one panel
 * quietly gains an option the other does not have.
 *
 * These compare the two panels' actual configuration rather than reading
 * the providers, so a difference is caught wherever it was introduced.
 */
class PanelParityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::firstOrCreate(
            ['email' => 'parity-admin@arucad.edu.tr'],
            ['name' => 'Parity Admin', 'password' => bcrypt('x')],
        );

        RoleAssignment::updateOrCreate(
            ['email' => $user->email],
            [
                'role' => GranularPermissions::SUPER_ROLE,
                'permissions' => null,
                'assigned_by' => 'test',
                'assigned_at' => now(),
            ],
        );

        return $user;
    }

    private function trainer(string $email = 'parity-trainer@arucad.edu.tr'): array
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => 'Parity Trainer', 'password' => bcrypt('x')],
        );

        RoleAssignment::updateOrCreate(
            ['email' => $email],
            ['role' => 'trainer', 'permissions' => null, 'assigned_by' => 'test', 'assigned_at' => now()],
        );

        $staff = StaffProfile::create([
            'id' => 'staff-'.Str::uuid(),
            'user_id' => $user->id,
            'name' => 'Parity Trainer',
            'email' => $email,
            'active' => true,
        ]);

        return [$user, $staff];
    }

    // ---- the shared design system ---------------------------------------

    public function test_both_panels_carry_the_same_logo(): void
    {
        $admin = Filament::getPanel('admin');
        $trainer = Filament::getPanel('trainer');

        $this->assertNotNull($admin->getBrandLogo(), 'The Admin panel has no logo.');
        $this->assertSame(
            $admin->getBrandLogo(),
            $trainer->getBrandLogo(),
            'The two panels show different branding.',
        );
    }

    public function test_both_panels_use_the_same_sidebar_groups(): void
    {
        $names = fn (string $id) => array_map(
            fn ($group) => $group->getLabel(),
            Filament::getPanel($id)->getNavigationGroups(),
        );

        $this->assertNotEmpty($names('admin'));
        $this->assertSame(
            $names('admin'),
            $names('trainer'),
            'The sidebars are organised differently, so the two panels are two products.',
        );
    }

    public function test_both_panels_share_the_same_layout_settings(): void
    {
        $admin = Filament::getPanel('admin');
        $trainer = Filament::getPanel('trainer');

        $this->assertSame(
            $admin->isSidebarCollapsibleOnDesktop(),
            $trainer->isSidebarCollapsibleOnDesktop(),
        );
        $this->assertSame($admin->getMaxContentWidth(), $trainer->getMaxContentWidth());
    }

    /**
     * The one difference that is supposed to exist. Someone holding both
     * roles has to be able to tell which panel they are in before they
     * change something.
     */
    public function test_the_panels_are_visibly_different_colours(): void
    {
        $this->assertNotSame(
            Filament::getPanel('admin')->getColors()['primary'],
            Filament::getPanel('trainer')->getColors()['primary'],
        );
    }

    // ---- the trainer panel actually has something in it ------------------

    public function test_the_trainer_panel_has_its_own_resources(): void
    {
        $resources = Filament::getPanel('trainer')->getResources();

        $this->assertNotEmpty(
            $resources,
            'The Trainer panel has no resources, so a department head has nothing to manage.',
        );
    }

    /**
     * Admin resources must not leak into the Trainer panel by being dropped
     * in the wrong folder — the separate discovery paths are the mechanism,
     * and this is the check that they still hold.
     */
    public function test_no_admin_resource_is_registered_in_the_trainer_panel(): void
    {
        foreach (Filament::getPanel('trainer')->getResources() as $resource) {
            $this->assertStringStartsWith(
                'App\\Filament\\Trainer\\',
                $resource,
                "{$resource} is an admin resource showing in the Trainer panel.",
            );
        }
    }

    // ---- row-level scoping ------------------------------------------------

    /**
     * The guarantee the whole Trainer panel rests on. A permission check
     * answers "may this person edit events" — yes — and would happily let
     * them edit somebody else's.
     */
    public function test_a_trainer_only_sees_their_own_events(): void
    {
        [$user, $staff] = $this->trainer();
        [, $otherStaff] = $this->trainer('other-trainer@arucad.edu.tr');

        Event::create([
            'id' => 'event-mine', 'title' => 'Mine', 'time' => '10:00',
            'place_name' => 'Studio', 'category' => 'social',
            'responsible_staff_id' => $staff->id,
        ]);
        Event::create([
            'id' => 'event-theirs', 'title' => 'Theirs', 'time' => '10:00',
            'place_name' => 'Studio', 'category' => 'social',
            'responsible_staff_id' => $otherStaff->id,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel('trainer');

        $visible = TrainerEventResource::getEloquentQuery()->pluck('id')->all();

        $this->assertSame(['event-mine'], $visible);
    }

    /**
     * Route binding runs through the same scope, so typing another
     * department's id into the URL finds nothing rather than a form.
     */
    public function test_a_trainer_cannot_reach_another_departments_event_by_id(): void
    {
        [$user, $staff] = $this->trainer();
        [, $otherStaff] = $this->trainer('other-trainer@arucad.edu.tr');

        Event::create([
            'id' => 'event-theirs', 'title' => 'Theirs', 'time' => '10:00',
            'place_name' => 'Studio', 'category' => 'social',
            'responsible_staff_id' => $otherStaff->id,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel('trainer');

        $this->assertNull(
            TrainerEventResource::getRecordRouteBindingEloquentQuery()
                ->find('event-theirs'),
            'A trainer reached another department\'s event by id.',
        );
        $this->assertNotNull($staff);
    }

    /**
     * A deactivated staff profile must see nothing, not everything. The
     * failure direction is the whole point.
     */
    public function test_a_trainer_without_an_active_profile_sees_nothing(): void
    {
        [$user, $staff] = $this->trainer();

        Event::create([
            'id' => 'event-mine', 'title' => 'Mine', 'time' => '10:00',
            'place_name' => 'Studio', 'category' => 'social',
            'responsible_staff_id' => $staff->id,
        ]);

        $staff->active = false;
        $staff->save();

        $this->actingAs($user);
        Filament::setCurrentPanel('trainer');

        $this->assertSame([], TrainerEventResource::getEloquentQuery()->pluck('id')->all());
        $this->assertFalse(TrainerEventResource::canViewAny());
    }

    public function test_an_admin_cannot_use_the_trainer_resource(): void
    {
        $this->actingAs($this->admin());
        Filament::setCurrentPanel('trainer');

        // A super admin holds every permission but has no staff profile, so
        // there is no department to scope to — and the resource says so
        // rather than showing them everything.
        $this->assertFalse(TrainerEventResource::canViewAny());
    }
}
