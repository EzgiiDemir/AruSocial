<?php

namespace Tests\Feature;

use App\Jobs\ModerateImageJob;
use App\Models\MediaItem;
use App\Models\ModerationEvent;
use App\Services\Moderation\Image\ImageModerationRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Queued image moderation.
 *
 * The property under test is that the queue cannot create a way to
 * publish. An image is written as `pending` before the job is dispatched,
 * and only the job can move it forward — so a stopped worker, a lost job,
 * a crash mid-run or a duplicate delivery all leave the image private.
 * Safety here is the shape of the code, not a check somebody remembered.
 */
class ImageModerationQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The test queue connection is `sync`, which would run the job
        // inline during the upload request — so a scanner failure would
        // surface as a 500 from the upload rather than as a queued retry,
        // and none of these tests would be exercising the queued path at
        // all. Faking holds the job so each test runs it deliberately.
        Queue::fake();

        config([
            'moderation.enabled' => false,
            'services.moderation.enabled' => false,
            'services.moderation.openai_key' => '',
            'services.local_moderation.binary' => '',

            'moderation.image.enabled' => true,
            'moderation.image.async' => true,
            'moderation.image.base_url' => 'http://image-moderation.test',
            'moderation.image.policy_version' => 'image-test-v1',
            'moderation.image.thresholds' => [
                'nsfw' => ['review' => 0.35, 'block' => 0.85],
            ],
        ]);
    }

    /** @param array<string, float> $scores */
    private function scannerReturns(array $scores): void
    {
        Http::fake(['image-moderation.test/*' => Http::response([
            'success' => true,
            'model' => 'Falconsai/nsfw_image_detection',
            'model_version' => 'b0d2e6a9',
            'scores' => $scores,
            'latency_ms' => 42,
        ])]);
    }

    private function upload()
    {
        return $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg('photo.jpg'),
        ], ['Accept' => 'application/json']);
    }

    public function test_an_upload_is_pending_and_queued_not_published(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->actingAsUser();

        $created = $this->upload()->assertCreated()->json('data');

        $this->assertSame('pending', $created['moderationStatus']);
        Queue::assertPushed(ModerateImageJob::class,
            fn (ModerateImageJob $job) => $job->mediaItemId === $created['id']);
    }

    /**
     * The worker never runs. This is the scenario that has to be boring:
     * the image simply stays where it was.
     */
    public function test_a_worker_that_never_runs_leaves_the_image_private(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->actingAsUser();

        $created = $this->upload()->assertCreated()->json('data');

        // Nothing processes the queue.
        $this->assertSame('pending', MediaItem::find($created['id'])->moderation_status);
        $this->get('/api/v1/media/'.$created['id'].'/file')->assertNotFound();
    }

    public function test_the_job_publishes_a_clean_image(): void
    {
        Storage::fake('local');
        $this->actingAsUser();
        $this->scannerReturns(['normal' => 0.98, 'nsfw' => 0.02]);

        $created = $this->upload()->assertCreated()->json('data');
        (new ModerateImageJob($created['id']))->handle(app(ImageModerationRunner::class));

        $this->assertSame('approved', MediaItem::find($created['id'])->moderation_status);
    }

    public function test_the_job_blocks_an_unsafe_image(): void
    {
        Storage::fake('local');
        $this->actingAsUser();
        $this->scannerReturns(['normal' => 0.03, 'nsfw' => 0.97]);

        $created = $this->upload()->assertCreated()->json('data');
        (new ModerateImageJob($created['id']))->handle(app(ImageModerationRunner::class));

        $item = MediaItem::find($created['id']);
        $this->assertSame('blocked', $item->moderation_status);
        $this->get('/api/v1/media/'.$item->id.'/file')->assertNotFound();
    }

    /**
     * At-least-once delivery is normal. Two deliveries must not mean two
     * verdicts, two evidence rows or two strikes.
     */
    public function test_running_the_job_twice_is_idempotent(): void
    {
        Storage::fake('local');
        $user = $this->actingAsUser();
        $this->scannerReturns(['normal' => 0.03, 'nsfw' => 0.97]);

        $created = $this->upload()->assertCreated()->json('data');
        $runner = app(ImageModerationRunner::class);

        (new ModerateImageJob($created['id']))->handle($runner);
        $strikesAfterFirst = (int) $user->fresh()->strikes;
        $eventsAfterFirst = ModerationEvent::where('content_id', $created['id'])->count();

        (new ModerateImageJob($created['id']))->handle($runner);
        (new ModerateImageJob($created['id']))->handle($runner);

        $this->assertSame('blocked', MediaItem::find($created['id'])->moderation_status);
        $this->assertSame($eventsAfterFirst,
            ModerationEvent::where('content_id', $created['id'])->count(),
            'A repeated delivery must not record a second verdict.');
        $this->assertSame($strikesAfterFirst, (int) $user->fresh()->strikes,
            'A repeated delivery must not punish twice.');
    }

    /**
     * A scan that could not happen must be retried, not resolved. The job
     * throws so the queue re-delivers it — and throwing is the only thing
     * that could leave the image unpublished *and* recoverable.
     */
    public function test_a_scanner_failure_throws_so_the_job_retries(): void
    {
        Storage::fake('local');
        $this->actingAsUser();
        Http::fake(['image-moderation.test/*' => Http::response('boom', 500)]);

        $created = $this->upload()->assertCreated()->json('data');

        try {
            (new ModerateImageJob($created['id']))
                ->handle(app(ImageModerationRunner::class));
            $this->fail('An unavailable scanner must not resolve the job quietly.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNotSame('approved', MediaItem::find($created['id'])->moderation_status);
    }

    /** Retries exhausted: loud, recorded, and still not published. */
    public function test_final_failure_marks_the_item_as_moderation_error(): void
    {
        Storage::fake('local');
        $this->actingAsUser();
        Http::fake(['image-moderation.test/*' => Http::response('boom', 500)]);

        $created = $this->upload()->assertCreated()->json('data');

        (new ModerateImageJob($created['id']))->failed(new \RuntimeException('scanner down'));

        $item = MediaItem::find($created['id']);
        $this->assertSame('moderation_error', $item->moderation_status);
        $this->get('/api/v1/media/'.$item->id.'/file')->assertNotFound();
    }

    /**
     * If the provider is switched off between dispatch and execution, the
     * job must not treat "no provider" as "no problem".
     */
    public function test_a_job_without_a_provider_leaves_the_image_pending(): void
    {
        Storage::fake('local');
        $this->actingAsUser();
        $this->scannerReturns(['normal' => 0.99, 'nsfw' => 0.01]);

        $created = $this->upload()->assertCreated()->json('data');

        config(['moderation.image.enabled' => false]);
        (new ModerateImageJob($created['id']))->handle(app(ImageModerationRunner::class));

        $this->assertSame('pending', MediaItem::find($created['id'])->moderation_status);
    }

    /** A file that vanished cannot be vouched for. */
    public function test_a_missing_file_is_an_error_not_an_approval(): void
    {
        Storage::fake('local');
        $this->actingAsUser();
        $this->scannerReturns(['normal' => 0.99, 'nsfw' => 0.01]);

        $created = $this->upload()->assertCreated()->json('data');
        Storage::disk(MediaItem::disk())->delete(MediaItem::find($created['id'])->file_path);

        try {
            (new ModerateImageJob($created['id']))
                ->handle(app(ImageModerationRunner::class));
        } catch (\RuntimeException) {
            // ERROR verdicts throw to trigger a retry.
        }

        $this->assertNotSame('approved', MediaItem::find($created['id'])->moderation_status);
    }
}
