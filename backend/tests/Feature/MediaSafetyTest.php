<?php

namespace Tests\Feature;

use App\Models\MediaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The rules that keep prohibited imagery off the timeline.
 *
 * The failure these guard against was real and silent: with the moderation
 * provider unreachable, image uploads fell through to a magic-byte check
 * and were approved. Magic bytes prove a file is a JPEG. They say nothing
 * about what the JPEG shows. So nudity, gore and violence would have been
 * published having been "checked" by something that never looked at a
 * single pixel.
 *
 * Text can safely degrade to the offline engine, because that engine really
 * does enforce policy. Media cannot: there is no local model that reads
 * pictures, so "no provider" means "nothing inspected this" and the only
 * honest outcome is to hold it.
 */
class MediaSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function flaggedAs(array $categories): void
    {
        config(['services.moderation.openai_key' => 'sk-test-key']);
        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'omni-moderation-latest',
            'results' => [[
                'flagged' => true,
                'categories' => array_fill_keys($categories, true),
                'category_scores' => array_fill_keys($categories, 0.98),
            ]],
        ])]);
    }

    private function providerClean(): void
    {
        config(['services.moderation.openai_key' => 'sk-test-key']);
        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'omni-moderation-latest',
            'results' => [['flagged' => false, 'categories' => [], 'category_scores' => []]],
        ])]);
    }

    public function test_an_image_is_never_published_when_no_model_inspected_it(): void
    {
        Storage::fake('public');
        $this->actingAsUser();
        // No key at all — the state a fresh install, a revoked key, or an
        // unpaid project all land in.
        config(['services.moderation.openai_key' => '']);

        $created = $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg('holiday.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->assertSame(
            'pending',
            $created['moderationStatus'],
            'An uninspected image must never be approved.',
        );
    }

    /**
     * Uploading the same photo twice used to beat the gate entirely.
     *
     * The first attempt was held and recorded a REVIEW event. The second
     * hit the idempotency cache, which replayed that event as "reviewed",
     * and the controller's `default => null` treated anything it had not
     * enumerated as fine — so the fall-through approved it. An image nothing
     * had ever looked at reached the timeline on the second try.
     *
     * Two fixes meet here: the cache no longer manufactures permission for
     * media, and the controller enumerates what may publish instead of what
     * may not.
     */
    public function test_re_uploading_the_same_image_does_not_get_it_approved(): void
    {
        Storage::fake('public');
        $this->actingAsUser();
        config(['services.moderation.openai_key' => '']);   // nothing can inspect it

        $statuses = [];
        for ($i = 0; $i < 4; $i++) {
            $statuses[] = $this->post('/api/v1/media/mine', [
                'file' => $this->fakeJpeg('same.jpg'),
            ], ['Accept' => 'application/json'])->assertCreated()->json('data.moderationStatus');
        }

        $this->assertSame(['pending', 'pending', 'pending', 'pending'], $statuses,
            'Repeating an upload must not launder it into an approval.');
        $this->assertSame(0, MediaItem::where('moderation_status', 'approved')->count());
    }

    /**
     * The controller must fail closed on a status it does not recognise.
     * The bug above existed because an unenumerated outcome fell through to
     * a path that approves, so this pins the direction of that default.
     */
    public function test_an_unrecognised_outcome_holds_rather_than_publishes(): void
    {
        Storage::fake('public');
        $this->actingAsUser();
        config(['services.moderation.openai_key' => 'sk-test-key']);

        // Flagged only for self-harm, which routes to SUPPORT — publishable
        // for text, but never a reason to publish an unexamined picture.
        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'omni-moderation-latest',
            'results' => [[
                'flagged' => true,
                'categories' => ['self-harm' => true],
                'category_scores' => ['self-harm' => 0.99],
            ]],
        ])]);

        $status = $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg('shot.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.moderationStatus');

        $this->assertNotSame('approved', $status);
    }

    public function test_prohibited_imagery_is_rejected_and_never_stored_as_approved(): void
    {
        Storage::fake('public');
        $this->actingAsUser();

        foreach ([['sexual'], ['violence/graphic'], ['violence'], ['self-harm/instructions']] as $categories) {
            $this->flaggedAs($categories);

            $this->post('/api/v1/media/mine', [
                'file' => $this->fakeJpeg('upload.jpg'),
            ], ['Accept' => 'application/json'])
                ->assertStatus(400)
                ->assertJsonPath('error.code', 'CONTENT_BLOCKED');
        }

        $this->assertSame(
            0,
            MediaItem::where('moderation_status', 'approved')->count(),
            'Flagged media must not be stored as approved.',
        );
    }

    public function test_the_rejection_tells_the_student_why_and_what_happens_next(): void
    {
        Storage::fake('public');
        $this->actingAsUser();
        $this->flaggedAs(['sexual']);

        $message = $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg('upload.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(400)
            ->json('error.message');

        // Naming the reason lets someone fix an honest mistake; a bare
        // "not allowed" just gets retried with the same file.
        $this->assertStringContainsString('çıplaklık', mb_strtolower($message));
        // And the consequence of doing it again is stated where the person
        // who needs it is certain to be reading.
        $this->assertStringContainsString('askıya', mb_strtolower($message));
    }

    public function test_a_clean_image_still_publishes_normally(): void
    {
        Storage::fake('public');
        $this->actingAsUser();
        $this->providerClean();

        $created = $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg('lecture.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->assertSame('approved', $created['moderationStatus']);
    }

    public function test_video_is_held_when_frames_cannot_be_inspected(): void
    {
        Storage::fake('public');
        $this->actingAsUser();
        $this->providerClean();

        // ffmpeg is not installed in CI, so no frame was ever looked at.
        // A clean thumbnail must not be able to vouch for the whole clip.
        $created = $this->post('/api/v1/media/mine', [
            'file' => UploadedFile::fake()->createWithContent(
                'clip.mp4',
                "\x00\x00\x00\x18ftypisom".str_repeat('0', 200),
            )->mimeType('video/mp4'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->assertSame('pending', $created['moderationStatus']);
    }

    public function test_disallowed_file_types_are_refused_before_anything_is_stored(): void
    {
        Storage::fake('public');
        $this->actingAsUser();

        foreach (['payload.exe' => 'application/octet-stream', 'doc.pdf' => 'application/pdf'] as $name => $mime) {
            $this->post('/api/v1/media/mine', [
                'file' => UploadedFile::fake()->create($name, 40, $mime),
            ], ['Accept' => 'application/json'])->assertStatus(400);
        }

        $this->assertSame(0, MediaItem::count(), 'Nothing should have been stored.');
    }

    public function test_a_renamed_file_does_not_get_through_on_its_extension(): void
    {
        Storage::fake('public');
        $this->actingAsUser();

        // Claims to be a JPEG, contains something else entirely.
        $this->post('/api/v1/media/mine', [
            'file' => UploadedFile::fake()->createWithContent('sneaky.jpg', 'MZ'.str_repeat('A', 400)),
        ], ['Accept' => 'application/json'])->assertStatus(400);

        $this->assertSame(0, MediaItem::count());
    }
}
