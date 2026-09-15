<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Checkin;
use App\Models\Event;
use App\Models\FeedPost;
use App\Models\FoodDailyMenu;
use App\Models\FoodVenue;
use App\Models\Notification as InboxNotification;
use App\Models\Place;
use App\Models\PushToken;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class DatabasePortabilityTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_user_insert_assigns_an_integer_id_and_dependent_fk_rows_work(): void
    {
        $user = User::create([
            'name' => 'Portability User',
            'email' => 'portability@arucad.edu.tr',
            'password' => bcrypt('x'),
        ]);

        $this->assertNotNull($user->id);
        $this->assertTrue(self::isIntegerColumnType(Schema::getColumnType('users', 'id')));
        $this->assertTrue(self::isIntegerColumnType(Schema::getColumnType('feed_posts', 'author_id')));

        $this->actingAsUser($user);
        $data = $this->postJson('/api/v1/feed', ['text' => 'fk smoke'])->assertOk()->json('data');

        $this->assertDatabaseHas('feed_posts', [
            'id' => $data['id'],
            'author_id' => $user->id,
        ]);
        $this->assertSame($user->id, (int) FeedPost::find($data['id'])->author_id);
    }

    public function test_boolean_filters_use_true_false_not_integer_literals(): void
    {
        $user = $this->actingAsRole();
        $place = Place::create(['id' => 'p-bool', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);
        Checkin::create([
            'id' => 'c-visible',
            'place_id' => $place->id,
            'user_id' => $user->id,
            'visible_to_others' => true,
            'created_at' => now(),
        ]);
        Checkin::create([
            'id' => 'c-hidden',
            'place_id' => $place->id,
            'user_id' => $user->id,
            'visible_to_others' => false,
            'created_at' => now(),
        ]);
        Event::create([
            'id' => 'e-draft',
            'title' => 'Draft',
            'time' => '10:00',
            'place_name' => 'X',
            'category' => 'C',
            'draft' => true,
            'workflow_status' => 'draft',
        ]);
        Event::create([
            'id' => 'e-pub',
            'title' => 'Published',
            'time' => '11:00',
            'place_name' => 'X',
            'category' => 'C',
            'draft' => false,
            'workflow_status' => 'published',
        ]);

        $this->assertSame(1, Checkin::where('visible_to_others', true)->count());
        $this->assertSame(1, Checkin::where('visible_to_others', false)->count());
        $this->assertSame(1, Event::where('draft', false)->where('workflow_status', 'published')->count());

        $stats = $this->getJson('/api/v1/admin/stats')->assertOk()->json('data');
        $this->assertSame(1, $stats['checkins']['visibleToOthers']);
        $this->assertSame(1, $stats['events']['published']);
        $this->assertNotEmpty($stats['checkins']['byDay']);
    }

    public function test_json_date_and_composite_uniques_round_trip(): void
    {
        $this->actingAsRole();
        $this->postJson('/api/v1/admin/food-venues', [
            'id' => 'food-port',
            'name' => 'Port Cafe',
        ])->assertOk();
        $this->postJson('/api/v1/admin/food-venues/food-port/menus', [
            'date' => '2026-08-24',
            'items' => ['Çorba', 'Pilav'],
            'price' => '80₺',
        ])->assertOk();

        $menu = FoodDailyMenu::where('food_venue_id', 'food-port')->first();
        $this->assertSame(['Çorba', 'Pilav'], $menu->items);
        $this->assertSame('2026-08-24', $menu->menu_date?->toDateString());

        $this->postJson('/api/v1/admin/food-venues/food-port/menus', [
            'date' => '2026-08-24',
            'items' => ['Salata'],
        ])->assertOk();
        $this->assertSame(1, FoodDailyMenu::where('food_venue_id', 'food-port')->count());
        $this->assertSame(['Salata'], FoodDailyMenu::where('food_venue_id', 'food-port')->first()->items);
    }

    public function test_email_username_push_token_role_and_settings_uniques(): void
    {
        User::create(['name' => 'A', 'email' => 'unique@arucad.edu.tr', 'password' => bcrypt('x')]);
        $this->assertRejected(
            fn () => User::create(['name' => 'B', 'email' => 'unique@arucad.edu.tr', 'password' => bcrypt('x')]),
            'duplicate email should violate unique',
        );

        $user = $this->actingAsUser();
        PushToken::create(['user_id' => $user->id, 'token' => 'tok-a', 'platform' => 'android', 'created_at' => now()]);
        $this->assertRejected(
            fn () => PushToken::create(['user_id' => $user->id, 'token' => 'tok-a', 'platform' => 'ios', 'created_at' => now()]),
            'duplicate push token per user should violate unique',
        );

        RoleAssignment::create([
            'email' => 'role-unique@arucad.edu.tr',
            'role' => 'student',
            'assigned_by' => 'test',
            'assigned_at' => now(),
        ]);
        $this->assertRejected(
            fn () => RoleAssignment::create([
                'email' => 'role-unique@arucad.edu.tr',
                'role' => 'clubPresident',
                'assigned_by' => 'test',
                'assigned_at' => now(),
            ]),
            'duplicate role assignment email should violate unique',
        );

        AppSetting::setValue('site.public.url', 'https://example.test');
        AppSetting::setValue('site.public.url', 'https://example.test/v2');
        $this->assertSame(1, AppSetting::where('key', 'site.public.url')->count());
        $this->assertSame('https://example.test/v2', AppSetting::getValue('site.public.url'));
    }

    public function test_chat_notification_audit_and_pagination_write_on_this_driver(): void
    {
        [$a, $tokenA] = $this->signInChatUser('AlicePort', 'alice.port@arucad.edu.tr');
        [$b] = $this->signInChatUser('BobPort', 'bob.port@arucad.edu.tr');

        $this->withToken($tokenA)->postJson($this->chatMessagesUrl($b), [
            'text' => 'pg smoke',
        ])->assertOk();

        $this->assertDatabaseHas('messages', ['body' => 'pg smoke', 'sender_id' => $a->id]);
        $this->assertSame(1, InboxNotification::where('user_id', $b->id)->count());

        $this->actingAsRole();
        AuditLogger::logAsCurrentUser('update', 'site_settings', 'portability');
        $this->assertDatabaseHas('admin_audit_log', ['action' => 'update', 'target_type' => 'site_settings']);

        $page = $this->getJson('/api/v1/feed?page=1&perPage=5')->assertOk()->json('meta.pagination');
        $this->assertSame(1, $page['currentPage']);
        $this->assertSame(5, $page['perPage']);
        $this->assertArrayHasKey('total', $page);
        $this->assertArrayHasKey('lastPage', $page);
    }

    /**
     * Venues are soft-deleted so the panel can undo a mis-click, and their
     * menus deliberately survive that: a restored venue with no menus is a
     * broken restore. Menus are only ever read through the venue, so a
     * deleted venue takes them out of every read path anyway.
     */
    public function test_a_deleted_food_venue_hides_its_menus_but_keeps_them_for_a_restore(): void
    {
        $this->actingAsRole();
        FoodVenue::create(['id' => 'food-cascade', 'name' => 'Cascade Cafe']);
        FoodDailyMenu::create([
            'id' => 'menu-cascade',
            'food_venue_id' => 'food-cascade',
            'menu_date' => '2026-08-24',
            'items' => ['X'],
        ]);

        FoodVenue::find('food-cascade')->delete();

        $this->assertNull(FoodVenue::find('food-cascade'));
        $this->assertDatabaseHas('food_daily_menus', ['id' => 'menu-cascade']);

        FoodVenue::withTrashed()->find('food-cascade')->restore();

        $this->assertCount(1, FoodVenue::find('food-cascade')->dailyMenus);
    }

    /**
     * Purging for real still cascades — the foreign key is unchanged.
     */
    public function test_force_deleting_a_food_venue_still_cascades_its_menus(): void
    {
        $this->actingAsRole();
        FoodVenue::create(['id' => 'food-purge', 'name' => 'Purge Cafe']);
        FoodDailyMenu::create([
            'id' => 'menu-purge',
            'food_venue_id' => 'food-purge',
            'menu_date' => '2026-08-24',
            'items' => ['X'],
        ]);

        FoodVenue::find('food-purge')->forceDelete();

        $this->assertDatabaseMissing('food_daily_menus', ['id' => 'menu-purge']);
    }

    /**
     * Asserts that a write is refused by the database, and leaves the
     * connection usable afterwards.
     *
     * The savepoint is the whole point. PostgreSQL aborts the entire
     * transaction when a statement fails — every later statement then
     * returns "current transaction is aborted" — and `RefreshDatabase`
     * already has this test inside a transaction. A nested
     * `DB::transaction()` opens a savepoint, so the failed insert rolls
     * back to it and the rest of the test can carry on.
     *
     * SQLite does not need this, which is why the test passed there and
     * failed against the database the app actually runs on.
     */
    private function assertRejected(callable $write, string $because): void
    {
        try {
            DB::transaction($write);
            $this->fail($because);
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }
}
