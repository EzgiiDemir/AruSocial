<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The single most important regression guard in this milestone. Every
// authenticated request used to resolve to User::first(), so two students
// on the same backend were one account: B's check-in raised A's XP, and
// A's profile was everyone's profile (docs/AUDIT_GERCEK_URUN.md §8).
class CurrentUserIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: string, 2: User, 3: string} */
    private function twoSignedInUsers(): array
    {
        $a = User::create(['name' => 'Ayşe Yıldız', 'email' => 'ayse.yildiz@arucad.edu.tr', 'password' => bcrypt('sifre-a')]);
        $b = User::create(['name' => 'Burak Kaya', 'email' => 'burak.kaya@arucad.edu.tr', 'password' => bcrypt('sifre-b')]);

        $tokenA = $this->postJson('/api/v1/auth/session',
            ['email' => $a->email, 'password' => 'sifre-a'])->json('data.token');
        $tokenB = $this->postJson('/api/v1/auth/session',
            ['email' => $b->email, 'password' => 'sifre-b'])->json('data.token');

        return [$a, $tokenA, $b, $tokenB];
    }

    public function test_each_token_resolves_to_its_own_user(): void
    {
        [$a, $tokenA, $b, $tokenB] = $this->twoSignedInUsers();

        $meA = $this->withToken($tokenA)->getJson('/api/v1/me');
        $meB = $this->withToken($tokenB)->getJson('/api/v1/me');

        $meA->assertOk()->assertJsonPath('data.id', (string) $a->id)
            ->assertJsonPath('data.name', 'Ayşe Yıldız');
        $meB->assertOk()->assertJsonPath('data.id', (string) $b->id)
            ->assertJsonPath('data.name', 'Burak Kaya');
        $this->assertNotEquals($meA->json('data.id'), $meB->json('data.id'));
    }

    public function test_a_write_by_one_user_is_not_credited_to_the_other(): void
    {
        [$a, $tokenA, $b, $tokenB] = $this->twoSignedInUsers();
        Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);

        $this->withToken($tokenB)->postJson('/api/v1/checkins', ['placeId' => 'p1'])->assertOk();

        $this->assertEquals(10, $b->fresh()->xp, "the check-in's XP belongs to B");
        $this->assertEquals(0, $a->fresh()->xp, "A did nothing and must have earned nothing");
        $this->assertDatabaseHas('checkins', ['place_id' => 'p1', 'user_id' => $b->id]);
        $this->assertDatabaseMissing('checkins', ['place_id' => 'p1', 'user_id' => $a->id]);

        // And each account only sees its own history.
        $activityA = $this->withToken($tokenA)->getJson('/api/v1/me/activity')->json('data');
        $activityB = $this->withToken($tokenB)->getJson('/api/v1/me/activity')->json('data');
        $this->assertEmpty($activityA);
        $this->assertNotEmpty($activityB);
    }

    // User::first() would have answered with A for both tokens, since A is
    // the older row — this pins the ordering that used to hide the bug.
    public function test_the_second_user_is_not_answered_as_the_first_row(): void
    {
        [$a, , , $tokenB] = $this->twoSignedInUsers();

        $response = $this->withToken($tokenB)->getJson('/api/v1/me');

        $this->assertNotEquals((string) $a->id, $response->json('data.id'));
    }

    public function test_banning_one_account_does_not_lock_out_the_other(): void
    {
        [$a, $tokenA, $b, $tokenB] = $this->twoSignedInUsers();

        $a->update(['banned_at' => now()]);

        $this->withToken($tokenA)->getJson('/api/v1/feed')
            ->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_BANNED');
        $this->withToken($tokenB)->getJson('/api/v1/feed')->assertOk();
        $this->assertNull($b->fresh()->banned_at);
    }
}
