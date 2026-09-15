<?php

namespace Tests\Feature;

use App\Filament\Resources\AdminPages\AdminPageResource;
use App\Filament\Resources\Clubs\ClubResource;
use App\Filament\Resources\RoleGrants\RoleGrantResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Club;
use App\Models\RoleAssignment;
use App\Models\RoleGrant;
use App\Models\User;
use App\Services\GranularPermissions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminArchitectureV2Test extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $user = User::factory()->create(['email' => 'architecture-admin@arucad.edu.tr']);
        RoleAssignment::create([
            'email' => $user->email, 'role' => GranularPermissions::SUPER_ROLE,
            'permissions' => null, 'assigned_by' => 'test', 'assigned_at' => now(),
        ]);

        return $user;
    }

    public function test_sidebar_uses_the_requested_information_architecture(): void
    {
        app()->setLocale('en');
        $labels = array_map(fn ($group) => $group->getLabel(), Filament::getPanel('admin')->getNavigationGroups());
        $this->assertSame(['Content Management', 'Social', 'AICAD', 'Users', 'Campus Map', 'Operations', 'System'], $labels);
    }

    public function test_core_admin_management_screens_render(): void
    {
        $this->actingAs($this->superAdmin());
        Filament::setCurrentPanel('admin');

        foreach ([AdminPageResource::class, UserResource::class, RoleGrantResource::class] as $resource) {
            $this->get($resource::getUrl('create'))->assertSuccessful();
            $this->get($resource::getUrl('index'))->assertSuccessful();
        }
    }

    public function test_scoped_grants_can_add_expire_and_deny_permissions(): void
    {
        $user = User::factory()->create();
        RoleGrant::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'role' => 'moderator',
            'scope_type' => 'campus', 'scope_id' => 'main', 'assigned_by' => 'test', 'status' => 'active',
        ]);
        $this->assertTrue(GranularPermissions::allows($user, 'moderation.moderate'));

        RoleGrant::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'role' => 'contentEditor',
            'scope_type' => 'own_department', 'scope_id' => 'design', 'permissions' => ['system.health.read'],
            'denied_permissions' => ['pages.manage'], 'assigned_by' => 'test', 'status' => 'active',
        ]);
        $this->assertTrue(GranularPermissions::allows($user, 'system.health.read'));
        $this->assertFalse(GranularPermissions::allows($user, 'pages.manage'));

        RoleGrant::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'role' => GranularPermissions::SUPER_ROLE,
            'scope_type' => 'all', 'expires_at' => now()->subMinute(), 'assigned_by' => 'test', 'status' => 'active',
        ]);
        $this->assertFalse(GranularPermissions::allows($user, 'users.manage'));
    }

    public function test_page_api_serves_one_locale_and_records_versions(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $this->postJson('/api/v1/admin/pages', [
            'id' => 'page-home-v2', 'title' => 'Home', 'slug' => 'home-v2', 'status' => 'published',
            'translations' => [
                'tr' => ['title' => 'Ana Sayfa'], 'en' => ['title' => 'Home'], 'ru' => ['title' => 'Главная'],
            ],
            'blocks' => [[
                'type' => 'heading',
                'data' => ['translations' => [
                    'tr' => ['title' => 'Merhaba'], 'en' => ['title' => 'Hello'], 'ru' => ['title' => 'Привет'],
                ]],
            ]],
        ])->assertOk();

        $this->getJson('/api/v1/pages/home-v2?lang=ru')->assertOk()
            ->assertJsonPath('data.title', 'Главная')
            ->assertJsonPath('data.blocks.0.data.title', 'Привет');
        $this->assertDatabaseHas('content_revisions', ['content_key' => 'page:page-home-v2']);
    }

    public function test_resource_queries_apply_role_scope_not_just_menu_permission(): void
    {
        $user = User::factory()->create();
        RoleGrant::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'role' => 'clubManager',
            'scope_type' => 'own_club', 'scope_id' => 'club-mine', 'assigned_by' => 'test', 'status' => 'active',
        ]);
        Club::create(['id' => 'club-mine', 'name' => 'Mine', 'category' => 'Art', 'description' => '']);
        Club::create(['id' => 'club-other', 'name' => 'Other', 'category' => 'Art', 'description' => '']);

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        $this->assertSame(['club-mine'], ClubResource::getEloquentQuery()->pluck('id')->all());
        $this->assertFalse(ClubResource::canEdit(Club::find('club-other')));
    }

    public function test_content_editor_can_draft_but_cannot_publish_without_separate_permission(): void
    {
        $editor = User::factory()->create();
        RoleGrant::create([
            'id' => (string) Str::uuid(), 'user_id' => $editor->id, 'role' => 'contentEditor',
            'scope_type' => 'all', 'can_publish' => false, 'assigned_by' => 'test', 'status' => 'active',
        ]);
        $this->actingAs($editor);

        $payload = ['id' => 'page-editor-draft', 'title' => 'Draft', 'slug' => 'editor-draft', 'blocks' => []];
        $this->postJson('/api/v1/admin/pages', $payload + ['status' => 'draft'])->assertOk();
        $this->postJson('/api/v1/admin/pages', $payload + ['status' => 'published'])
            ->assertForbidden()->assertJsonPath('error.code', 'PUBLISH_FORBIDDEN');
    }
}
