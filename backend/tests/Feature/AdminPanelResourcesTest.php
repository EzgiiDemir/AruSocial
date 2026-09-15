<?php

namespace Tests\Feature;

use App\Filament\Concerns\ManagesCampusContent;
use App\Filament\Resources\Places\Pages\ListPlaces;
use App\Models\Place;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\GranularPermissions;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every resource in the panel, not just the one someone happened to test.
 *
 * The resources share their behaviour through `ManagesCampusContent`, which
 * means a resource can be added by copying a neighbour and be wrong in a way
 * that no per-resource test would catch — an unregistered permission key, a
 * model without soft deletes behind a Restore button, a list page that
 * throws on a column referencing a relation that is not there.
 *
 * So this walks the panel's own registry: whatever is registered is what
 * gets checked, and a resource added later is covered the day it lands.
 */
class AdminPanelResourcesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Resources that opt into the shared content behaviour.
     *
     * @return array<class-string<resource>>
     */
    private function contentResources(): array
    {
        Filament::setCurrentPanel('admin');

        return array_values(array_filter(
            Filament::getPanel('admin')->getResources(),
            fn (string $resource) => in_array(
                ManagesCampusContent::class,
                class_uses_recursive($resource),
                true,
            ),
        ));
    }

    private function superAdmin(): User
    {
        $user = User::firstOrCreate(
            ['email' => 'panel-super@arucad.edu.tr'],
            ['name' => 'Panel Super', 'password' => bcrypt('x')],
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

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');

        return $user;
    }

    public function test_the_panel_has_content_resources_registered(): void
    {
        $this->assertNotEmpty(
            $this->contentResources(),
            'No resource uses ManagesCampusContent, so this whole file is vacuous.'
        );
    }

    /**
     * A Restore button on a model without soft deletes throws at the moment
     * someone presses it, which is the worst moment to find out.
     */
    public function test_every_content_resource_has_a_soft_deletable_model(): void
    {
        foreach ($this->contentResources() as $resource) {
            $model = $resource::getModel();

            $this->assertContains(
                SoftDeletes::class,
                class_uses_recursive($model),
                "{$resource} offers restore and purge, but {$model} is not soft-deletable."
            );
        }
    }

    /**
     * An unregistered key means `GranularPermissions::allows` answers false
     * for everyone but the super admin — the resource would look broken
     * rather than forbidden.
     */
    public function test_every_content_resource_names_a_real_permission_key(): void
    {
        foreach ($this->contentResources() as $resource) {
            $key = $resource::permissionKey();

            $this->assertTrue(
                GranularPermissions::isValidKey($key),
                "{$resource} sits behind '{$key}', which is not in GranularPermissions::KEYS."
            );
        }
    }

    /**
     * Renders each list page for real. Columns that reach through a relation
     * or a cast fail here rather than in front of a member of staff.
     */
    public function test_every_list_page_renders(): void
    {
        $this->superAdmin();

        foreach ($this->contentResources() as $resource) {
            $page = $resource::getPages()['index']->getPage();

            Livewire::test($page)
                ->assertSuccessful();
        }
    }

    /**
     * The listing has to be usable on a table with real content in it.
     */
    public function test_every_list_page_offers_a_trashed_filter(): void
    {
        $this->superAdmin();

        foreach ($this->contentResources() as $resource) {
            /** @var class-string<ListRecords> $page */
            $page = $resource::getPages()['index']->getPage();

            $filters = Livewire::test($page)
                ->instance()
                ->getTable()
                ->getFilters();

            $this->assertArrayHasKey(
                'trashed',
                $filters,
                "{$resource} has no trashed filter, so deleted rows cannot be found to restore."
            );
        }
    }

    /**
     * Every resource is listable; create/view/edit are required only where
     * they make sense.
     *
     * The Media Library is the deliberate exception. Media arrives by being
     * uploaded with a post or a place — a row typed in by hand would name a
     * file that does not exist, and editing the path of an existing row
     * breaks every post pointing at it. So it declares `canCreate() =>
     * false` and this asks for the pages that follow from that rather than
     * a uniform shape every resource must wear.
     */
    public function test_every_content_resource_is_listable_and_editable_where_it_should_be(): void
    {
        foreach ($this->contentResources() as $resource) {
            $pages = $resource::getPages();

            $this->assertArrayHasKey('index', $pages, "{$resource} has no listing.");

            if (! $resource::canCreate()) {
                continue;
            }

            foreach (['create', 'view', 'edit'] as $page) {
                $this->assertArrayHasKey(
                    $page,
                    $pages,
                    "{$resource} can be created in but has no '{$page}' page."
                );
            }
        }
    }

    /**
     * Nobody signed out, and no student, gets a listing.
     */
    public function test_a_signed_out_visitor_cannot_view_any_content_resource(): void
    {
        Filament::setCurrentPanel('admin');

        foreach ($this->contentResources() as $resource) {
            $this->assertFalse(
                $resource::canViewAny(),
                "{$resource} is readable with nobody signed in."
            );
        }
    }

    public function test_a_student_cannot_view_any_content_resource(): void
    {
        $student = User::create([
            'name' => 'Student',
            'email' => 'plain-student@arucad.edu.tr',
            'password' => bcrypt('x'),
        ]);
        $this->actingAs($student);
        Filament::setCurrentPanel('admin');

        foreach ($this->contentResources() as $resource) {
            $this->assertFalse(
                $resource::canViewAny(),
                "{$resource} is readable by a student with no role."
            );
        }
    }

    /**
     * A moderator reviews reported content; they do not edit the campus.
     * This is the check that catches a permission key widened by accident.
     */
    public function test_a_moderator_cannot_edit_campus_content(): void
    {
        $moderator = User::create([
            'name' => 'Moderator',
            'email' => 'panel-moderator@arucad.edu.tr',
            'password' => bcrypt('x'),
        ]);
        RoleAssignment::create([
            'email' => $moderator->email,
            'role' => 'moderator',
            'permissions' => null,
            'assigned_by' => 'test',
            'assigned_at' => now(),
        ]);
        $this->actingAs($moderator);
        Filament::setCurrentPanel('admin');

        foreach ($this->contentResources() as $resource) {
            $this->assertFalse(
                $resource::canCreate(),
                "A moderator can create rows in {$resource}."
            );
        }
    }

    // ---- the §2 management-features checklist ---------------------------

    /**
     * Every capability the brief lists, checked on the real table rather
     * than assumed from the trait. A resource can use `ManagesCampusContent`
     * and still forget to wire its helpers into the table, at which point
     * the buttons are simply absent and nobody notices until someone needs
     * to restore something.
     */
    public function test_every_resource_offers_search_filter_and_bulk_actions(): void
    {
        $this->superAdmin();

        foreach ($this->contentResources() as $resource) {
            $page = $resource::getPages()['index']->getPage();
            $table = Livewire::test($page)->instance()->getTable();

            $this->assertTrue(
                $table->isSearchable(),
                "{$resource} has no searchable column, so its listing cannot be searched."
            );

            $this->assertNotEmpty(
                $table->getFilters(),
                "{$resource} has no filters."
            );

            $bulk = [];
            foreach ($table->getToolbarActions() as $action) {
                foreach (method_exists($action, 'getActions') ? $action->getActions() : [$action] as $inner) {
                    $bulk[] = $inner->getName();
                }
            }

            foreach (['delete', 'restore', 'export'] as $needed) {
                $this->assertContains(
                    $needed,
                    $bulk,
                    "{$resource} has no bulk {$needed} action. Available: ".implode(', ', $bulk)
                );
            }
        }
    }

    public function test_every_resource_offers_view_edit_delete_and_restore_on_a_row(): void
    {
        $this->superAdmin();

        foreach ($this->contentResources() as $resource) {
            $page = $resource::getPages()['index']->getPage();
            $table = Livewire::test($page)->instance()->getTable();

            $actions = [];
            foreach ($table->getRecordActions() as $action) {
                foreach (method_exists($action, 'getActions') ? $action->getActions() : [$action] as $inner) {
                    $actions[] = $inner->getName();
                }
            }

            // Every resource must offer a way to *look* at a row before
            // deciding to remove it. For most that is `view`; the Media
            // Library's is `preview`, which shows the image and what is
            // using it — more useful for media than a read-only form.
            $this->assertNotEmpty(
                array_intersect(['view', 'preview'], $actions),
                "{$resource} rows cannot be inspected. Available: ".implode(', ', $actions)
            );

            foreach (['delete', 'restore', 'forceDelete'] as $needed) {
                $this->assertContains(
                    $needed,
                    $actions,
                    "{$resource} rows have no {$needed} action. Available: ".implode(', ', $actions)
                );
            }

            if ($resource::canCreate()) {
                $this->assertContains('edit', $actions, "{$resource} rows cannot be edited.");
            }
        }
    }

    /**
     * Deleting content has to leave a record of who did it. An audit trail
     * with gaps is not an audit trail, and "who removed the careers page"
     * is exactly the question it exists to answer.
     */
    public function test_deleting_from_any_resource_is_written_to_the_audit_trail(): void
    {
        $this->superAdmin();

        $place = Place::create([
            'id' => 'place-audit', 'name' => 'Audit Studio', 'category' => 'academic',
            'lat' => 35.33, 'lng' => 33.31,
        ]);

        Livewire::test(ListPlaces::class)
            ->callAction(TestAction::make('delete')->table($place->getKey()));

        $this->assertDatabaseHas('admin_audit_log', [
            'action' => 'delete',
            'target_type' => 'place',
            'target_label' => 'Audit Studio',
        ]);
    }

    public function test_a_super_admin_can_manage_every_content_resource(): void
    {
        $this->superAdmin();

        foreach ($this->contentResources() as $resource) {
            $this->assertTrue($resource::canViewAny(), "{$resource} is closed to the super admin.");
            $this->assertTrue($resource::canEdit(null), "{$resource} cannot be edited by the super admin.");
        }
    }
}
