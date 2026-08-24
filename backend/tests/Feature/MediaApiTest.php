<?php

namespace Tests\Feature;

use App\Models\MediaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_content_editor_can_list_upload_update_and_delete_media(): void
    {
        Storage::fake('public');
        $this->actingAsRole('contentEditor');

        $file = UploadedFile::fake()->create('garden.jpg', 20, 'image/jpeg');
        $created = $this->post('/api/v1/media', ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data');

        $this->assertNotEmpty($created['id']);
        $this->assertSame('garden.jpg', $created['fileName']);
        $this->assertNotEmpty($created['url']);
        $this->assertStringNotContainsString('\\', $created['url']);
        $this->assertArrayHasKey('usedIn', $created);
        $this->assertDatabaseHas('media_items', ['id' => $created['id'], 'file_name' => 'garden.jpg']);
        Storage::disk('public')->assertExists(MediaItem::find($created['id'])->file_path);

        $list = $this->getJson('/api/v1/media')->assertOk()->json('data');
        $this->assertTrue(collect($list)->contains(fn ($row) => $row['id'] === $created['id']));

        $this->postJson('/api/v1/media/'.$created['id'], ['fileName' => 'garden-cover.jpg'])
            ->assertOk()
            ->assertJsonPath('data.fileName', 'garden-cover.jpg');

        $path = MediaItem::find($created['id'])->file_path;
        $this->postJson('/api/v1/media/'.$created['id'].'/delete')->assertOk();
        $this->assertDatabaseMissing('media_items', ['id' => $created['id']]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_student_cannot_delete_existing_media(): void
    {
        Storage::fake('public');
        $this->actingAsRole('contentEditor');
        $created = $this->post('/api/v1/media', [
            'file' => UploadedFile::fake()->create('keep.jpg', 20, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->actingAsUser();
        $this->postJson('/api/v1/media/'.$created['id'].'/delete')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertDatabaseHas('media_items', ['id' => $created['id']]);
    }

    public function test_a_student_cannot_read_or_write_media(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/v1/media')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
        $this->post('/api/v1/media', [
            'file' => UploadedFile::fake()->create('x.jpg', 20, 'image/jpeg'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_unauthenticated_media_access_is_401(): void
    {
        $this->getJson('/api/v1/media')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
    }

    public function test_upload_rejects_non_images_and_oversize_files(): void
    {
        Storage::fake('public');
        $this->actingAsRole('contentEditor');

        $this->post('/api/v1/media', [
            'file' => UploadedFile::fake()->create('notes.txt', 20, 'text/plain'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'UNSUPPORTED_FILE_TYPE');

        $this->post('/api/v1/media', [
            'file' => UploadedFile::fake()->create('huge.jpg', 9000, 'image/jpeg'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'FILE_TOO_LARGE');
    }
}
