<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\User;
use App\Services\Moderation\AccountEnforcement;
use App\Services\Moderation\Workflow\AccountEnforcementPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Account enforcement can be switched off without switching off
 * moderation.
 *
 * The two are different decisions with very different stakes: refusing a
 * photo is undone in a second, locking a student out of the campus app is
 * not. Testing moderation means submitting violations on purpose, and an
 * escalation ladder cannot tell a test case from a real one — it banned
 * this project's own student test account after three probes, which then
 * presented as a login bug.
 *
 * The half of this that actually needs guarding is the half that must NOT
 * change: with enforcement off, unsafe content is still refused. A flag
 * that quietly also stopped blocking content would be the worst possible
 * outcome, because everything would look like it was working.
 */
class AccountEnforcementToggleTest extends TestCase
{
    use RefreshDatabase;

    private const HATE = 'lanet zenci defol buradan';

    private function disableEnforcement(): void
    {
        config(['moderation.enforcement.enabled' => false]);
    }

    // ---- what must not change --------------------------------------

    public function test_unsafe_content_is_still_blocked(): void
    {
        $this->disableEnforcement();
        $this->actingAsUser();

        $this->postJson('/api/v1/feed', ['text' => self::HATE])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');

        $this->assertSame(0, FeedPost::includingUnmoderated()->count(),
            'Disabling enforcement also let the content through.');
    }

    public function test_safe_content_still_publishes(): void
    {
        $this->disableEnforcement();
        $this->actingAsUser();

        $this->postJson('/api/v1/feed', ['text' => 'Kütüphanede buluşalım'])
            ->assertSuccessful();
    }

    /**
     * An account banned before the window stays banned. The flag stops
     * new consequences; it is not an amnesty, and it must not become a
     * way to walk out of a suspension by flipping an env var.
     */
    public function test_an_existing_ban_is_still_honoured(): void
    {
        $this->disableEnforcement();
        $user = $this->actingAsUser();
        $user->forceFill(['banned_until' => now()->addDay()])->save();

        $this->postJson('/api/v1/feed', ['text' => 'merhaba'])->assertStatus(403);
    }

    // ---- what the flag actually suppresses -------------------------

    public function test_repeated_violations_never_ban_the_account(): void
    {
        $this->disableEnforcement();
        $user = $this->actingAsUser();

        // Comfortably past every rung of both ladders.
        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/api/v1/feed', ['text' => self::HATE])->assertStatus(400);
        }

        $user->refresh();
        $this->assertSame(0, (int) $user->strikes, 'A strike was charged.');
        $this->assertNull($user->banned_at);
        $this->assertNull($user->banned_until);
    }

    /**
     * The violation *record* survives — moderators work the queue from
     * those rows, so suppressing them would break the case workflow
     * rather than the punishment. It is written UNCONFIRMED, so it
     * carries no points and no consequence.
     */
    public function test_a_violation_is_recorded_but_costs_nothing(): void
    {
        $this->disableEnforcement();
        $user = $this->makeUser();

        $policy = app(AccountEnforcementPolicy::class);
        $result = $policy->recordConfirmedViolation(
            $user, 'hate', 'critical', 'idem-'.uniqid(),
        );

        $this->assertSame('none', $result['action']);
        $this->assertTrue($result['violation']->exists, 'The evidence row is the case record.');
        $this->assertFalse((bool) $result['violation']->confirmed);
        $this->assertSame('suppressed', $result['violation']->action_taken);
        $this->assertSame(0, $policy->activePoints($user->refresh()));
        $this->assertNull($user->refresh()->banned_until,
            'The suspension was actually served.');
        $this->assertNull($user->refresh()->posting_restricted_until);
    }

    /**
     * The promise the toggle makes, and the one that is easiest to break:
     * turning enforcement back on must not settle a bill run up while it
     * was off.
     *
     * Banking the points and merely skipping the lock would do exactly
     * that — the first violation after the switch flipped would land on
     * top of a whole testing window.
     */
    public function test_points_do_not_accumulate_while_enforcement_is_off(): void
    {
        $this->disableEnforcement();
        $user = $this->makeUser();
        $policy = app(AccountEnforcementPolicy::class);

        for ($i = 0; $i < 4; $i++) {
            $policy->recordConfirmedViolation($user, 'hate', 'critical', 'idem-off-'.$i);
        }
        $this->assertSame(0, $policy->activePoints($user->refresh()));

        // Switched back on, the next violation is charged on its own —
        // not on top of the four above.
        config(['moderation.enforcement.enabled' => true]);
        $result = $policy->recordConfirmedViolation($user, 'spam', 'minor', 'idem-on');

        $this->assertSame(1, $result['points']);
        $this->assertSame('warning', $result['action']);
        $this->assertNull($user->refresh()->banned_until);
    }

    /**
     * A posting restriction is the 3-point rung and must not behave like
     * a suspension: the account keeps working, it just cannot submit.
     */
    public function test_a_posting_restriction_is_not_a_suspension(): void
    {
        config(['moderation.enforcement.enabled' => true]);
        $user = $this->makeUser();

        app(AccountEnforcementPolicy::class)->recordConfirmedViolation(
            $user, 'harassment', 'serious', 'idem-'.uniqid(),
        );

        $user->refresh();
        $this->assertNotNull($user->posting_restricted_until);
        $this->assertNull($user->banned_until, 'A restriction must not lock the account.');
    }

    // ---- the default -----------------------------------------------

    /**
     * The single most important assertion here. This flag is being used
     * during a testing phase, and the failure mode of the wrong default
     * is a launched university app where no violation has any
     * consequence and nothing looks wrong.
     */
    public function test_enforcement_is_on_by_default(): void
    {
        $this->assertTrue(AccountEnforcement::enabled());
        $this->assertTrue((bool) config('moderation.enforcement.enabled'));
    }

    public function test_enforcement_still_bans_when_enabled(): void
    {
        config(['moderation.enforcement.enabled' => true]);
        $user = $this->makeUser();

        app(AccountEnforcementPolicy::class)->recordConfirmedViolation(
            $user, 'hate', 'critical', 'idem-'.uniqid(),
        );

        $this->assertNotNull($user->refresh()->banned_until,
            'Enforcement is enabled but nothing was applied.');
    }

    public function test_the_state_is_reported_on_the_health_endpoint(): void
    {
        $this->disableEnforcement();
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.moderation.enforcement', 'off');

        config(['moderation.enforcement.enabled' => true]);
        $this->getJson('/api/v1/health')
            ->assertJsonPath('data.moderation.enforcement', 'on');
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Deneme',
            'email' => 'deneme'.uniqid().'@arucad.edu.tr',
            'password' => bcrypt('x'),
        ]);
    }
}
