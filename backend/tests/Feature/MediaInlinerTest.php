<?php

namespace Tests\Feature;

use App\Models\MediaItem;
use App\Services\Moderation\MediaInliner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Getting an uploaded image in front of the moderation provider.
 *
 * This class had no tests, and was broken in three separate ways at once:
 * it read from `disk('public')` while uploads live on the private media
 * disk; it treated `/api/v1/media/{id}/file` as if the id were a path; and
 * it handed a remote URL's path to the local branch. Every one of those
 * ends at `toDataUri` returning null, and the caller drops nulls without
 * comment — so images reached the provider as an empty list and the whole
 * image pipeline was inert while every test around it stayed green.
 *
 * These assert the resolution itself, because that is the part that failed
 * silently.
 */
class MediaInlinerTest extends TestCase
{
    use RefreshDatabase;

    /** A one-pixel PNG, so mimeType() has something real to read. */
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\x0aIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\x0d\x0a\x2d\xb4\x00\x00\x00\x00IEND\xaeB\x60\x82";

    private function storedItem(string $id = 'media-inline-1'): MediaItem
    {
        Storage::disk(MediaItem::disk())->put("media/{$id}.png", self::PNG);

        return MediaItem::create([
            'id' => $id,
            'file_path' => "media/{$id}.png",
            'file_name' => 'shot.png',
            'mime_type' => 'image/png',
            'size_bytes' => strlen(self::PNG),
            'uploaded_at' => now(),
            'uploaded_by' => 'test',
            'moderation_status' => 'approved',
        ]);
    }

    public function test_an_api_media_url_resolves_to_the_stored_key(): void
    {
        Storage::fake(MediaItem::disk());
        $item = $this->storedItem();

        $this->assertSame(
            $item->file_path,
            MediaInliner::localPathFor("http://127.0.0.1:4000/api/v1/media/{$item->id}/file"),
            'The id in the URL is not a storage key; it has to be looked up.',
        );
    }

    public function test_an_upload_is_inlined_from_the_media_disk(): void
    {
        Storage::fake(MediaItem::disk());
        $item = $this->storedItem();

        $uri = MediaInliner::toDataUri($item->url());

        $this->assertNotNull($uri, 'A stored upload must reach the provider, not be dropped.');
        $this->assertStringStartsWith('data:image/png;base64,', $uri);
        $this->assertSame(self::PNG, base64_decode(substr($uri, strlen('data:image/png;base64,'))));
    }

    /**
     * The disk is read from config rather than named in the class. If that
     * regresses, this is what notices.
     */
    public function test_the_public_disk_is_not_consulted(): void
    {
        Storage::fake(MediaItem::disk());
        Storage::fake('public');
        $item = $this->storedItem();

        $this->assertNotNull(MediaInliner::toDataUri($item->url()));
        Storage::disk('public')->assertMissing($item->file_path);
    }

    /** Withheld media is exactly what most needs inspecting. */
    public function test_a_soft_deleted_item_still_resolves(): void
    {
        Storage::fake(MediaItem::disk());
        $item = $this->storedItem();
        $item->delete();

        $this->assertNotNull(MediaInliner::toDataUri($item->url()));
    }

    public function test_a_bare_storage_key_is_inlined(): void
    {
        Storage::fake(MediaItem::disk());
        $item = $this->storedItem();

        $this->assertNotNull(MediaInliner::toDataUri($item->file_path));
    }

    public function test_a_remote_url_is_passed_through_rather_than_read_locally(): void
    {
        Storage::fake(MediaItem::disk());

        $url = 'https://cdn.example.com/photo.jpg';

        $this->assertNull(
            MediaInliner::localPathFor($url),
            "Another host's path is not a key on our disk.",
        );
        $this->assertSame($url, MediaInliner::toDataUri($url));
    }

    public function test_an_unreachable_host_is_refused_rather_than_sent(): void
    {
        Storage::fake(MediaItem::disk());

        $this->assertNull(MediaInliner::toDataUri('http://192.168.1.5/photo.jpg'));
    }

    public function test_a_data_uri_is_returned_unchanged(): void
    {
        $uri = 'data:image/png;base64,'.base64_encode(self::PNG);

        $this->assertSame($uri, MediaInliner::toDataUri($uri));
    }

    public function test_a_missing_file_yields_null_rather_than_throwing(): void
    {
        Storage::fake(MediaItem::disk());

        $this->assertNull(MediaInliner::toDataUri('media/nothing-here.png'));
    }

    /** Non-images go to VideoModerator, not the image endpoint. */
    public function test_a_non_image_is_not_inlined(): void
    {
        Storage::fake(MediaItem::disk());
        Storage::disk(MediaItem::disk())->put('media/notes.pdf', "%PDF-1.4\n%%EOF\n");

        $this->assertNull(MediaInliner::toDataUri('media/notes.pdf'));
    }
}
