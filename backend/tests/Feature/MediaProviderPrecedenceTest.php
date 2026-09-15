<?php

namespace Tests\Feature;

use App\Models\MediaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * For media, the calibrated self-hosted classifier decides — not the
 * legacy remote provider.
 *
 * This is a regression test for a live incident. Enabling the legacy
 * OpenAI layer to fix *text* moderation silently rerouted every image and
 * video away from the self-hosted classifier, because the media path
 * asked "is any remote provider configured?" and that question started
 * answering yes. The remote account had no quota, and an unsafe image
 * uploaded as a student came back `201 approved` and published.
 *
 * Two lessons are encoded here:
 *
 *  - Precedence must follow *measured authority*, not configuration
 *    order. The self-hosted classifier is calibrated against a labelled
 *    set; the remote provider is not, and a dead one is worth less still.
 *  - Turning on a layer must never be able to turn a different layer off.
 *    That is the shape of change that looks additive and is not.
 */
class MediaProviderPrecedenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            // Self-hosted classifier: configured and healthy.
            'moderation.image.enabled' => true,
            'moderation.image.base_url' => 'http://image-moderation.test',
            'moderation.image.thresholds' => ['nsfw' => ['review' => 0.20, 'block' => 0.50]],

            // Legacy remote provider: configured, and broken exactly the
            // way the real account was — quota exhausted.
            'services.moderation.enabled' => true,
            'services.moderation.openai_key' => 'sk-test-no-quota',
            'services.moderation.endpoint' => 'https://api.openai.test/v1/moderations',

            // Not the self-hosted gateway; that path is separate.
            'moderation.enabled' => false,
        ]);
    }

    private function fakeUpstreams(float $nsfw, int $remoteStatus): void
    {
        Http::fake([
            'image-moderation.test/*' => Http::response([
                'success' => true, 'model' => 'm', 'model_version' => 'v',
                'scores' => ['nsfw' => $nsfw, 'normal' => 1 - $nsfw], 'latency_ms' => 5,
            ]),
            'api.openai.test/*' => Http::response(
                ['error' => ['message' => 'Too Many Requests', 'type' => 'invalid_request_error']],
                $remoteStatus,
            ),
        ]);
    }

    /** The incident, reduced to one assertion. */
    public function test_an_unsafe_image_is_blocked_even_when_the_remote_provider_is_dead(): void
    {
        Storage::fake('local');
        $this->fakeUpstreams(nsfw: 0.99, remoteStatus: 429);
        $this->actingAsUser();

        $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg('unsafe.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');

        $this->assertSame(0, MediaItem::count(),
            'An unsafe upload was stored while the remote provider was down.');
    }

    /**
     * The other half. A fix that holds everything would also pass the test
     * above and would make the uploader useless.
     */
    public function test_a_safe_image_still_publishes_when_the_remote_provider_is_dead(): void
    {
        Storage::fake('local');
        $this->fakeUpstreams(nsfw: 0.01, remoteStatus: 429);
        $this->actingAsUser();

        $created = $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg('kampus.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->assertSame('approved', $created['moderationStatus']);
    }

    /**
     * The self-hosted classifier must actually be consulted, rather than
     * the request happening to succeed for some other reason.
     */
    public function test_the_self_hosted_classifier_is_the_one_that_decided(): void
    {
        Storage::fake('local');
        $this->fakeUpstreams(nsfw: 0.99, remoteStatus: 429);
        $this->actingAsUser();

        $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg('unsafe.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(400);

        $hitClassifier = collect(Http::recorded())->contains(
            fn ($pair) => str_contains($pair[0]->url(), 'image-moderation.test'),
        );
        $this->assertTrue($hitClassifier, 'The self-hosted classifier was never called.');
    }

    /**
     * A remote provider that is working must not be able to override the
     * calibrated classifier's block either. "Someone else said it was
     * fine" is not evidence about these pixels.
     */
    public function test_a_healthy_remote_provider_cannot_override_a_local_block(): void
    {
        Storage::fake('local');
        Http::fake([
            'image-moderation.test/*' => Http::response([
                'success' => true, 'model' => 'm', 'model_version' => 'v',
                'scores' => ['nsfw' => 0.99, 'normal' => 0.01], 'latency_ms' => 5,
            ]),
            'api.openai.test/*' => Http::response([
                'results' => [['flagged' => false, 'categories' => [], 'category_scores' => []]],
                'model' => 'omni-moderation-latest',
            ]),
        ]);
        $this->actingAsUser();

        $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg('unsafe.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(400);
    }
}
