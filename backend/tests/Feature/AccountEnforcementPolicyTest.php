<?php

namespace Tests\Feature;

use App\Models\UserViolation;
use App\Services\Moderation\Workflow\AccountEnforcementPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Account penalties, which are a different question from content.
 */
class AccountEnforcementPolicyTest extends TestCase
{
    use RefreshDatabase;

    /** actingAsUser() takes a model, so each extra reporter is made here. */
    private function makeUser(string $email): \App\Models\User
    {
        return \App\Models\User::firstOrCreate(
            ['email' => $email],
            ['name' => explode('@', $email)[0], 'password' => bcrypt('x')],
        );
    }

    public function test_severity_decides_the_consequence_not_the_count(): void
    {
        $policy = new AccountEnforcementPolicy;

        $spammer = $this->actingAsUser($this->makeUser('spammer@arucad.edu.tr'));
        $policy->recordConfirmedViolation($spammer, 'spam', 'minor', 'k1');

        $threatener = $this->actingAsUser($this->makeUser('threat@arucad.edu.tr'));
        $result = $policy->recordConfirmedViolation($threatener, 'threat', 'critical', 'k2');

        // One violation each, wildly different outcomes.
        $this->assertSame(0, $policy->activePoints($spammer) >= 6 ? 1 : 0,
            'A single spam violation must not reach a suspension.');
        $this->assertSame('temporary_suspension', $result['action']);
        $this->assertNotNull($threatener->fresh()->banned_until);
        $this->assertNull($spammer->fresh()->banned_until);
    }

    /** A retried job or a double-clicked button must not punish twice. */
    public function test_recording_the_same_violation_twice_is_idempotent(): void
    {
        $policy = new AccountEnforcementPolicy;
        $user = $this->actingAsUser();

        $first = $policy->recordConfirmedViolation($user, 'hate', 'severe', 'case-1:hate');
        $second = $policy->recordConfirmedViolation($user, 'hate', 'severe', 'case-1:hate');

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame(1, UserViolation::where('user_id', $user->id)->count());
        $this->assertSame(6, $policy->activePoints($user));
    }

    /** Expired points stop counting; an old mistake must not compound forever. */
    public function test_expired_violations_no_longer_count(): void
    {
        $policy = new AccountEnforcementPolicy;
        $user = $this->actingAsUser();

        $policy->recordConfirmedViolation($user, 'spam', 'minor', 'old');
        UserViolation::where('user_id', $user->id)->update(['expires_at' => now()->subDay()]);

        $this->assertSame(0, $policy->activePoints($user));
    }

    /** Unconfirmed rows are evidence, not violations. */
    public function test_unconfirmed_violations_do_not_count(): void
    {
        $policy = new AccountEnforcementPolicy;
        $user = $this->actingAsUser();

        UserViolation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'category' => 'harassment',
            'severity' => 'serious',
            'confirmed' => false,
            'points' => 3,
            'idempotency_key' => 'unconfirmed-1',
        ]);

        $this->assertSame(0, $policy->activePoints($user));
    }

    /** The ceiling is a long suspension. Permanence stays a human decision. */
    public function test_the_ladder_never_bans_permanently_on_its_own(): void
    {
        $policy = new AccountEnforcementPolicy;
        $user = $this->actingAsUser();

        for ($i = 0; $i < 6; $i++) {
            $policy->recordConfirmedViolation($user, 'threat', 'critical', "k{$i}");
        }

        $bannedUntil = $user->fresh()->banned_until;
        $this->assertNotNull($bannedUntil);
        $this->assertTrue($bannedUntil->lessThan(now()->addYears(5)),
            'The automatic ladder must not produce an effectively permanent ban.');
    }

    /** A new violation mid-suspension must not hand back time already served. */
    public function test_a_new_violation_never_shortens_an_existing_suspension(): void
    {
        $policy = new AccountEnforcementPolicy;
        $user = $this->actingAsUser();

        $policy->recordConfirmedViolation($user, 'threat', 'critical', 'long');
        $long = $user->fresh()->banned_until;

        $policy->recordConfirmedViolation($user, 'spam', 'minor', 'short');

        $this->assertTrue($user->fresh()->banned_until->greaterThanOrEqualTo($long));
    }
}
