<?php

namespace Tests\Feature;

use App\Models\MediaItem;
use App\Models\ModerationEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The invariant: no successful visual moderation result, no publication.
 *
 * Every test below is a way the scanner can fail. They all assert the
 * same thing — the image did not become public — because the failure mode
 * that matters is not "the model was wrong", it is "nothing ran and we
 * published anyway". A model can only be as good as its benchmark; this
 * file is about the guarantee the *system* makes regardless of the model.
 */
class ImageModerationSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            // Isolate the visual layer: no gateway, no legacy provider, no
            // local binary, so anything that publishes did so because the
            // image classifier said to.
            'moderation.enabled' => false,
            'services.moderation.enabled' => false,
            'services.moderation.openai_key' => '',
            'services.local_moderation.binary' => '',

            'moderation.image.enabled' => true,
            'moderation.image.base_url' => 'http://image-moderation.test',
            'moderation.image.timeout' => 5,
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

    private function upload(string $name = 'photo.jpg')
    {
        return $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg($name),
        ], ['Accept' => 'application/json']);
    }

    // ---- the happy path still has to work -------------------------

    public function test_a_clean_image_is_scanned_and_then_published(): void
    {
        Storage::fake('local');
        $this->actingAsUser();
        $this->scannerReturns(['normal' => 0.98, 'nsfw' => 0.02]);

        $created = $this->upload()->assertCreated()->json('data');

        $this->assertSame('approved', $created['moderationStatus']);

        // Publication must be *because* the scanner answered, not merely
        // alongside it. If the request was never made, this passed by luck.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/moderate/image'));
    }

    public function test_the_verdict_is_recorded_with_model_and_policy_version(): void
    {
        Storage::fake('local');
        $this->actingAsUser();
        $this->scannerReturns(['normal' => 0.98, 'nsfw' => 0.02]);

        $this->upload()->assertCreated();

        $event = ModerationEvent::where('content_type', 'image')->latest('created_at')->first();
        $this->assertNotNull($event, 'An allowed image must still leave evidence.');
        $this->assertSame('b0d2e6a9', $event->model_version);
        $this->assertSame('image-test-v1', $event->policy_version);
        $this->assertSame(42, $event->latency_ms);
    }

    // ---- thresholds -----------------------------------------------

    public function test_a_score_above_the_block_threshold_is_refused(): void
    {
        Storage::fake('local');
        $this->actingAsUser();
        $this->scannerReturns(['normal' => 0.06, 'nsfw' => 0.94]);

        $this->upload()
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');

        $this->assertSame(0, MediaItem::count(), 'Blocked media must not be stored.');
    }

    public function test_a_score_between_the_thresholds_is_held_for_review(): void
    {
        Storage::fake('local');
        $this->actingAsUser();
        $this->scannerReturns(['normal' => 0.45, 'nsfw' => 0.55]);

        $created = $this->upload()->assertCreated()->json('data');

        $this->assertSame('pending', $created['moderationStatus']);
    }

    /**
     * REVIEW is the system admitting uncertainty. Charging a strike for it
     * punishes people for our own ambiguity, and it is the fastest way to
     * make students stop posting.
     */
    public function test_review_never_creates_a_strike(): void
    {
        Storage::fake('local');
        $user = $this->actingAsUser();
        $this->scannerReturns(['normal' => 0.45, 'nsfw' => 0.55]);

        $this->upload()->assertCreated();

        $this->assertSame(0, (int) $user->fresh()->strikes);
        $this->assertNull($user->fresh()->banned_until);
    }

    // ---- every way the scanner can fail ---------------------------

    public static function scannerFailures(): array
    {
        return [
            'connection refused' => [fn () => Http::fake([
                'image-moderation.test/*' => fn () => throw new ConnectionException('Connection refused'),
            ])],
            'timeout' => [fn () => Http::fake([
                'image-moderation.test/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
            ])],
            'http 500' => [fn () => Http::fake([
                'image-moderation.test/*' => Http::response('upstream exploded', 500),
            ])],
            'http 503 model not loaded' => [fn () => Http::fake([
                'image-moderation.test/*' => Http::response([
                    'detail' => ['code' => 'MODEL_NOT_LOADED', 'message' => 'weights missing'],
                ], 503),
            ])],
            'invalid json' => [fn () => Http::fake([
                'image-moderation.test/*' => Http::response('<html>gateway</html>', 200),
            ])],
            // The nastiest one: a perfectly shaped 200 that never actually
            // says anything. Without an explicit check this parses to an
            // empty score set and reads exactly like "nothing found".
            'success flag missing' => [fn () => Http::fake([
                'image-moderation.test/*' => Http::response(['scores' => ['nsfw' => 0.1]], 200),
            ])],
            'empty scores' => [fn () => Http::fake([
                'image-moderation.test/*' => Http::response(['success' => true, 'scores' => []], 200),
            ])],
            'non numeric score' => [fn () => Http::fake([
                'image-moderation.test/*' => Http::response([
                    'success' => true, 'scores' => ['nsfw' => 'definitely not'],
                ], 200),
            ])],
        ];
    }

    #[DataProvider('scannerFailures')]
    public function test_no_scanner_result_means_no_publication(callable $arrangeFailure): void
    {
        Storage::fake('local');
        $this->actingAsUser();
        $arrangeFailure();

        $response = $this->upload();

        $this->assertNotSame(201, $response->status(),
            'A failed scan must not produce a stored, published upload.');
        $this->assertSame(0, MediaItem::where('moderation_status', 'approved')->count(),
            'No image may be approved when nothing inspected it.');
    }

    #[DataProvider('scannerFailures')]
    public function test_a_scanner_failure_is_never_charged_to_the_user(callable $arrangeFailure): void
    {
        Storage::fake('local');
        $user = $this->actingAsUser();
        $arrangeFailure();

        $this->upload();

        $this->assertSame(0, (int) $user->fresh()->strikes,
            'Our outage is not the student\'s violation.');
    }

    // ---- the configuration itself ---------------------------------

    /**
     * The regression that started all of this: with no classifier
     * configured, images were published after only a format check. Once a
     * classifier is enabled, an unreachable one must not silently fall
     * back to that path.
     */
    public function test_an_enabled_but_unreachable_scanner_does_not_fall_back_to_format_checks(): void
    {
        Storage::fake('local');
        $this->actingAsUser();
        Http::fake(['image-moderation.test/*' => fn () => throw new ConnectionException('down')]);

        $this->upload()->assertStatus(503)
            ->assertJsonPath('error.code', 'MODERATION_UNAVAILABLE');

        $this->assertSame(0, MediaItem::count());
    }

    public function test_the_scanner_receives_bytes_not_a_url(): void
    {
        Storage::fake('local');
        $this->actingAsUser();
        $this->scannerReturns(['normal' => 0.99, 'nsfw' => 0.01]);

        $this->upload();

        // Handing the service a URL would let a user point our own network
        // at anything it can reach.
        Http::assertSent(function ($request) {
            $this->assertTrue($request->isMultipart(),
                'The image must be sent as bytes, never as a URL.');

            return true;
        });
    }

    // ---- validation is not policy ---------------------------------

    public function test_a_corrupt_image_is_a_validation_error_not_a_violation(): void
    {
        Storage::fake('local');
        $user = $this->actingAsUser();
        $this->scannerReturns(['normal' => 0.99, 'nsfw' => 0.01]);

        $this->post('/api/v1/media/mine', [
            'file' => UploadedFile::fake()
                ->createWithContent('broken.jpg', 'MZ'.str_repeat('A', 400)),
        ], ['Accept' => 'application/json'])->assertStatus(400);

        $this->assertSame(0, (int) $user->fresh()->strikes);
        $this->assertSame(0, MediaItem::count());
    }

    /**
     * Flutter is not a security boundary — this is the same endpoint a
     * modified client or a curl one-liner would hit.
     */
    public function test_a_direct_api_upload_is_scanned_like_any_other(): void
    {
        Storage::fake('local');
        $this->actingAsUser();
        $this->scannerReturns(['normal' => 0.03, 'nsfw' => 0.97]);

        $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg('direct.jpg'),
        ], ['Accept' => 'application/json', 'User-Agent' => 'curl/8.0'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');
    }
}
