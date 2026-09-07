<?php

namespace Tests\Feature;

use App\Models\ApplicationQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Admin management of the dynamic question schema behind the two-stage
// apply flow — questions are never hardcoded per category, they're rows
// here that a staff member with `applications.manage` can add/reorder/
// retire.
class ApplicationQuestionAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_non_admin_cannot_manage_questions(): void
    {
        $this->actingAsUser();
        $this->getJson('/api/v1/admin/application-questions')->assertStatus(403);
    }

    public function test_admin_can_create_list_and_delete_a_question(): void
    {
        $this->actingAsRole('superAdmin');

        $created = $this->postJson('/api/v1/admin/application-questions', [
            'targetType' => 'sport',
            'stage' => 'preview',
            'type' => 'single_choice',
            'label' => 'Bu sporla daha önce ilgilendiniz mi?',
            'options' => ['Evet', 'Hayır'],
            'required' => true,
            'sortOrder' => 1,
        ])->assertCreated()->json('data');

        $this->assertDatabaseHas('application_questions', ['id' => $created['id'], 'target_type' => 'sport']);

        $list = $this->getJson('/api/v1/admin/application-questions?targetType=sport')
            ->assertOk()->json('data');
        $this->assertCount(1, $list);

        $this->postJson("/api/v1/admin/application-questions/{$created['id']}/delete")->assertOk();
        $this->assertDatabaseMissing('application_questions', ['id' => $created['id']]);
    }

    public function test_upsert_with_an_existing_id_updates_in_place(): void
    {
        $this->actingAsRole('superAdmin');
        $question = ApplicationQuestion::create([
            'id' => 'q-1', 'target_type' => 'career', 'stage' => 'detail',
            'type' => 'text', 'label' => 'CV linki', 'required' => false,
            'sort_order' => 1, 'active' => true,
        ]);

        $this->postJson('/api/v1/admin/application-questions', [
            'id' => $question->id,
            'targetType' => 'career',
            'stage' => 'detail',
            'type' => 'text',
            'label' => 'CV / Portfolyo linki',
            'required' => true,
            'sortOrder' => 1,
        ])->assertOk()->assertJsonPath('data.label', 'CV / Portfolyo linki');

        $this->assertSame(1, ApplicationQuestion::where('target_type', 'career')->count());
    }

    public function test_invalid_target_type_or_stage_is_rejected(): void
    {
        $this->actingAsRole('superAdmin');
        $this->postJson('/api/v1/admin/application-questions', [
            'targetType' => 'not-a-real-type',
            'stage' => 'preview',
            'type' => 'text',
            'label' => 'X',
        ])->assertStatus(422);

        $this->getJson('/api/v1/application-questions')->assertStatus(400);
    }
}
