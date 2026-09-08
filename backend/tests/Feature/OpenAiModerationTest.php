<?php

namespace Tests\Feature;

use App\Models\ModerationEvent;
use App\Services\Moderation\ContentModerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The OpenAI moderation path, with the provider faked.
 *
 * These cover the parts that only matter when a remote model is in play:
 * that its verdict is honoured, that an outage holds content instead of
 * publishing it, and that the API key never leaves the server.
 */
class OpenAiModerationTest extends TestCase
{
    use RefreshDatabase;

    private function withProvider(array $response, int $status = 200): void
    {
        config(['services.moderation.openai_key' => 'sk-test-key']);
        Http::fake([
            'api.openai.com/*' => Http::response($response, $status),
        ]);
    }

    private function flagged(array $categories, array $scores = []): array
    {
        return [
            'model' => 'omni-moderation-latest',
            'results' => [[
                'flagged' => true,
                'categories' => $categories,
                'category_scores' => $scores ?: array_map(fn () => 0.97, $categories),
            ]],
        ];
    }

    private function clean(): array
    {
        return [
            'model' => 'omni-moderation-latest',
            'results' => [[
                'flagged' => false,
                'categories' => ['hate' => false, 'violence' => false],
                'category_scores' => ['hate' => 0.001, 'violence' => 0.002],
            ]],
        ];
    }

    public function test_content_the_model_flags_is_rejected_and_never_stored(): void
    {
        $user = $this->actingAsUser();
        $this->withProvider($this->flagged(['harassment' => true]));

        $response = $this->postJson('/api/v1/feed', [
            'text' => 'Something the local list does not know about.',
        ]);

        $response->assertStatus(400)->assertJsonPath('error.code', 'CONTENT_BLOCKED');
        $this->assertSame(0, \App\Models\FeedPost::count(), 'Rejected content must never be written.');
        $this->assertSame(1, (int) $user->fresh()->strikes);
    }

    public function test_content_the_model_clears_is_published(): void
    {
        $this->actingAsUser();
        $this->withProvider($this->clean());

        $this->postJson('/api/v1/feed', ['text' => 'Kütüphanede ders çalışıyoruz.'])->assertOk();
        $this->assertSame(1, \App\Models\FeedPost::count());
    }

    public function test_a_provider_outage_holds_content_instead_of_publishing_it(): void
    {
        $this->actingAsUser();
        config(['services.moderation.openai_key' => 'sk-test-key']);
        Http::fake(['api.openai.com/*' => Http::response('gateway timeout', 504)]);

        $response = $this->postJson('/api/v1/feed', ['text' => 'Tamamen normal bir gönderi.']);

        $response->assertStatus(503)->assertJsonPath('error.code', 'MODERATION_UNAVAILABLE');
        $this->assertSame(0, \App\Models\FeedPost::count(), 'Unchecked content must not be published.');
    }

    public function test_an_outage_does_not_cost_the_user_a_strike(): void
    {
        $user = $this->actingAsUser();
        config(['services.moderation.openai_key' => 'sk-test-key']);
        Http::fake(['api.openai.com/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('down')]);

        $this->postJson('/api/v1/feed', ['text' => 'Normal bir gönderi.'])->assertStatus(503);

        $this->assertSame(0, (int) $user->fresh()->strikes);
    }

    /**
     * The safety net that matters most during a provider outage: obvious
     * abuse is still refused by the local engine rather than being held as
     * "unavailable" and then waved through by an impatient operator.
     */
    public function test_obvious_abuse_is_still_blocked_while_the_provider_is_down(): void
    {
        $user = $this->actingAsUser();
        config(['services.moderation.openai_key' => 'sk-test-key']);
        Http::fake(['api.openai.com/*' => Http::response('rate limited', 429)]);

        $this->postJson('/api/v1/feed', ['text' => '@ahmet sen tam bir aptalsın'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');

        $this->assertSame(0, \App\Models\FeedPost::count());
        $this->assertSame(1, (int) $user->fresh()->strikes);
    }

    public function test_scores_above_threshold_count_even_when_not_hard_flagged(): void
    {
        $user = $this->actingAsUser();
        config(['services.moderation.openai_key' => 'sk-test-key']);
        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'omni-moderation-latest',
            'results' => [[
                'flagged' => false,
                'categories' => ['sexual/minors' => false],
                'category_scores' => ['sexual/minors' => 0.42],
            ]],
        ])]);

        $this->postJson('/api/v1/feed', ['text' => 'borderline text'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');
        $this->assertSame(1, (int) $user->fresh()->strikes);
    }

    public function test_a_moderation_event_records_the_decision_for_audit(): void
    {
        $user = $this->actingAsUser();
        $this->withProvider($this->flagged(['hate' => true], ['hate' => 0.91]));

        $this->postJson('/api/v1/feed', ['text' => 'flagged by model'])->assertStatus(400);

        $event = ModerationEvent::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('rejected', $event->action);
        $this->assertSame('post', $event->content_type);
        $this->assertSame('feed.store', $event->source_feature);
        $this->assertContains('hate', $event->categories);
        $this->assertSame(1, $event->strike_number);
        $this->assertSame('omni-moderation-latest', $event->moderation_model);
    }

    public function test_the_api_key_is_sent_to_openai_and_never_returned_to_the_client(): void
    {
        $this->actingAsUser();
        $this->withProvider($this->flagged(['harassment' => true]));

        $response = $this->postJson('/api/v1/feed', ['text' => 'flagged']);

        Http::assertSent(function ($request) {
            // The key travels server→OpenAI only.
            return str_contains($request->header('Authorization')[0] ?? '', 'sk-test-key')
                && $request['model'] === 'omni-moderation-latest';
        });

        $this->assertStringNotContainsString('sk-test-key', $response->getContent());
    }

    public function test_images_are_sent_to_the_model_alongside_the_caption(): void
    {
        $user = $this->actingAsUser();
        $this->withProvider($this->clean());

        app(ContentModerator::class)->check(
            $user,
            'a caption',
            'post',
            'feed.store',
            ['data:image/png;base64,iVBORw0KGgo='],
        );

        Http::assertSent(function ($request) {
            $types = array_column($request['input'] ?? [], 'type');

            return in_array('text', $types, true) && in_array('image_url', $types, true);
        });
    }
}
