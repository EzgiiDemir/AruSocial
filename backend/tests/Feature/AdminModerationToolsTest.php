<?php

namespace Tests\Feature;

use App\Models\ModerationEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin-side moderation tooling: reading the audit trail, correcting a bad
 * decision, and applying or lifting a ban by hand.
 *
 * The permission checks matter as much as the features — violation history
 * is other people's worst moments, and a student must not be able to reach
 * it (or unban themselves) by calling the endpoint directly.
 */
class AdminModerationToolsTest extends TestCase
{
    use RefreshDatabase;

    private function seedEvent(User $user, array $overrides = []): ModerationEvent
    {
        return ModerationEvent::create(array_merge([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'content_type' => 'post',
            'source_feature' => 'feed.store',
            'action' => ModerationEvent::ACTION_REJECTED,
            'flagged' => true,
            'categories' => ['harassment'],
            'category_scores' => ['harassment' => 0.94],
            'decided_by' => 'openai',
            'strike_number' => 1,
            'penalty' => 'warning',
            'moderation_provider' => 'openai',
            'moderation_model' => 'omni-moderation-latest',
            'excerpt' => 'offending excerpt',
        ], $overrides));
    }

    public function test_a_student_cannot_read_moderation_history(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/v1/admin/moderation/events')->assertStatus(403);
        $this->getJson('/api/v1/admin/moderation/users')->assertStatus(403);
    }

    public function test_a_student_cannot_unban_themselves(): void
    {
        $me = $this->actingAsUser();
        $me->update(['strikes' => 4, 'banned_until' => now()->addDay()]);

        // The ban middleware refuses first; either way it must not succeed.
        $this->postJson("/api/v1/admin/moderation/users/{$me->id}/ban", ['lift' => true])
            ->assertStatus(403);

        $this->assertNotNull($me->fresh()->banned_until);
    }

    public function test_a_moderator_sees_the_decision_trail(): void
    {
        $student = User::factory()->create(['strikes' => 1]);
        $this->seedEvent($student);
        $this->actingAsRole('moderator');

        $events = $this->getJson('/api/v1/admin/moderation/events')->assertOk()->json('data');

        $this->assertCount(1, $events);
        $this->assertSame('rejected', $events[0]['action']);
        $this->assertSame('feed.store', $events[0]['sourceFeature']);
        $this->assertSame('omni-moderation-latest', $events[0]['model']);
        $this->assertContains('harassment', $events[0]['categories']);
    }

    public function test_removing_a_wrong_strike_also_lifts_the_ban_it_caused(): void
    {
        $student = User::factory()->create([
            'strikes' => 4,
            'banned_until' => now()->addDay(),
            'moderation_status' => 'banned',
        ]);
        $event = $this->seedEvent($student, ['strike_number' => 4, 'penalty' => 'ban']);
        $this->actingAsRole('moderator');

        $this->postJson("/api/v1/admin/moderation/events/{$event->id}/remove-strike")
            ->assertOk()
            ->assertJsonPath('data.reversed', true);

        $fresh = $student->fresh();
        $this->assertSame(3, (int) $fresh->strikes);
        $this->assertNull($fresh->banned_until, 'Correcting a strike must undo its punishment.');
        $this->assertSame('reversed', $event->fresh()->action);
    }

    public function test_a_moderator_can_ban_and_then_lift_by_hand(): void
    {
        $student = User::factory()->create();
        $this->actingAsRole('moderator');

        $this->postJson("/api/v1/admin/moderation/users/{$student->id}/ban", ['hours' => 48])
            ->assertOk();
        $this->assertNotNull($student->fresh()->banned_until);

        $this->postJson("/api/v1/admin/moderation/users/{$student->id}/ban", ['lift' => true])
            ->assertOk();
        $fresh = $student->fresh();
        $this->assertNull($fresh->banned_until);
        $this->assertNull($fresh->banned_at);
    }

    public function test_the_policy_endpoint_reports_the_configured_ladder(): void
    {
        $this->actingAsRole('moderator');

        $policy = $this->getJson('/api/v1/admin/moderation/policy')->assertOk()->json('data');

        $this->assertSame('warning', $policy['penalties'][1]['action']);
        $this->assertSame(24, $policy['penalties'][4]['hours']);
        $this->assertSame(72, $policy['penalties'][5]['hours']);
        $this->assertSame(168, $policy['penalties'][6]['hours']);
        $this->assertSame('omni-moderation-latest', $policy['model']);
    }

    public function test_offender_list_shows_standing_and_the_next_penalty(): void
    {
        User::factory()->create(['strikes' => 3, 'last_violation_at' => now()]);
        $this->actingAsRole('moderator');

        $users = $this->getJson('/api/v1/admin/moderation/users')->assertOk()->json('data');

        $this->assertCount(1, $users);
        $this->assertSame(3, $users[0]['strikes']);
        // Next one crosses into a 24-hour ban.
        $this->assertSame('ban', $users[0]['nextPenalty']['action']);
        $this->assertSame(24, $users[0]['nextPenalty']['hours']);
    }
}
