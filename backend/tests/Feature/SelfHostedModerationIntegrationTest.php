<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\FeedPost;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Real HTTP/controller/database integration coverage for the gateway contract. */
class SelfHostedModerationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(MediaItem::disk());
        config([
            'moderation.enabled' => true,
            'moderation.base_url' => 'http://moderation.test:8080',
            'moderation.timeout' => 2,
            'services.moderation.openai_key' => '',
            'services.local_moderation.binary' => '',
        ]);
    }

    /** The video leg went with the feature on 14 September 2026. */
    public function test_normal_post_image_and_url_are_allowed_and_visible_in_timeline(): void
    {
        Http::fake(['moderation.test:8080/*' => Http::response($this->decision('allow'))]);
        $this->actingAsUser();

        $image = $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg('campus.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.moderationStatus', 'approved')
            ->json('data');

        $payloads = [
            ['text' => 'Bugün kampüste kahve içtik.'],
            ['text' => 'Kampüsten normal bir fotoğraf.', 'imageUrl' => $image['url']],
            ['text' => 'Kampüs etkinliğinden kısa bir not.'],
            ['text' => 'Proje sayfası: https://example.edu/projects/42'],
        ];
        $ids = [];
        foreach ($payloads as $payload) {
            $ids[] = $this->postJson('/api/v1/feed', $payload)
                ->assertOk()
                ->assertJsonPath('data.workflowStatus', 'published')
                ->json('data.id');
        }

        $timelineIds = collect($this->getJson('/api/v1/feed')->assertOk()->json('data'))->pluck('id');
        foreach ($ids as $id) {
            $this->assertTrue($timelineIds->contains($id), "Allowed post {$id} is absent from timeline");
        }
        $this->assertSame(4, FeedPost::count());
        // One upload, not two: the video leg was removed with the feature.
        $this->assertSame(1, MediaItem::where('moderation_status', 'approved')->count());

        // That upload plus the four publication gates all reached the
        // central service. Timeline assertions above prove every ALLOW
        // continued through the existing persistence path.
        Http::assertSentCount(5);
    }

    public function test_clear_violation_is_blocked_without_automatic_account_ban(): void
    {
        Http::fake(['moderation.test:8080/*' => Http::response($this->decision(
            'block',
            ['credible_threat'],
            strikeRecommended: true,
        ))]);
        $user = $this->actingAsUser();

        $this->postJson('/api/v1/feed', ['text' => 'I will kill you tomorrow on campus.'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');

        $this->assertSame(0, FeedPost::count());
        $this->assertSame(1, (int) $user->fresh()->strikes);
        $this->assertNull($user->fresh()->banned_at);
        $this->assertNull($user->fresh()->banned_until);
    }

    public function test_required_provider_error_is_503_and_never_a_violation(): void
    {
        Http::fake(['moderation.test:8080/*' => Http::response([
            'decision' => 'error',
            'categories' => [],
            'provider_errors' => [[
                'provider' => 'qwen3guard',
                'required' => true,
                'message' => 'provider_unavailable',
            ]],
            'degraded' => true,
            'strike_recommended' => false,
        ])]);
        $user = $this->actingAsUser();

        $this->postJson('/api/v1/feed', ['text' => 'Normal bir kampüs paylaşımı.'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'MODERATION_UNAVAILABLE');

        $this->assertSame(0, FeedPost::count());
        $this->assertSame(0, (int) $user->fresh()->strikes);
        $this->assertNull($user->fresh()->banned_at);
        $this->assertNull($user->fresh()->banned_until);
    }

    public function test_optional_provider_failure_is_degraded_allow_and_publishes(): void
    {
        Http::fake(['moderation.test:8080/*' => Http::response([
            'decision' => 'allow',
            'categories' => [],
            'provider_errors' => [[
                'provider' => 'paddleocr',
                'required' => false,
                'message' => 'provider_unavailable',
            ]],
            'degraded' => true,
            'strike_recommended' => false,
        ])]);
        $this->actingAsUser();

        $id = $this->postJson('/api/v1/feed', ['text' => 'Yeni projemizi tamamladık.'])
            ->assertOk()
            ->assertJsonPath('data.workflowStatus', 'published')
            ->json('data.id');

        $this->assertContains($id, collect($this->getJson('/api/v1/feed')->json('data'))->pluck('id'));
    }

    public function test_blocked_dm_is_not_persisted_or_delivered(): void
    {
        Http::fake(['moderation.test:8080/*' => Http::response($this->decision(
            'block',
            ['harassment'],
            strikeRecommended: true,
        ))]);
        $this->actingAsUser();
        $peer = User::factory()->create(['name' => 'Peer Student']);

        $this->postJson("/api/v1/chat/{$peer->name}/messages", ['text' => 'explicit synthetic abuse'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');

        $this->assertSame(0, ChatMessage::withoutGlobalScopes()->count());
    }

    public function test_blocked_media_is_not_stored_or_publicly_addressable(): void
    {
        Http::fake(['moderation.test:8080/*' => Http::response($this->decision(
            'block',
            ['explicit_nsfw'],
            strikeRecommended: true,
        ))]);
        $this->actingAsUser();

        $this->post('/api/v1/media/mine', ['file' => $this->fakeJpeg('blocked.jpg')], [
            'Accept' => 'application/json',
        ])->assertStatus(400)->assertJsonPath('error.code', 'CONTENT_BLOCKED');

        $this->assertSame(0, MediaItem::withoutGlobalScopes()->count());
        Storage::disk(MediaItem::disk())->assertDirectoryEmpty('media');
    }

    /** @param list<string> $categories */
    private function decision(
        string $decision,
        array $categories = [],
        bool $strikeRecommended = false,
    ): array {
        return [
            'decision' => $decision,
            'categories' => $categories,
            'findings' => [],
            'provider_errors' => [],
            'degraded' => false,
            'strike_recommended' => $strikeRecommended,
        ];
    }
}
