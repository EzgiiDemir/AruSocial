<?php

namespace Tests\Feature;

use App\Models\OnboardingProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Domain cleanup: "First 30 Days" checklist completion used to live only
// in device-local SharedPreferences (AppSettingsStore.onboardingDone) —
// real, shared onboarding_progress replaces that in Rest mode.
class OnboardingProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_account_has_no_completed_steps_and_a_real_started_at(): void
    {
        $me = $this->actingAsUser();

        $data = $this->getJson('/api/v1/me/onboarding')->assertOk()->json('data');

        $this->assertEquals([], $data['done']);
        $this->assertEquals($me->created_at->toIso8601String(), $data['startedAt']);
        $this->assertTrue($data['eligible']);
    }

    public function test_second_year_student_is_not_onboarding_eligible(): void
    {
        $me = $this->actingAsUser();
        $me->update(['year' => '2']);

        $data = $this->getJson('/api/v1/me/onboarding')->assertOk()->json('data');
        $this->assertFalse($data['eligible']);
    }

    public function test_first_year_student_is_onboarding_eligible(): void
    {
        $me = $this->actingAsUser();
        $me->update(['year' => '1']);

        $data = $this->getJson('/api/v1/me/onboarding')->assertOk()->json('data');
        $this->assertTrue($data['eligible']);
    }

    public function test_marking_a_step_completed_persists_it(): void
    {
        $me = $this->actingAsUser();

        $data = $this->postJson('/api/v1/me/onboarding/day1-orientation', ['completed' => true])
            ->assertOk()->json('data');

        $this->assertTrue($data['completed']);
        $this->assertDatabaseHas('onboarding_progress', [
            'user_id' => $me->id, 'step_id' => 'day1-orientation',
        ]);
        $this->assertEquals(['day1-orientation'], $this->getJson('/api/v1/me/onboarding')->json('data.done'));
    }

    public function test_marking_the_same_step_completed_twice_does_not_duplicate(): void
    {
        $me = $this->actingAsUser();

        $this->postJson('/api/v1/me/onboarding/day1-id', ['completed' => true])->assertOk();
        $this->postJson('/api/v1/me/onboarding/day1-id', ['completed' => true])->assertOk();

        $this->assertEquals(1, OnboardingProgress::where('user_id', $me->id)->where('step_id', 'day1-id')->count());
    }

    public function test_unchecking_a_step_removes_it(): void
    {
        $me = $this->actingAsUser();
        $this->postJson('/api/v1/me/onboarding/week1-club', ['completed' => true])->assertOk();

        $data = $this->postJson('/api/v1/me/onboarding/week1-club', ['completed' => false])
            ->assertOk()->json('data');

        $this->assertFalse($data['completed']);
        $this->assertDatabaseMissing('onboarding_progress', ['user_id' => $me->id, 'step_id' => 'week1-club']);
    }

    public function test_unchecking_a_step_never_marked_done_is_a_harmless_no_op(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/me/onboarding/week2-library', ['completed' => false])
            ->assertOk()
            ->assertJsonPath('data.completed', false);
    }

    public function test_progress_is_isolated_per_account(): void
    {
        $other = User::create(['name' => 'Other', 'email' => 'other@arucad.edu.tr', 'password' => bcrypt('x')]);
        $this->actingAsUser($other);
        $this->postJson('/api/v1/me/onboarding/day1-orientation', ['completed' => true])->assertOk();

        $this->actingAsUser();
        $data = $this->getJson('/api/v1/me/onboarding')->json('data');

        $this->assertEquals([], $data['done']);
    }

    public function test_completed_must_be_a_boolean(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/v1/me/onboarding/day1-id', ['completed' => 'yes']);

        $response->assertStatus(400);
        $this->assertEquals('VALIDATION', $response->json('error.code'));
    }

    public function test_onboarding_endpoints_require_a_signed_in_account(): void
    {
        $this->getJson('/api/v1/me/onboarding')->assertStatus(401)->assertJsonPath('error.code', 'AUTH_REQUIRED');
        $this->postJson('/api/v1/me/onboarding/day1-id', ['completed' => true])
            ->assertStatus(401)->assertJsonPath('error.code', 'AUTH_REQUIRED');
        $this->assertDatabaseCount('onboarding_progress', 0);
    }

    public function test_deleting_a_user_takes_their_onboarding_progress_with_it(): void
    {
        $me = $this->actingAsUser();
        $this->postJson('/api/v1/me/onboarding/day1-id', ['completed' => true])->assertOk();

        $me->delete();

        $this->assertDatabaseCount('onboarding_progress', 0);
    }
}
