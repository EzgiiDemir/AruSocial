<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Outcomes that publish but still have something to say.
 *
 * `warned`, `review` and `support` all let the content through, so they
 * never travelled the error path — and the controller returned a plain
 * success, so the message was computed and thrown away. The self-harm
 * support text was the worst casualty: the engine went out of its way to
 * route a student in crisis to help rather than a strike, and then nothing
 * reached them.
 *
 * The notice now rides in `meta.moderation` on the normal success response,
 * attached centrally so no endpoint can forget it.
 */
class ModerationNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_student_in_crisis_is_told_where_to_get_help(): void
    {
        $this->actingAsUser();
        config(['services.moderation.openai_key' => '']);

        $response = $this->postJson('/api/v1/feed', [
            'text' => 'Artık yaşamak istemiyorum, çok yoruldum.',
        ])->assertSuccessful();

        $response->assertJsonPath('meta.moderation.status', 'support');

        $message = $response->json('meta.moderation.message');
        $this->assertNotEmpty($message, 'The support message must reach the author.');
        // The point of the message is the referral, not the acknowledgement.
        $this->assertStringContainsString('psikolojik', mb_strtolower($message));

        // Published, and not a strike — the whole reason this path exists.
        $this->assertSame(1, FeedPost::count());
        $this->assertSame(0, (int) $this->actingAsUser()->fresh()->strikes);
    }

    public function test_a_borderline_post_publishes_and_says_it_was_a_warning(): void
    {
        $this->actingAsUser();
        config(['services.moderation.openai_key' => '']);

        // Content criticism, not a targeted attack: allowed, but noted.
        $response = $this->postJson('/api/v1/feed', [
            'text' => 'Bunu yazanın kafası çalışmıyor herhalde.',
        ])->assertSuccessful();

        $this->assertContains(
            $response->json('meta.moderation.status'),
            ['warned', 'review'],
            'A borderline post should publish with a notice attached.',
        );
        $this->assertSame(1, FeedPost::count());
    }

    public function test_a_clean_post_carries_no_notice(): void
    {
        $this->actingAsUser();
        config(['services.moderation.openai_key' => '']);

        $this->postJson('/api/v1/feed', [
            'text' => 'Kütüphanede ders çalışıyorum, gelen olursa yazsın.',
        ])->assertSuccessful()->assertJsonMissingPath('meta.moderation');
    }

    /**
     * A rejection already carries its reason on the error path; repeating
     * it in meta would mean two sources of truth for one decision.
     */
    public function test_a_rejection_reports_through_the_error_path_only(): void
    {
        $this->actingAsUser();
        config(['services.moderation.openai_key' => '']);

        $this->postJson('/api/v1/feed', ['text' => 'lanet zenci'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED')
            ->assertJsonMissingPath('meta.moderation');
    }

    /**
     * The slot is static, so a notice from one request must not appear on
     * the next — a reused worker process would otherwise show a stranger's
     * support message to whoever asked next.
     */
    public function test_a_notice_does_not_leak_into_the_next_response(): void
    {
        $this->actingAsUser();
        config(['services.moderation.openai_key' => '']);
        Http::fake();

        $this->postJson('/api/v1/feed', ['text' => 'Kendimi öldürmek istiyorum.'])
            ->assertSuccessful()
            ->assertJsonPath('meta.moderation.status', 'support');

        $this->getJson('/api/v1/feed')
            ->assertSuccessful()
            ->assertJsonMissingPath('meta.moderation');
    }
}
