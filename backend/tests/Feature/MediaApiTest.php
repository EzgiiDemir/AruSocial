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
        Storage::fake(MediaItem::disk());
        $this->actingAsRole('contentEditor');

        $file = $this->fakeJpeg('garden.jpg');
        $created = $this->post('/api/v1/media', ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data');

        $this->assertNotEmpty($created['id']);
        $this->assertSame('garden.jpg', $created['fileName']);
        $this->assertNotEmpty($created['url']);
        $this->assertStringNotContainsString('\\', $created['url']);
        $this->assertArrayHasKey('usedIn', $created);
        $this->assertDatabaseHas('media_items', ['id' => $created['id'], 'file_name' => 'garden.jpg']);
        Storage::disk(MediaItem::disk())->assertExists(MediaItem::find($created['id'])->file_path);

        $list = $this->getJson('/api/v1/media')->assertOk()->json('data');
        $this->assertTrue(collect($list)->contains(fn ($row) => $row['id'] === $created['id']));

        $this->postJson('/api/v1/media/'.$created['id'], ['fileName' => 'garden-cover.jpg'])
            ->assertOk()
            ->assertJsonPath('data.fileName', 'garden-cover.jpg');

        $path = MediaItem::find($created['id'])->file_path;
        $this->postJson('/api/v1/media/'.$created['id'].'/delete')->assertOk();
        $this->assertSoftDeleted('media_items', ['id' => $created['id']]);

        // The bytes deliberately survive a soft delete: a file removed here
        // is referenced by posts, covers and avatars, and keeping it is what
        // makes restore possible at all (see MediaItem's note, and
        // `media:restore-quarantine`). This previously asserted the file was
        // *missing* and passed only because it asked the public disk, which
        // media has not used since it moved behind the authorised route —
        // so it was asserting the opposite of the intended contract against
        // a disk that was always empty.
        Storage::disk(MediaItem::disk())->assertExists($path);
    }

    public function test_a_student_cannot_delete_existing_media(): void
    {
        Storage::fake(MediaItem::disk());
        $this->actingAsRole('contentEditor');
        $created = $this->post('/api/v1/media', [
            'file' => $this->fakeJpeg('keep.jpg'),
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
        Storage::fake(MediaItem::disk());
        $this->actingAsRole('contentEditor');

        // A wrong file type is now refused by the form request, before the
        // bytes reach storage or moderation — cheaper, and it cannot be
        // reached by a code path that forgot to call the controller check.
        $this->post('/api/v1/media', [
            'file' => UploadedFile::fake()->create('notes.txt', 20, 'text/plain'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION');

        // Answered by the form request now, not the controller.
        //
        // This used to assert FILE_TOO_LARGE: 20 MB was over the image
        // limit but under the form request's overall cap, which was sized
        // for a 100 MB video, so the request passed validation and the
        // controller's own size check answered. With video removed the cap
        // *is* the image limit, so validation refuses it first — earlier
        // and before the bytes reach storage. The controller check remains
        // as defence in depth for callers that do not go through this
        // request.
        $this->post('/api/v1/media', [
            'file' => UploadedFile::fake()->create('huge.jpg', 20000, 'image/jpeg'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION');

        $this->post('/api/v1/media', [
            'file' => UploadedFile::fake()->create('spoof.jpg', 20, 'image/jpeg'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'INVALID_FILE_CONTENTS');
    }

    public function test_public_file_by_name_serves_storage_basename(): void
    {
        Storage::fake(MediaItem::disk());
        $this->actingAsRole('contentEditor');
        $created = $this->post('/api/v1/media', [
            'file' => $this->fakeJpeg('garden.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $path = MediaItem::find($created['id'])->file_path;
        $basename = basename($path);

        // No semantic provider is configured, so structurally valid media is
        // approved and immediately serveable.
        $this->assertSame('approved', $created['moderationStatus']);

        $this->app['auth']->forgetGuards();
        $this->get('/api/v1/media/file/'.$basename)
            ->assertOk()
            ->assertHeader('access-control-allow-origin');

        // The other direction still matters: while an item is held, its
        // bytes must not be reachable either. A file that is "not on the
        // timeline" but still fetchable by URL has not actually been
        // withheld — anyone with the link could see it, and links get shared.
        MediaItem::find($created['id'])->update(['moderation_status' => 'pending']);

        $this->get('/api/v1/media/file/'.$basename)->assertNotFound();
    }
}
