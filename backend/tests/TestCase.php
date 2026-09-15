<?php

namespace Tests;

use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\FcmClient;
use App\Services\Moderation\ModerationNotice;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakeFcmClient;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        /*
         * A cached config silently overrides phpunit.xml.
         *
         * bootstrap/cache/config.php freezes whatever .env held when it was
         * written, and Laravel then ignores the `force="true"` env values
         * this suite depends on — including DB_CONNECTION and DB_DATABASE.
         * The suite quietly starts running against the *development*
         * database, with RefreshDatabase enabled.
         *
         * This has already happened twice here, and both times it looked
         * like a dozen unrelated test failures rather than what it was.
         * Failing loudly, once, with the fix in the message costs far less
         * than diagnosing it a third time.
         */
        // dirname(__DIR__), not base_path(): this runs before the
        // application is booted, so the container does not exist yet.
        if (file_exists(dirname(__DIR__).'/bootstrap/cache/config.php')) {
            $this->fail(
                'bootstrap/cache/config.php exists, which overrides phpunit.xml '
                .'and can point the test suite at the development database. '
                .'Run: php artisan config:clear'
            );
        }

        parent::setUp();

        // The notice slot is static and survives between tests in one
        // process, so a leftover from an earlier case could otherwise show
        // up in an unrelated assertion.
        ModerationNotice::reset();
    }

    /**
     * Every /api/v1 route except health and login now sits behind
     * `auth:sanctum`, so a feature test has to say who it is. Returns the
     * user so tests can keep asserting against the exact row the request
     * will resolve to, and reuses an existing one rather than tripping the
     * unique email constraint when a test seeds its own.
     */
    protected function actingAsUser(?User $user = null): User
    {
        $user ??= User::firstOrCreate(
            ['email' => 'test@arucad.edu.tr'],
            ['name' => 'Test Student', 'password' => bcrypt('x')],
        );
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * A signed-in account that also holds a real role assignment, which is
     * what /admin/* authorizes against. Defaults to superAdmin because most
     * existing tests exercise an admin endpoint incidentally rather than
     * testing authorization itself — the tests that *do* care pass the
     * narrower role they mean.
     */
    protected function actingAsRole(string $role = 'superAdmin', ?array $permissions = null): User
    {
        $email = strtolower($role).'@arucad.edu.tr';
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => "Test $role", 'password' => bcrypt('x')],
        );
        RoleAssignment::updateOrCreate(
            ['email' => $email],
            ['role' => $role, 'permissions' => $permissions, 'assigned_by' => 'test', 'assigned_at' => now()],
        );

        return $this->actingAsUser($user);
    }

    /**
     * Laravel's request guard memoises whichever user it resolved first,
     * and a feature test reuses one container for every request it makes.
     * Without this flush the second token in a test is silently answered as
     * the first token's user, and a token revoked mid-test keeps working —
     * both of which would hide exactly the bugs these tests exist to catch.
     * Real requests each get a fresh container (Octane flushes the same
     * state explicitly), so this is a test-harness concern, not app
     * behaviour.
     */
    public function withToken(#[\SensitiveParameter] string $token, string $type = 'Bearer')
    {
        $this->app['auth']->forgetGuards();

        return parent::withToken($token, $type);
    }

    protected function fakeFcm(): FakeFcmClient
    {
        $fake = new FakeFcmClient;
        $this->app->instance(FcmClient::class, $fake);

        return $fake;
    }

    /**
     * JPEG magic bytes without requiring the GD extension (Windows PHP often
     * ships without it). Size check in MediaController runs before magic.
     */
    protected function fakeJpeg(string $name = 'shot.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00".str_repeat('A', 64),
        );
    }

    /** Coordinates matching CreatesPlaces / AchievementTest seed places. */
    protected function checkinNear(string $placeId, float $lat = 35.337502, float $lng = 33.321226, bool $visible = true): array
    {
        return [
            'placeId' => $placeId,
            'latitude' => $lat,
            'longitude' => $lng,
            'visibleToOthers' => $visible,
        ];
    }

    protected static function isIntegerColumnType(string $type): bool
    {
        return in_array(strtolower($type), ['bigint', 'integer', 'int', 'int8', 'int4', 'smallint', 'int2'], true);
    }
}
