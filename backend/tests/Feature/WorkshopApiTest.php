<?php

namespace Tests\Feature;

use App\Models\CollaborationPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlaces;
use Tests\TestCase;

/**
 * Real replacement for the Flutter map sheet's previously hardcoded
 * `_workshopEquipment` / `_collaborationBoard` consts, which showed the
 * identical fake list on every workshop-category place.
 */
class WorkshopApiTest extends TestCase
{
    use CreatesPlaces, RefreshDatabase;

    public function test_a_place_with_no_workshop_data_returns_empty_lists(): void
    {
        $this->actingAsUser();
        $place = $this->seedPlace('p-workshop');

        $data = $this->getJson("/api/v1/places/{$place->id}/workshop")->assertOk()->json('data');

        $this->assertSame([], $data['equipment']);
        $this->assertSame([], $data['posts']);
    }

    public function test_workshop_requires_a_real_place(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/v1/places/missing/workshop')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'PLACE_NOT_FOUND');
    }

    public function test_admin_can_create_update_and_delete_equipment(): void
    {
        $place = $this->seedPlace('p-workshop');
        $this->actingAsRole();

        $created = $this->postJson("/api/v1/admin/places/{$place->id}/workshop/equipment", [
            'name' => '3D Yazıcı', 'available' => true,
        ])->assertStatus(201)->json('data');
        $this->assertTrue($created['available']);

        $updated = $this->postJson("/api/v1/admin/places/{$place->id}/workshop/equipment", [
            'id' => $created['id'], 'name' => '3D Yazıcı', 'available' => false,
        ])->assertOk()->json('data');
        $this->assertFalse($updated['available']);

        $listed = $this->getJson("/api/v1/places/{$place->id}/workshop")->json('data.equipment');
        $this->assertCount(1, $listed);
        $this->assertFalse($listed[0]['available']);

        $this->postJson("/api/v1/admin/places/{$place->id}/workshop/equipment/{$created['id']}/delete")
            ->assertOk();
        $this->assertSoftDeleted('workshop_equipment_items', ['id' => $created['id']]);
    }

    public function test_equipment_write_requires_places_manage_permission(): void
    {
        $place = $this->seedPlace('p-workshop');
        $this->actingAsRole('student');

        $this->postJson("/api/v1/admin/places/{$place->id}/workshop/equipment", [
            'name' => '3D Yazıcı',
        ])->assertStatus(403);
    }

    public function test_a_signed_in_student_can_post_to_the_collaboration_board(): void
    {
        $place = $this->seedPlace('p-workshop');
        $me = $this->actingAsUser();

        $post = $this->postJson("/api/v1/places/{$place->id}/workshop/posts", [
            'text' => 'Heykel projesi için model aranıyor',
        ])->assertOk()->json('data');

        $this->assertSame($me->name, $post['authorName']);
        $this->assertSame('Heykel projesi için model aranıyor', $post['text']);

        $listed = $this->getJson("/api/v1/places/{$place->id}/workshop")->json('data.posts');
        $this->assertCount(1, $listed);
        $this->assertSame($post['id'], $listed[0]['id']);
    }

    public function test_a_collaboration_post_is_moderated_like_any_other_text(): void
    {
        $place = $this->seedPlace('p-workshop');
        $this->actingAsUser();

        $this->postJson("/api/v1/places/{$place->id}/workshop/posts", [
            'text' => 'sen bir salaksın',
        ])->assertStatus(400)->assertJsonPath('error.code', 'CONTENT_BLOCKED');
    }

    public function test_an_expired_post_is_not_returned(): void
    {
        $place = $this->seedPlace('p-workshop');
        $me = $this->actingAsUser();
        CollaborationPost::create([
            'id' => 'collab-old', 'place_id' => $place->id, 'author_id' => $me->id,
            'text' => 'eski ilan', 'created_at' => now()->subDays(20), 'expires_at' => now()->subDays(6),
        ]);

        $listed = $this->getJson("/api/v1/places/{$place->id}/workshop")->json('data.posts');
        $this->assertSame([], $listed);
    }

    public function test_admin_can_delete_an_inappropriate_post(): void
    {
        $place = $this->seedPlace('p-workshop');
        $me = $this->actingAsUser();
        $post = CollaborationPost::create([
            'id' => 'collab-1', 'place_id' => $place->id, 'author_id' => $me->id,
            'text' => 'ilan', 'created_at' => now(), 'expires_at' => now()->addDays(14),
        ]);
        $this->actingAsRole();

        $this->postJson("/api/v1/admin/places/{$place->id}/workshop/posts/{$post->id}/delete")
            ->assertOk();
        $this->assertDatabaseMissing('collaboration_posts', ['id' => $post->id]);
    }
}
