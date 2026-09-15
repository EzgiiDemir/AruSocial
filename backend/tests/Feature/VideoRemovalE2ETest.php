<?php

namespace Tests\Feature;

use App\Models\MediaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Video is gone, and everything else still works.
 *
 * Removing a feature that reached the upload path, the moderation
 * pipeline, the media library and the feed is exactly the change that
 * breaks the neighbouring features quietly. These are real HTTP requests
 * against the surfaces a student uses, asserting both halves: a clip is
 * refused by name, and photos and text are untouched.
 */
class VideoRemovalE2ETest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config([
            'services.moderation.openai_key' => '',
            'services.local_moderation.binary' => '',
            'moderation.enforcement.enabled' => false,
        ]);
    }

    private function clip(string $name = 'campus.mp4', string $mime = 'video/mp4'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "\x00\x00\x00\x18ftypisom".str_repeat('0', 256),
        )->mimeType($mime);
    }

    private function photo(string $name = 'campus.jpg'): UploadedFile
    {
        // A real JPEG header, so magic-byte validation passes.
        return UploadedFile::fake()->createWithContent(
            $name,
            "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00".str_repeat("\x00", 256)."\xFF\xD9",
        )->mimeType('image/jpeg');
    }

    // ---- video is refused, everywhere it used to be accepted ---------

    /** @return array<string, list<string>> */
    public static function uploadRoutes(): array
    {
        return [
            'personal gallery' => ['/api/v1/media/mine'],
        ];
    }

    #[DataProvider('uploadRoutes')]
    public function test_a_video_upload_is_refused_by_name(string $route): void
    {
        $this->actingAsUser();

        $this->post($route, ['file' => $this->clip()], ['Accept' => 'application/json'])
            ->assertStatus(415)
            ->assertJsonPath('error.code', 'VIDEO_NOT_SUPPORTED');

        $this->assertSame(0, MediaItem::count(),
            'A refused clip must leave no row behind.');
    }

    /**
     * The admin media library too — staff uploads skip *scanning*, not
     * validation, so the feature being gone has to hold there as well.
     */
    public function test_the_admin_library_also_refuses_video(): void
    {
        $this->actingAsRole('contentEditor');

        $this->post('/api/v1/media', ['file' => $this->clip()], ['Accept' => 'application/json'])
            ->assertStatus(415)
            ->assertJsonPath('error.code', 'VIDEO_NOT_SUPPORTED');

        $this->assertSame(0, MediaItem::count());
    }

    /**
     * Renaming a clip must not get it through. The check reads the sniffed
     * MIME type, never the extension.
     */
    public function test_a_video_renamed_as_a_photo_is_still_refused(): void
    {
        $this->actingAsUser();

        $this->post('/api/v1/media/mine',
            ['file' => $this->clip('holiday.jpg', 'video/mp4')],
            ['Accept' => 'application/json'])
            ->assertStatus(415)
            ->assertJsonPath('error.code', 'VIDEO_NOT_SUPPORTED');
    }

    // ---- and everything else is unaffected ---------------------------

    public function test_a_photo_still_uploads_and_publishes_on_the_feed(): void
    {
        $this->actingAsUser();

        $media = $this->post('/api/v1/media/mine', ['file' => $this->photo()],
            ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data');

        $this->assertSame('approved', $media['moderationStatus']);
        $this->assertSame('image/jpeg', $media['mimeType']);

        $id = $this->postJson('/api/v1/feed', [
            'text' => 'Kampüsten güzel bir kare.',
            'imageUrl' => $media['url'],
        ])->assertOk()->assertJsonPath('data.workflowStatus', 'published')->json('data.id');

        $timeline = collect($this->getJson('/api/v1/feed')->assertOk()->json('data'))
            ->pluck('id');
        $this->assertTrue($timeline->contains($id));
    }

    public function test_ordinary_text_still_publishes(): void
    {
        $this->actingAsUser();

        foreach ([
            'Yarın kütüphanede buluşalım, notları paylaşırım.',
            'The life drawing class needs charcoal and a large sketchbook.',
            'Кто-нибудь знает, во сколько открывается библиотека?',
        ] as $text) {
            $this->postJson('/api/v1/feed', ['text' => $text])
                ->assertOk()
                ->assertJsonPath('data.workflowStatus', 'published');
        }
    }

    /**
     * The point of removing video is not to weaken moderation. Abuse is
     * still refused on the same surface.
     */
    public function test_abusive_text_is_still_refused(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/feed', ['text' => 'Siktir git buradan.'])
            ->assertStatus(400);
    }

    /**
     * A genuinely unsupported type still gets the generic answer, so the
     * new video branch did not swallow that case.
     */
    public function test_an_unrelated_file_type_still_gets_the_generic_error(): void
    {
        $this->actingAsUser();

        $this->post('/api/v1/media/mine', [
            'file' => UploadedFile::fake()->create('notes.txt', 4, 'text/plain'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION');
    }
}
