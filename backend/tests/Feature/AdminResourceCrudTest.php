<?php

namespace Tests\Feature;

use App\Filament\Resources\CareerOpportunities\Pages\CreateCareerOpportunity;
use App\Filament\Resources\Clubs\Pages\CreateClub;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\FoodVenues\Pages\CreateFoodVenue;
use App\Filament\Resources\Places\Pages\CreatePlace;
use App\Filament\Resources\Places\Pages\EditPlace;
use App\Filament\Resources\Places\Pages\ListPlaces;
use App\Filament\Resources\Places\PlaceResource;
use App\Filament\Resources\ServiceItems\Pages\CreateServiceItem;
use App\Filament\Resources\ShuttleRoutes\Pages\CreateShuttleRoute;
use App\Filament\Resources\Sports\Pages\CreateSport;
use App\Models\AdminAuditLog;
use App\Models\Place;
use App\Models\RoleAssignment;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What a member of staff can actually do in the panel.
 *
 * These drive the real Livewire components rather than asserting that the
 * resource classes exist. A resource can be discovered, appear in the
 * navigation and still fail on save — the campus tables use non-incrementing
 * string keys, so a Create page that does not mint an id looks perfectly
 * correct until someone presses the button.
 *
 * Places is the worked example. Every other content resource is built from
 * the same two traits, so a break in the shared behaviour fails here first.
 */
class AdminResourceCrudTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = 'superAdmin'): User
    {
        $email = strtolower($role).'@arucad.edu.tr';

        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => "Panel $role", 'password' => bcrypt('x')],
        );

        RoleAssignment::updateOrCreate(
            ['email' => $email],
            ['role' => $role, 'permissions' => null, 'assigned_by' => 'test', 'assigned_at' => now()],
        );

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');

        return $user;
    }

    private function place(string $id = 'place-seed'): Place
    {
        return Place::create([
            'id' => $id,
            'name' => 'Seed Studio',
            'category' => 'academic',
            'lat' => 35.33,
            'lng' => 33.31,
        ]);
    }

    public function test_the_list_page_renders_for_someone_who_may_manage_places(): void
    {
        $this->admin();
        $this->place();

        Livewire::test(ListPlaces::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(Place::all());
    }

    /**
     * The id is minted for the person filling the form. Without it the
     * insert fails on a null primary key.
     */
    public function test_creating_a_place_mints_an_id_and_saves(): void
    {
        $this->admin();

        Livewire::test(CreatePlace::class)
            ->fillForm([
                'name' => 'New Studio',
                'category' => 'academic',
                'lat' => 35.34,
                'lng' => 33.32,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $place = Place::where('name', 'New Studio')->firstOrFail();

        $this->assertStringStartsWith('place-', $place->id);
    }

    public function test_creating_a_place_is_written_to_the_audit_trail(): void
    {
        $this->admin();

        Livewire::test(CreatePlace::class)
            ->fillForm([
                'name' => 'Audited Studio',
                'category' => 'academic',
                'lat' => 35.34,
                'lng' => 33.32,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('admin_audit_log', [
            'action' => 'create',
            'target_type' => 'place',
            'target_label' => 'Audited Studio',
        ]);
    }

    public function test_editing_a_place_saves_and_is_audited(): void
    {
        $this->admin();
        $place = $this->place();

        Livewire::test(EditPlace::class, ['record' => $place->getKey()])
            ->fillForm(['name' => 'Renamed Studio'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Renamed Studio', $place->fresh()->name);
        $this->assertDatabaseHas('admin_audit_log', [
            'action' => 'update',
            'target_type' => 'place',
            'target_label' => 'Renamed Studio',
        ]);
    }

    /**
     * A latitude of 950 would put the pin nowhere and nothing downstream
     * would notice, so the form is where it has to be caught.
     */
    public function test_an_impossible_coordinate_is_refused(): void
    {
        $this->admin();

        Livewire::test(CreatePlace::class)
            ->fillForm([
                'name' => 'Nowhere',
                'category' => 'academic',
                'lat' => 950,
                'lng' => 33.32,
            ])
            ->call('create')
            ->assertHasFormErrors(['lat']);
    }

    // ---- soft delete and restore ---------------------------------------

    public function test_deleting_from_the_table_is_reversible_and_audited(): void
    {
        $this->admin();
        $place = $this->place();

        Livewire::test(ListPlaces::class)
            ->callAction(TestAction::make('delete')->table($place->getKey()));

        $this->assertSoftDeleted('places', ['id' => $place->getKey()]);
        $this->assertDatabaseHas('admin_audit_log', [
            'action' => 'delete',
            'target_type' => 'place',
            'target_label' => 'Seed Studio',
        ]);

        Livewire::test(ListPlaces::class)
            ->callAction(TestAction::make('restore')->table($place->getKey()));

        $this->assertNotNull(Place::find($place->getKey()));
        $this->assertDatabaseHas('admin_audit_log', [
            'action' => 'restore',
            'target_type' => 'place',
        ]);
    }

    public function test_purging_removes_the_row_for_good(): void
    {
        $this->admin();
        $place = $this->place();
        $place->delete();

        Livewire::test(ListPlaces::class)
            ->callAction(TestAction::make('forceDelete')->table($place->getKey()));

        $this->assertCount(0, Place::withTrashed()->where('id', $place->getKey())->get());
        $this->assertDatabaseHas('admin_audit_log', [
            'action' => 'purge',
            'target_type' => 'place',
        ]);
    }

    /**
     * Deleted rows stay out of the listing until they are asked for, so the
     * panel shows the same campus the app does.
     */
    public function test_deleted_rows_are_hidden_until_the_trashed_filter_asks_for_them(): void
    {
        $this->admin();
        $live = $this->place('place-live');
        $gone = $this->place('place-gone');
        $gone->delete();

        Livewire::test(ListPlaces::class)
            ->assertCanSeeTableRecords([$live])
            ->assertCanNotSeeTableRecords([$gone])
            ->filterTable('trashed', '0')
            ->assertCanSeeTableRecords([$gone]);
    }

    // ---- authorisation --------------------------------------------------

    /**
     * The permission key is the same one `/api/v1/admin/places` checks, so
     * a role that cannot use the endpoint cannot use the panel either.
     */
    public function test_a_moderator_cannot_reach_the_places_resource(): void
    {
        $this->admin('moderator');

        $this->assertFalse(PlaceResource::canViewAny());
    }

    public function test_a_content_editor_can_reach_the_places_resource(): void
    {
        $this->admin('contentEditor');

        $this->assertTrue(PlaceResource::canViewAny());
    }

    public function test_a_student_cannot_reach_the_places_resource(): void
    {
        $user = User::create([
            'name' => 'Student',
            'email' => 'student@arucad.edu.tr',
            'password' => bcrypt('x'),
        ]);
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');

        $this->assertFalse(PlaceResource::canViewAny());
    }

    public function test_the_audit_trail_records_who_did_it(): void
    {
        $admin = $this->admin();

        Livewire::test(CreatePlace::class)
            ->fillForm([
                'name' => 'Attributed Studio',
                'category' => 'academic',
                'lat' => 35.34,
                'lng' => 33.32,
            ])
            ->call('create');

        $entry = AdminAuditLog::where('target_label', 'Attributed Studio')->firstOrFail();

        $this->assertSame($admin->name, $entry->actor_name);
    }

    // ---- every other resource -------------------------------------------

    /**
     * The smallest valid row each resource can be asked to create.
     *
     * Both bugs found while building these resources were save-time —
     * a missing primary key, and a NOT NULL column sent an explicit null —
     * and neither shows up until the button is pressed. So every resource
     * gets its button pressed.
     *
     * @return array<string, array{class-string, array<string, mixed>, string}>
     */
    public static function minimalRows(): array
    {
        return [
            'club' => [
                CreateClub::class,
                ['name' => 'Smoke Club', 'category' => 'social'],
                'club-',
            ],
            'sport' => [
                CreateSport::class,
                ['name' => 'Smoke Sport', 'facility' => 'Main hall'],
                'sport-',
            ],
            'service' => [
                CreateServiceItem::class,
                ['title' => 'Smoke Service', 'category' => 'support'],
                'service-',
            ],
            'food venue' => [
                CreateFoodVenue::class,
                ['name' => 'Smoke Cafe'],
                'food-',
            ],
            'shuttle route' => [
                CreateShuttleRoute::class,
                // A simple() repeater's *form* state is still keyed by the
                // inner field; it is flattened on the way to the column.
                [
                    'name' => 'Smoke Route',
                    'color_key' => 'blue',
                    'stops' => [['stop' => 'Gate']],
                    'departures' => [['time' => '08:30']],
                ],
                'shuttle-',
            ],
            'career opportunity' => [
                CreateCareerOpportunity::class,
                ['title' => 'Smoke Internship', 'kind' => 'internship'],
                'career-',
            ],
            'event' => [
                CreateEvent::class,
                [
                    'title' => 'Smoke Event',
                    'category' => 'social',
                    'time' => '18:00 - 20:00',
                    'place_name' => 'Main hall',
                ],
                'event-',
            ],
        ];
    }

    #[DataProvider('minimalRows')]
    public function test_each_resource_can_create_a_row(
        string $page,
        array $data,
        string $idPrefix,
    ): void {
        $this->admin();

        Livewire::test($page)
            ->fillForm($data)
            ->call('create')
            ->assertHasNoFormErrors();

        $model = $page::getResource()::getModel();
        $record = $model::query()->latest('id')->first()
            ?? $model::query()->first();

        $this->assertNotNull($record, "Nothing was created by {$page}.");
        $this->assertStringStartsWith($idPrefix, (string) $record->getKey());
    }

    /**
     * Delete, restore, purge — through the real table actions, on every
     * resource rather than only the one that was built first.
     */
    #[DataProvider('minimalRows')]
    public function test_each_resource_can_delete_and_restore_a_row(
        string $page,
        array $data,
        string $_idPrefix,
    ): void {
        $this->admin();

        Livewire::test($page)->fillForm($data)->call('create')->assertHasNoFormErrors();

        $resource = $page::getResource();
        $model = $resource::getModel();
        $record = $model::query()->first();
        $listPage = $resource::getPages()['index']->getPage();

        Livewire::test($listPage)
            ->callAction(TestAction::make('delete')->table($record->getKey()));

        $this->assertNull($model::query()->find($record->getKey()));

        Livewire::test($listPage)
            ->callAction(TestAction::make('restore')->table($record->getKey()));

        $this->assertNotNull($model::query()->find($record->getKey()));
    }
}
