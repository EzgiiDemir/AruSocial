<?php

namespace Tests\Feature;

use App\Filament\Pages\Integrations;
use App\Models\IntegrationState;
use App\Models\RoleGrant;
use App\Services\Integrations\IntegrationRegistry;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Filament page is the surface staff actually use, so it gets the same
 * scrutiny as the JSON API: who may open it, who may operate it, and whether
 * a credential can reach the rendered HTML.
 */
class AdminIntegrationsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('admin');
        Http::preventStrayRequests();
    }

    public function test_a_student_cannot_open_the_page(): void
    {
        $this->actingAsRole('student');

        $this->assertFalse(Integrations::canAccess());
    }

    public function test_a_super_admin_can_open_the_page(): void
    {
        $this->actingAsRole('superAdmin');

        $this->assertTrue(Integrations::canAccess());
    }

    /**
     * The canonical `system.integration.*` keys resolve through a RoleGrant,
     * not through the legacy RoleAssignment role string — GranularPermissions
     * only consults its KEYS map (legacy keys) after the grant check, and
     * `system.integration.read` is not in that map.
     *
     * So an IT account provisioned the modern way can open the page, while
     * the same role string set only as a legacy assignment cannot. Both
     * directions are asserted here because getting this backwards would
     * either lock IT out of their own page or hand it to anyone labelled
     * 'it' without a real grant.
     */
    public function test_an_it_grant_can_open_and_operate_the_page(): void
    {
        $user = $this->actingAsRole('it');

        $this->assertFalse(
            Integrations::canAccess(),
            'a legacy role assignment alone must not unlock the page',
        );

        RoleGrant::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role' => 'it',
            'status' => 'active',
            'assigned_by' => 'test',
        ]);

        $this->assertTrue(Integrations::canAccess());
        $this->assertTrue(Livewire::test(Integrations::class)->instance()->canManage());
    }

    public function test_a_read_only_analyst_grant_cannot_operate_the_page(): void
    {
        $user = $this->actingAsRole('analyst');
        RoleGrant::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role' => 'analyst',
            'status' => 'active',
            'assigned_by' => 'test',
        ]);

        // analyst holds neither system.integration nor manage_settings.
        $this->assertFalse(Integrations::canAccess());
    }

    public function test_page_lists_every_registered_integration(): void
    {
        $this->actingAsRole();

        $component = Livewire::test(Integrations::class);

        $keys = array_column($component->get('rows'), 'key');
        foreach (array_keys(app(IntegrationRegistry::class)->definitions()) as $expected) {
            $this->assertContains($expected, $keys);
        }
    }

    public function test_rendered_page_never_contains_a_raw_credential(): void
    {
        config()->set('services.groq.key', 'gsk_rendered_secret_value_42');
        config()->set('services.campus_directory.api_key', 'cd_rendered_secret_value_99');
        $this->actingAsRole();

        Livewire::test(Integrations::class)
            ->assertSuccessful()
            ->assertDontSee('gsk_rendered_secret_value_42')
            ->assertDontSee('cd_rendered_secret_value_99')
            // The masked tail is what an operator is allowed to see.
            ->assertSee('••••••••e_42');
    }

    public function test_toggling_from_the_page_persists_and_updates_status(): void
    {
        config()->set('services.groq.key', 'gsk_live_key_value_here_1234');
        $this->actingAsRole();

        Livewire::test(Integrations::class)
            ->call('toggle', 'groq', false)
            ->assertSuccessful();

        $this->assertFalse(IntegrationState::find('groq')->enabled);

        $rows = collect(Livewire::test(Integrations::class)->get('rows'));
        $this->assertSame(
            IntegrationRegistry::STATUS_DISABLED,
            $rows->firstWhere('key', 'groq')['status'],
        );
    }

    public function test_test_button_records_a_failure_without_throwing(): void
    {
        config()->set('services.groq.key', 'gsk_live_key_value_here_1234');
        Http::fake(['api.groq.com/*' => Http::response([], 500)]);
        $this->actingAsRole();

        Livewire::test(Integrations::class)
            ->call('test', 'groq')
            ->assertSuccessful();

        $this->assertFalse(IntegrationState::find('groq')->last_test_ok);
    }
}
