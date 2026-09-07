<?php

namespace Tests\Feature;

use App\Models\OnboardingStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Real replacement for the previously fully-hardcoded `onboardingSteps`
 * const in `onboarding_config.dart` — the checklist content (title/detail/
 * grouping/ordering) is now admin-editable; only completion state lived in
 * the database before this.
 */
class OnboardingStepApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_seeds_the_original_twelve_steps(): void
    {
        $this->actingAsUser();

        $listed = $this->getJson('/api/v1/onboarding-steps')->assertOk()->json('data');
        $this->assertCount(13, $listed);
        $this->assertSame('day1-orientation', $listed[0]['id']);
        $this->assertSame('1. Gün', $listed[0]['group']);
    }

    public function test_inactive_steps_are_hidden_from_the_public_list(): void
    {
        $this->actingAsUser();
        OnboardingStep::where('id', 'week3-kyrenia')->update(['active' => false]);

        $listed = $this->getJson('/api/v1/onboarding-steps')->assertOk()->json('data');
        $this->assertFalse(collect($listed)->contains('id', 'week3-kyrenia'));
    }

    public function test_admin_index_shows_inactive_steps_too(): void
    {
        $this->actingAsRole();
        OnboardingStep::where('id', 'week3-kyrenia')->update(['active' => false]);

        $listed = $this->getJson('/api/v1/admin/onboarding-steps')->assertOk()->json('data');
        $this->assertCount(13, $listed);
        $hidden = collect($listed)->firstWhere('id', 'week3-kyrenia');
        $this->assertFalse($hidden['active']);
    }

    public function test_admin_can_create_update_and_delete_a_step(): void
    {
        $this->actingAsRole();

        $created = $this->postJson('/api/v1/admin/onboarding-steps', [
            'id' => 'week5-extra', 'groupLabel' => '5. Hafta', 'title' => 'Ekstra adım',
            'detail' => 'Yeni bir adım.', 'sortOrder' => 13,
        ])->assertStatus(201)->json('data');
        $this->assertSame('info', $created['actionKind']);

        $updated = $this->postJson('/api/v1/admin/onboarding-steps', [
            'id' => 'week5-extra', 'groupLabel' => '5. Hafta', 'title' => 'Güncellendi',
            'detail' => 'Değişti.', 'actionKind' => 'list', 'refId' => 'clubs',
        ])->assertOk()->json('data');
        $this->assertSame('Güncellendi', $updated['title']);
        $this->assertSame('clubs', $updated['refId']);

        $this->postJson('/api/v1/admin/onboarding-steps/week5-extra/delete')->assertOk();
        $this->assertDatabaseMissing('onboarding_steps', ['id' => 'week5-extra']);
    }

    public function test_step_write_requires_onboarding_manage_permission(): void
    {
        $this->actingAsRole('student');

        $this->postJson('/api/v1/admin/onboarding-steps', [
            'id' => 'x', 'groupLabel' => 'g', 'title' => 't', 'detail' => 'd',
        ])->assertStatus(403);
    }

    public function test_step_upsert_rejects_an_unknown_action_kind(): void
    {
        $this->actingAsRole();

        $this->postJson('/api/v1/admin/onboarding-steps', [
            'id' => 'x', 'groupLabel' => 'g', 'title' => 't', 'detail' => 'd',
            'actionKind' => 'not-a-kind',
        ])->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION');
    }

    public function test_existing_onboarding_progress_still_resolves_against_seeded_steps(): void
    {
        $me = $this->actingAsUser();

        $this->postJson('/api/v1/me/onboarding/day1-id', ['completed' => true])->assertOk();

        $steps = collect($this->getJson('/api/v1/onboarding-steps')->json('data'));
        $this->assertTrue($steps->contains('id', 'day1-id'));
        $progress = $this->getJson('/api/v1/me/onboarding')->json('data');
        $this->assertContains('day1-id', $progress['done']);
    }
}
