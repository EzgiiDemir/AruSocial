<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\MediaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A refused API key must not take the whole app down with it.
 *
 * This is the failure that put the install into its current state: the
 * OpenAI key is rejected (revoked, or a project with no billing), a 401 was
 * classified as an outage, and an outage holds everything. Every post came
 * back 503 and every photo sat in the review queue, so the OpenAI layer was
 * switched off by hand in .env just to make the app usable again.
 *
 * An outage is temporary and worth waiting for. A rejected credential is
 * not: it will be rejected identically on the next retry and the one after
 * that, so the queue never drains. It therefore degrades to the local
 * engine — which still enforces real policy — instead of blocking.
 */
class RejectedCredentialsDegradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'moderation.enabled' => false,
            'services.moderation.enabled' => true,
            'services.moderation.openai_key' => 'sk-revoked-key',
            'services.local_moderation.binary' => '',
        ]);
    }

    /** 401 = this key is not going to start working on retry. */
    private function credentialsRejected(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'error' => ['message' => 'Incorrect API key provided', 'type' => 'invalid_request_error'],
        ], 401)]);
    }

    public function test_an_ordinary_post_still_publishes_when_the_key_is_refused(): void
    {
        $this->actingAsUser();
        $this->credentialsRejected();

        $this->postJson('/api/v1/feed', [
            'text' => 'Bugün kütüphanede ders çalıştık, herkese iyi haftalar.',
        ])->assertOk();

        $this->assertSame(1, FeedPost::count(),
            'A refused key must not stop ordinary posts from publishing.');
    }

    public function test_an_ordinary_image_still_publishes_when_the_key_is_refused(): void
    {
        Storage::fake(MediaItem::disk());
        $this->actingAsUser();
        $this->credentialsRejected();

        $created = $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg('kampus.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->assertSame('approved', $created['moderationStatus']);
        $this->assertSame(1, MediaItem::count());
    }

    /**
     * The layer that still works keeps working. Degrading is not the same
     * as switching moderation off — the local engine is exactly why this
     * degrade is acceptable rather than reckless.
     */
    public function test_the_local_engine_still_blocks_when_the_key_is_refused(): void
    {
        $this->actingAsUser();
        $this->credentialsRejected();

        $this->postJson('/api/v1/feed', [
            'text' => 'lanet zenci hepinizden nefret ediyorum',
        ])->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');

        $this->assertSame(0, FeedPost::count());
    }

    /**
     * A genuine outage is still an outage: a 500 says the provider might
     * work on the next try, so content waits rather than slipping past.
     */
    public function test_a_real_outage_still_holds_content(): void
    {
        $this->actingAsUser();
        Http::fake(['api.openai.com/*' => Http::response('upstream boom', 500)]);

        $this->postJson('/api/v1/feed', [
            'text' => 'Bugün kütüphanede ders çalıştık.',
        ])->assertStatus(503)
            ->assertJsonPath('error.code', 'MODERATION_UNAVAILABLE');

        $this->assertSame(0, FeedPost::count());
    }
}
