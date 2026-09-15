<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\ModerationEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ambiguous text is held for a human, not refused and not published.
 *
 * The semantic layer catches roughly 40% of unseen harmful writing on its
 * own. The band between `review` and `block` is where most of the rest
 * lives: measured across the labelled sets it is worth nine more catches
 * for four held safe posts out of fifty.
 *
 * It was deliberately switched off until there was a moderator screen to
 * drain the queue, because a hold nobody reads is a silent delete with
 * extra steps. The three properties below are what make holding
 * defensible rather than merely convenient:
 *
 *  - the post is not published,
 *  - the author is told plainly, and told it is not a violation,
 *  - it costs no strike, because the system is saying it does not know.
 */
class TextReviewBandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'moderation.text.enabled' => true,
            'moderation.text.base_url' => 'http://text-moderation.test',
            'moderation.text.thresholds' => [
                'THR' => ['review' => 0.10, 'block' => 0.15],
            ],
            'moderation.image.enabled' => false,
            'services.moderation.enabled' => false,
            'moderation.enabled' => false,
        ]);
    }

    private function marginIs(float $margin): void
    {
        Http::fake(['text-moderation.test/*' => Http::response([
            'success' => true, 'model' => 'minilm', 'model_version' => 'v1',
            'margins' => ['THR' => $margin], 'latency_ms' => 27,
        ])]);
    }

    /** Clean text. Nothing in the band, nothing to decide. */
    public function test_text_below_the_review_line_publishes(): void
    {
        $this->marginIs(0.02);
        $this->actingAsUser();

        $this->postJson('/api/v1/feed', ['text' => 'Kutuphanede bulusalim'])
            ->assertSuccessful();
    }

    public function test_text_in_the_band_is_held_rather_than_published(): void
    {
        $this->marginIs(0.12);
        $this->actingAsUser();

        $this->postJson('/api/v1/feed', ['text' => 'Cikista gorusuruz'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'MODERATION_PENDING');

        $this->assertSame(0, FeedPost::includingUnmoderated()->count(),
            'Held content was stored as a published post.');
    }

    /**
     * The message is the difference between a hold and a broken app. It
     * fell through to an empty string, so a student saw a bare 400 with
     * no explanation for a post that had not violated anything.
     */
    public function test_the_author_is_told_what_happened_and_that_it_is_not_a_violation(): void
    {
        $this->marginIs(0.12);
        $this->actingAsUser();

        $message = $this->postJson('/api/v1/feed', ['text' => 'Cikista gorusuruz'])
            ->json('error.message');

        $this->assertNotEmpty($message, 'A held post explained nothing to its author.');
        $this->assertStringContainsString('moderatör', (string) $message);
        $this->assertStringContainsString('ihlal tespiti değil', (string) $message);
    }

    /** Uncertainty is the system's problem, not the student's. */
    public function test_being_held_costs_no_strike_and_no_ban(): void
    {
        $this->marginIs(0.12);
        config(['moderation.enforcement.enabled' => true]);
        $user = $this->actingAsUser();

        $this->postJson('/api/v1/feed', ['text' => 'Cikista gorusuruz'])
            ->assertStatus(400);

        $user->refresh();
        $this->assertSame(0, (int) $user->strikes);
        $this->assertNull($user->banned_at);
        $this->assertNull($user->banned_until);
    }

    /** A moderator has to be able to find it. */
    public function test_a_review_event_is_recorded_for_the_queue(): void
    {
        $this->marginIs(0.12);
        $user = $this->actingAsUser();

        $this->postJson('/api/v1/feed', ['text' => 'Cikista gorusuruz'])
            ->assertStatus(400);

        $this->assertSame(1, ModerationEvent::where('user_id', $user->id)
            ->where('action', ModerationEvent::ACTION_REVIEW)->count());
    }

    /** Above the block line it is a refusal, and says so differently. */
    public function test_text_above_the_block_line_is_still_refused(): void
    {
        $this->marginIs(0.40);
        $this->actingAsUser();

        $this->postJson('/api/v1/feed', ['text' => 'Cikista gorusuruz'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');
    }

    /**
     * A category with no `review` threshold must not acquire one by
     * accident — SELF in particular routes to support, and a crisis post
     * must never be held silently instead.
     */
    public function test_a_category_without_a_review_threshold_is_unaffected(): void
    {
        config(['moderation.text.thresholds' => ['THR' => ['block' => 0.15]]]);
        $this->marginIs(0.12);
        $this->actingAsUser();

        $this->postJson('/api/v1/feed', ['text' => 'Cikista gorusuruz'])
            ->assertSuccessful();
    }
}
