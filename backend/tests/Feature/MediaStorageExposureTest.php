<?php

namespace Tests\Feature;

use App\Models\MediaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Uploads must not be reachable except through the authorised route.
 *
 * The bug these lock down was silent and total: `filesystems.media_disk`
 * defaulted to `public`, whose root is `storage/app/public`, and the
 * `public/storage` symlink publishes that directory verbatim. Every
 * upload was therefore fetchable at /storage/<path> with no auth — so
 * MediaController's approved-only gate, and the whole moderation
 * pipeline behind it, could be walked straight past with a URL.
 *
 * The API-level tests elsewhere only ever asked the API. They passed
 * throughout, because the API was never the way in.
 */
class MediaStorageExposureTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_is_configured_to_a_private_disk(): void
    {
        $disk = MediaItem::disk();

        $this->assertNotSame('public', $disk,
            'Media on the public disk is served by the public/storage symlink, '
            .'which bypasses moderation entirely.');

        $this->assertNotSame('public', config('filesystems.disks.'.$disk.'.visibility'),
            "Media disk '{$disk}' is marked publicly visible.");
    }

    public function test_an_upload_is_not_written_to_the_public_disk(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->actingAsUser();

        $created = $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg('kampus.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $path = MediaItem::find($created['id'])->file_path;

        Storage::disk('public')->assertMissing($path);
        Storage::disk(MediaItem::disk())->assertExists($path);
    }

    /**
     * The status gate is enforced per request, so flipping a row to a
     * withheld state takes its bytes away immediately — there is no
     * second, ungated path still serving them.
     */
    public function test_withheld_media_is_unreachable_through_every_route(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->actingAsUser();

        $created = $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg('kampus.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $item = MediaItem::find($created['id']);
        $basename = basename((string) $item->file_path);

        foreach (['pending', 'review', 'blocked', 'removed', 'moderation_error'] as $state) {
            $item->update(['moderation_status' => $state]);

            $this->get('/api/v1/media/'.$item->id.'/file')
                ->assertNotFound("state '{$state}' was served by the id route");

            $this->get('/api/v1/media/file/'.$basename)
                ->assertNotFound("state '{$state}' was served by the filename route");
        }
    }

    /**
     * A file with no MediaItem row has never been through moderation, so
     * there must be no route that serves it on the strength of the path
     * alone.
     */
    public function test_an_orphan_file_on_disk_is_never_served(): void
    {
        Storage::fake('local');
        $this->actingAsUser();

        Storage::disk(MediaItem::disk())->put('media/smuggled.jpg', 'not-really-a-jpeg');

        $this->get('/api/v1/media/file/smuggled.jpg')->assertNotFound();
    }

    /** Path traversal must not escape the media directory. */
    public function test_the_filename_route_rejects_traversal(): void
    {
        $this->actingAsUser();

        foreach (['../.env', '..%2F.env', 'media/../../.env'] as $attempt) {
            $response = $this->get('/api/v1/media/file/'.$attempt);
            $this->assertContains($response->status(), [404, 301, 302],
                "traversal attempt '{$attempt}' returned {$response->status()}");
        }
    }
}
