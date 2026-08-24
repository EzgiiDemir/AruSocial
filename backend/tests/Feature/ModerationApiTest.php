<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\FeedPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModerationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    private function seedUser(): User
    {
        return $this->actingAsUser();
    }

    public function test_a_post_with_blocked_text_is_rejected_and_not_created(): void
    {
        $this->seedUser();

        $response = $this->postJson('/api/v1/feed', ['text' => 'sen bir salaksın']);

        $response->assertStatus(400);
        $this->assertEquals('CONTENT_BLOCKED', $response->json('error.code'));
        $this->assertEquals(0, FeedPost::count());
    }

    public function test_a_clean_post_is_accepted(): void
    {
        $this->seedUser();

        $this->postJson('/api/v1/feed', ['text' => 'Bugün stüdyoda harika bir gün geçirdim'])->assertOk();

        $this->assertEquals(1, FeedPost::count());
    }

    public function test_three_violations_actually_ban_the_account(): void
    {
        $user = $this->seedUser();

        $this->postJson('/api/v1/feed', ['text' => 'salak'])->assertStatus(400);
        $this->assertEquals(1, $user->fresh()->strikes);
        $this->assertNull($user->fresh()->banned_at);

        $this->postJson('/api/v1/feed', ['text' => 'aptal'])->assertStatus(400);
        $this->assertEquals(2, $user->fresh()->strikes);
        $this->assertNull($user->fresh()->banned_at);

        $this->postJson('/api/v1/feed', ['text' => 'ahmak'])->assertStatus(400);
        $this->assertEquals(3, $user->fresh()->strikes);
        $this->assertNotNull($user->fresh()->banned_at);
    }

    public function test_a_banned_account_is_rejected_from_every_student_facing_endpoint(): void
    {
        $user = $this->seedUser();
        $user->update(['banned_at' => now()]);

        $response = $this->getJson('/api/v1/events');

        $response->assertStatus(403);
        $this->assertEquals('ACCOUNT_BANNED', $response->json('error.code'));
    }

    // This asserted the opposite until authorization landed: /admin/* was
    // exempt from the ban check, because with one shared demo account a ban
    // would have locked the only person who could lift it out of the tools
    // to lift it. Real per-user auth ended that — whoever reviews a ban is
    // a different, unbanned account — and the exemption had turned into a
    // way for any banned account to keep reaching the admin API just by
    // choosing an /admin/* path.
    public function test_a_ban_reaches_admin_routes_too(): void
    {
        $moderator = $this->actingAsRole('moderator');
        $this->getJson('/api/v1/admin/audit-log')->assertOk();

        $moderator->update(['banned_at' => now()]);

        $this->getJson('/api/v1/admin/audit-log')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_BANNED');
    }

    public function test_a_blocked_comment_is_rejected(): void
    {
        $user = $this->seedUser();
        $post = FeedPost::create([
            'id' => 'post-1', 'author_id' => $user->id, 'name' => $user->name, 'text' => 'hi',
            'meta' => 'now', 'created_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/feed/{$post->id}/comments", ['text' => 'bu piç bir yorum']);

        $response->assertStatus(400);
        $this->assertEquals('CONTENT_BLOCKED', $response->json('error.code'));
    }

    // docs/EKSIKLER.md §26: the vision-moderation call is now fully
    // server-side (ImageModerationService) — the client just uploads
    // bytes, the backend calls OpenAI itself using a key that only ever
    // lives in the app_settings table, and a flagged image counts toward
    // the same 3-strike ban as flagged text.
    public function test_image_moderation_is_skipped_when_no_api_key_is_configured(): void
    {
        $this->seedUser();
        // No AppSetting::setValue('moderation.apiKey', ...) — the real
        // "not configured yet" state.

        $response = $this->postJson('/api/v1/moderation/check-image', [
            'imageBase64' => base64_encode('fake-image-bytes'),
            'mimeType' => 'image/jpeg',
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('data.allowed'));
    }

    public function test_a_flagged_image_is_rejected_and_counts_as_a_real_strike(): void
    {
        $user = $this->seedUser();
        AppSetting::setValue('moderation.apiKey', 'test-key');
        Http::fake([
            'api.openai.com/*' => Http::response([
                'results' => [
                    ['flagged' => true, 'categories' => ['sexual' => true, 'violence' => false]],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/moderation/check-image', [
            'imageBase64' => base64_encode('fake-image-bytes'),
            'mimeType' => 'image/jpeg',
        ]);

        $response->assertStatus(400);
        $this->assertEquals('CONTENT_BLOCKED', $response->json('error.code'));
        $this->assertEquals(1, $user->fresh()->strikes);
    }

    public function test_a_clean_image_is_accepted_and_does_not_strike(): void
    {
        $user = $this->seedUser();
        AppSetting::setValue('moderation.apiKey', 'test-key');
        Http::fake([
            'api.openai.com/*' => Http::response([
                'results' => [['flagged' => false, 'categories' => []]],
            ], 200),
        ]);

        $this->postJson('/api/v1/moderation/check-image', [
            'imageBase64' => base64_encode('fake-image-bytes'),
            'mimeType' => 'image/jpeg',
        ])->assertOk();

        $this->assertEquals(0, $user->fresh()->strikes);
    }

    public function test_two_image_strikes_and_one_text_strike_together_ban_the_account(): void
    {
        $user = $this->seedUser();
        AppSetting::setValue('moderation.apiKey', 'test-key');
        Http::fake([
            'api.openai.com/*' => Http::response([
                'results' => [['flagged' => true, 'categories' => ['sexual' => true]]],
            ], 200),
        ]);

        $this->postJson('/api/v1/moderation/check-image',
            ['imageBase64' => base64_encode('a'), 'mimeType' => 'image/jpeg'])->assertStatus(400);
        $this->assertEquals(1, $user->fresh()->strikes);

        $this->postJson('/api/v1/moderation/check-image',
            ['imageBase64' => base64_encode('b'), 'mimeType' => 'image/jpeg'])->assertStatus(400);
        $this->assertEquals(2, $user->fresh()->strikes);

        // A text violation and two image violations both count against the
        // same strikes counter — the third violation, regardless of kind,
        // is what bans the account.
        $this->postJson('/api/v1/feed', ['text' => 'salak'])->assertStatus(400);
        $this->assertEquals(3, $user->fresh()->strikes);
        $this->assertNotNull($user->fresh()->banned_at);
    }

    public function test_admin_moderation_settings_round_trip_without_ever_echoing_the_key(): void
    {
        $this->actingAsRole('moderator');

        $before = $this->getJson('/api/v1/admin/settings/moderation');
        $before->assertOk();
        $this->assertFalse($before->json('data.configured'));

        $set = $this->postJson('/api/v1/admin/settings/moderation', ['apiKey' => 'sk-real-key']);
        $set->assertOk();
        $this->assertTrue($set->json('data.configured'));
        $this->assertArrayNotHasKey('apiKey', $set->json('data'));

        $after = $this->getJson('/api/v1/admin/settings/moderation');
        $this->assertTrue($after->json('data.configured'));

        $this->postJson('/api/v1/admin/settings/moderation', ['apiKey' => ''])->assertOk();
        $cleared = $this->getJson('/api/v1/admin/settings/moderation');
        $this->assertFalse($cleared->json('data.configured'));
    }
}
