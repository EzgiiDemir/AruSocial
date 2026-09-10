<?php

namespace Tests\Feature;

use App\Models\MediaItem;
use App\Services\Moderation\Image\ImageModerationRunner;
use App\Services\Moderation\Image\ImageVerdict;
use App\Services\Moderation\Image\VideoModerationRunner;
use App\Services\Moderation\VideoModerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * No video publishes without a verified visual result.
 *
 * Video used to skip the image gate entirely — the check was written
 * `if (! $isVideo && ...)` — and fall through to a fallback ending in
 * "no local classifier configured, so approve". Every clip published
 * with no frame ever inspected, while photographs beside them were being
 * scanned properly. It was the widest hole in the pipeline and the
 * hardest to notice, because nothing failed.
 */
class VideoModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'moderation.enabled' => false,
            'services.moderation.enabled' => false,
            'services.moderation.openai_key' => '',
            'services.local_moderation.binary' => '',
            'moderation.image.enabled' => true,
            'moderation.image.base_url' => 'http://image-moderation.test',
            'moderation.image.thresholds' => ['nsfw' => ['review' => 0.20, 'block' => 0.50]],
        ]);
    }

    private function scannerReturns(float $nsfw): void
    {
        Http::fake(['image-moderation.test/*' => Http::response([
            'success' => true, 'model' => 'm', 'model_version' => 'v',
            'scores' => ['nsfw' => $nsfw, 'normal' => 1 - $nsfw], 'latency_ms' => 5,
        ])]);
    }

    private function uploadVideo()
    {
        return $this->post('/api/v1/media/mine', [
            'file' => UploadedFile::fake()
                ->createWithContent('clip.mp4', "\x00\x00\x00\x18ftypisom".str_repeat('0', 400))
                ->mimeType('video/mp4'),
        ], ['Accept' => 'application/json']);
    }

    /**
     * The regression itself. FFmpeg is not installed in this environment,
     * which is the same state production is in today — so this is not a
     * hypothetical branch, it is the live one.
     */
    public function test_a_video_is_never_published_without_frame_analysis(): void
    {
        Storage::fake('local');
        $this->actingAsUser();
        $this->scannerReturns(0.01);

        $response = $this->uploadVideo();

        $this->assertNotSame(201, $response->status(),
            'A video was published with no frame ever inspected.');
        $this->assertSame(0, MediaItem::where('moderation_status', 'approved')->count());
    }

    public function test_missing_ffmpeg_is_an_error_not_an_approval(): void
    {
        $verdict = app(VideoModerationRunner::class)->scanFile(__FILE__);

        $this->assertSame(ImageVerdict::ERROR, $verdict->decision);
        $this->assertStringStartsWith('FRAMES_', (string) $verdict->reasonCode);
    }

    public function test_a_video_upload_costs_the_uploader_no_strike(): void
    {
        Storage::fake('local');
        $user = $this->actingAsUser();
        $this->scannerReturns(0.01);

        $this->uploadVideo();

        // Our missing FFmpeg is not the student's violation.
        $this->assertSame(0, (int) $user->fresh()->strikes);
    }

    // ---- aggregation, with extraction stubbed ----------------------

    /**
     * A fake extractor, so frame aggregation can be tested without
     * FFmpeg. The frames themselves are irrelevant — what matters is how
     * their verdicts combine.
     *
     * @param  list<string>  $frames
     */
    private function runnerWithFrames(array $frames, bool $available = true): VideoModerationRunner
    {
        $extractor = new class($frames, $available) extends VideoModerator
        {
            public function __construct(private array $stubFrames, private bool $stubAvailable) {}

            public function extractFrames(string $absolutePath, ?int $frameCount = null): array
            {
                return [
                    'available' => $this->stubAvailable,
                    'frames' => $this->stubFrames,
                    'reason' => $this->stubAvailable ? null : 'ffmpeg_missing',
                ];
            }
        };

        return new VideoModerationRunner(
            new ImageModerationRunner,
            $extractor,
        );
    }

    /** Data URIs so no real files are needed. */
    private function frame(): string
    {
        return 'data:image/jpeg;base64,'.base64_encode("\xFF\xD8\xFF\xE0stub");
    }

    /**
     * The aggregation rule that matters. Averaging is how a ninety-second
     * clip with four seconds of explicit content passes — the other
     * eighty-six seconds outvote it.
     */
    public function test_one_explicit_frame_blocks_the_whole_clip(): void
    {
        // Every frame clean except the scanner's answer, which is the same
        // for all of them here; the block threshold decides.
        $this->scannerReturns(0.97);

        $verdict = $this->runnerWithFrames([$this->frame(), $this->frame(), $this->frame()])
            ->scanFile('irrelevant');

        $this->assertSame(ImageVerdict::BLOCK, $verdict->decision);
    }

    public function test_a_clip_of_clean_frames_is_allowed(): void
    {
        $this->scannerReturns(0.01);

        $verdict = $this->runnerWithFrames([$this->frame(), $this->frame()])
            ->scanFile('irrelevant');

        $this->assertSame(ImageVerdict::ALLOW, $verdict->decision);
    }

    public function test_borderline_frames_hold_the_clip_for_review(): void
    {
        $this->scannerReturns(0.30);

        $verdict = $this->runnerWithFrames([$this->frame(), $this->frame()])
            ->scanFile('irrelevant');

        $this->assertSame(ImageVerdict::REVIEW, $verdict->decision);
    }

    /**
     * A scanner failure part-way through must not look like a clean
     * result for the frames that did answer.
     */
    public function test_a_scanner_failure_mid_clip_holds_the_video(): void
    {
        Http::fake(['image-moderation.test/*' => Http::response('boom', 500)]);

        $verdict = $this->runnerWithFrames([$this->frame(), $this->frame()])
            ->scanFile('irrelevant');

        $this->assertSame(ImageVerdict::ERROR, $verdict->decision);
    }

    public function test_extraction_producing_no_frames_is_an_error(): void
    {
        $this->scannerReturns(0.01);

        $verdict = $this->runnerWithFrames([])->scanFile('irrelevant');

        $this->assertSame(ImageVerdict::ERROR, $verdict->decision);
        $this->assertSame('FRAMES_NONE', $verdict->reasonCode);
    }

    public function test_an_unreadable_frame_is_an_error(): void
    {
        $this->scannerReturns(0.01);

        $verdict = $this->runnerWithFrames(['/no/such/frame.jpg'])->scanFile('irrelevant');

        $this->assertSame(ImageVerdict::ERROR, $verdict->decision);
        $this->assertSame('FRAME_UNREADABLE', $verdict->reasonCode);
    }

    /** Staff video is exempt like staff photographs. */
    public function test_staff_video_is_not_scanned(): void
    {
        Storage::fake('local');
        $this->actingAsRole('contentEditor');
        $this->scannerReturns(0.99);

        $created = $this->uploadVideo()->assertCreated()->json('data');

        $this->assertSame('approved', $created['moderationStatus']);
    }
}
