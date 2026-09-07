<?php

namespace Tests\Feature;

use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PersonalGalleryTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_upload_list_and_delete_own_gallery_media(): void
    {
        Storage::fake('public');
        $me = $this->actingAsUser();

        $created = $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg('shot.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->assertSame('approved', $created['moderationStatus']);
        $this->assertDatabaseHas('media_items', [
            'id' => $created['id'], 'user_id' => $me->id, 'file_name' => 'shot.jpg',
            'moderation_status' => 'approved',
        ]);

        $page = $this->getJson('/api/v1/media/mine')->assertOk();
        $page->assertJsonPath('meta.pagination.total', 1);
        $this->assertSame($created['id'], $page->json('data.0.id'));

        $this->postJson('/api/v1/media/mine/'.$created['id'].'/delete')->assertOk();
        $this->assertDatabaseMissing('media_items', ['id' => $created['id']]);
        $this->assertSame(0, $this->getJson('/api/v1/media/mine')->json('meta.pagination.total'));
    }

    public function test_gallery_list_is_isolated_per_user(): void
    {
        Storage::fake('public');
        $other = User::create(['name' => 'Other', 'email' => 'other@arucad.edu.tr', 'password' => bcrypt('x')]);
        $this->actingAsUser($other);
        $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg('other.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->actingAsUser();
        $this->assertSame([], $this->getJson('/api/v1/media/mine')->json('data'));
    }

    public function test_cannot_delete_another_users_personal_media(): void
    {
        Storage::fake('public');
        $other = User::create(['name' => 'Other', 'email' => 'other@arucad.edu.tr', 'password' => bcrypt('x')]);
        $this->actingAsUser($other);
        $id = $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg('other.jpg'),
        ], ['Accept' => 'application/json'])->json('data.id');

        $this->actingAsUser();
        $this->postJson("/api/v1/media/mine/{$id}/delete")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'MEDIA_NOT_FOUND');
        $this->assertDatabaseHas('media_items', ['id' => $id]);
    }

    public function test_student_still_cannot_use_admin_media_library(): void
    {
        $this->actingAsUser();
        $this->getJson('/api/v1/media')->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_admin_media_upload_does_not_set_user_id(): void
    {
        Storage::fake('public');
        $this->actingAsRole('contentEditor');
        $id = $this->post('/api/v1/media', [
            'file' => $this->fakeJpeg('lib.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $this->assertNull(MediaItem::find($id)->user_id);
    }

    public function test_personal_gallery_requires_auth(): void
    {
        $this->getJson('/api/v1/media/mine')->assertStatus(401)->assertJsonPath('error.code', 'AUTH_REQUIRED');
        $this->post('/api/v1/media/mine', [
            'file' => UploadedFile::fake()->create('x.jpg', 20, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertStatus(401);
    }
}
