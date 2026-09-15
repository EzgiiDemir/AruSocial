<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\MediaItem;
use App\Models\ModerationReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

    public function test_social_media_must_be_an_owned_approved_local_upload(): void
    {
        $user = $this->seedUser();

        $this->postJson('/api/v1/feed', [
            'text' => 'uzak görsel',
            'imageUrl' => 'https://untrusted.example/unsafe.jpg',
        ])->assertStatus(422)->assertJsonPath('error.code', 'SOCIAL_MEDIA_UPLOAD_REQUIRED');

        $pending = MediaItem::create([
            'id' => 'media-pending', 'user_id' => $user->id,
            'file_path' => 'media/pending.jpg', 'file_name' => 'pending.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 1,
            'uploaded_at' => now(), 'uploaded_by' => $user->name,
            'used_in' => [], 'moderation_status' => 'pending',
        ]);
        $this->postJson('/api/v1/feed', [
            'text' => 'incelemedeki görsel', 'imageUrl' => $pending->url(),
        ])->assertStatus(422)->assertJsonPath('error.code', 'MEDIA_PENDING_REVIEW');

        $approved = MediaItem::create([
            'id' => 'media-approved', 'user_id' => $user->id,
            'file_path' => 'media/approved.mp4', 'file_name' => 'approved.mp4',
            'mime_type' => 'video/mp4', 'size_bytes' => 1,
            'uploaded_at' => now(), 'uploaded_by' => $user->name,
            'used_in' => [], 'moderation_status' => 'approved',
        ]);
        $response = $this->postJson('/api/v1/feed', [
            'text' => 'onaylı video', 'imageUrl' => $approved->url(),
        ])->assertOk();

        $response->assertJsonPath('data.imageUrl', $approved->url())
            ->assertJsonPath('data.mediaMimeType', 'video/mp4');
        $this->assertSame(1, FeedPost::count());
    }

    /**
     * The published ladder: three warnings, then escalating timed bans of
     * 24 hours, 3 days and 7 days. Nothing here may quietly become a
     * permanent ban — that stays an explicit admin decision.
     */
    public function test_strike_ladder_warns_three_times_then_escalates_timed_bans(): void
    {
        $user = $this->seedUser();

        foreach (['salak', 'aptal', 'ahmak'] as $index => $text) {
            $this->postJson('/api/v1/feed', ['text' => $text])->assertStatus(400);
            $fresh = $user->fresh();
            $this->assertSame($index + 1, (int) $fresh->strikes);
            $this->assertNull($fresh->banned_until, 'Strikes 1-3 are warnings, not bans.');
            $this->assertNull($fresh->banned_at);
        }

        // 4th → 24 hours.
        $this->postJson('/api/v1/feed', ['text' => 'gerizekalı'])->assertStatus(400);
        $fresh = $user->fresh();
        $this->assertSame(4, (int) $fresh->strikes);
        $this->assertNotNull($fresh->banned_until);
        $this->assertEqualsWithDelta(24, now()->diffInHours($fresh->banned_until, false), 1);

        // A ban is enforced for every endpoint, not just the one that tripped it.
        $this->getJson('/api/v1/events')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_BANNED');

        // 5th → 3 days.
        $user->update(['banned_until' => null, 'moderation_status' => 'clear']);
        $this->postJson('/api/v1/feed', ['text' => 'şerefsiz'])->assertStatus(400);
        $fresh = $user->fresh();
        $this->assertSame(5, (int) $fresh->strikes);
        $this->assertEqualsWithDelta(72, now()->diffInHours($fresh->banned_until, false), 1);

        // 6th → 7 days.
        $user->update(['banned_until' => null, 'moderation_status' => 'clear']);
        $this->postJson('/api/v1/feed', ['text' => 'orospu'])->assertStatus(400);
        $fresh = $user->fresh();
        $this->assertSame(6, (int) $fresh->strikes);
        $this->assertEqualsWithDelta(168, now()->diffInHours($fresh->banned_until, false), 1);
    }

    public function test_an_expired_ban_restores_access_without_admin_action(): void
    {
        $user = $this->seedUser();
        $user->update(['strikes' => 4, 'banned_until' => now()->subMinute()]);

        // Server time decides, so a lapsed ban simply stops applying.
        $this->getJson('/api/v1/events')->assertOk();
        $this->assertNull($user->fresh()->banned_until);
    }

    public function test_a_repeated_submission_does_not_cost_two_strikes(): void
    {
        $user = $this->seedUser();

        $this->postJson('/api/v1/feed', ['text' => 'sen tam bir aptalsın'])->assertStatus(400);
        $this->postJson('/api/v1/feed', ['text' => 'sen tam bir aptalsın'])->assertStatus(400);

        $this->assertSame(1, (int) $user->fresh()->strikes, 'A double-tap must not double-punish.');
    }

    public function test_local_text_policy_normalizes_common_turkish_obfuscation_and_categories(): void
    {
        $user = $this->seedUser();

        $this->postJson('/api/v1/feed', ['text' => 's4l4k davranma'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');
        $this->postJson('/api/v1/feed', ['text' => 'seni vuracağım'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');

        $this->assertSame(2, $user->fresh()->strikes);
    }

    public function test_local_text_policy_enforces_english_and_russian_content(): void
    {
        $user = $this->seedUser();

        $this->postJson('/api/v1/feed', ['text' => 'I will kill you'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');
        $this->postJson('/api/v1/feed', ['text' => 'Я тебя убью'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');

        $this->assertSame(2, $user->fresh()->strikes);
    }

    public function test_local_text_policy_blocks_hate_speech_and_sexual_harassment(): void
    {
        $user = $this->seedUser();

        $hate = $this->postJson('/api/v1/feed', [
            'text' => 'Bu göçmenler insan değil, hepsini ülkeden sürmek lazım',
        ]);
        $hate->assertStatus(400)->assertJsonPath('error.code', 'CONTENT_BLOCKED');
        $this->assertStringContainsString('nefret', (string) $hate->json('error.message'));

        $sex = $this->postJson('/api/v1/feed', [
            'text' => "Give me your number, gorgeous. I won't let you sleep tonight.",
        ]);
        $sex->assertStatus(400)->assertJsonPath('error.code', 'CONTENT_BLOCKED');
        $this->assertStringContainsString('cinsel', (string) $sex->json('error.message'));

        $this->assertSame(2, $user->fresh()->strikes);
    }

    public function test_mild_campus_exclamation_is_still_allowed(): void
    {
        $this->seedUser();

        $this->postJson('/api/v1/feed', ['text' => 'Bugün stüdyoda harika bir gün geçirdim'])->assertOk();
        $this->postJson('/api/v1/feed', ['text' => 'Bu uygulama yine çöktü, lanet olsun.'])->assertOk();
    }

    public function test_political_party_campaigning_is_blocked_but_student_council_elections_are_allowed(): void
    {
        $user = $this->seedUser();

        $this->postJson('/api/v1/feed', [
            'text' => 'CHP ve AKP arasındaki tartışma bu sabah yine gündemdeydi.',
        ])->assertOk();

        $political = $this->postJson('/api/v1/feed', [
            'text' => 'Genel seçimlerde oy verin, AKP kazanmalı.',
        ]);
        $political->assertStatus(400)->assertJsonPath('error.code', 'CONTENT_BLOCKED');
        $this->assertStringContainsString('siyasi', (string) $political->json('error.message'));
        $this->assertSame(1, $user->fresh()->strikes);

        // A campus platform's own club/council elections are a normal,
        // unrelated topic — the generic word "seçim" must never trip this.
        $this->postJson('/api/v1/feed', [
            'text' => 'Kulüp başkanlığı seçimi için adaylık başvuruları başladı.',
        ])->assertOk();
    }

    public function test_stretched_letters_and_rephrased_insults_are_still_caught(): void
    {
        $user = $this->seedUser();

        // Elongated-letter evasion of an exact BLOCKED_TERMS phrase.
        $this->postJson('/api/v1/feed', ['text' => 'saaaalak davranma'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');

        // A rephrasing the old exact-phrase list would have missed, caught
        // by the gap-tolerant pattern family instead of an exact string.
        $rephrased = $this->postJson('/api/v1/feed', [
            'text' => 'Uyarıyorum, hesabını yakında tamamen sileceğim.',
        ]);
        $rephrased->assertStatus(400)->assertJsonPath('error.code', 'CONTENT_BLOCKED');

        $this->assertSame(2, $user->fresh()->strikes);
    }

    public function test_reviewer_rejection_of_owned_media_records_a_strike(): void
    {
        Storage::fake('public');
        $student = $this->seedUser();
        $item = MediaItem::create([
            'id' => 'media-owned-pending',
            'user_id' => $student->id,
            'file_path' => 'media/unsafe.jpg',
            'file_name' => 'unsafe.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'uploaded_at' => now(),
            'uploaded_by' => $student->name,
            'used_in' => [],
            'moderation_status' => 'pending',
        ]);
        ModerationReport::create([
            'id' => 'report-owned-pending',
            'kind' => 'media',
            'target_id' => $item->id,
            'target_label' => $item->file_name,
            'reason' => 'media_awaiting_local_review',
            'reported_at' => now(),
            'action' => null,
        ]);

        $this->actingAsRole('moderator');
        $this->postJson('/api/v1/admin/moderation/queue/'.$item->id.'/resolve', [
            'action' => 'rejected',
        ])->assertOk()->assertJsonPath('data.moderationStatus', 'rejected');

        $this->assertSame(1, $student->fresh()->strikes);
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
            'meta' => 'now', 'created_at' => now(), 'moderation_status' => 'approved',
        ]);

        $response = $this->postJson("/api/v1/feed/{$post->id}/comments", ['text' => 'bu piç bir yorum']);

        $response->assertStatus(400);
        $this->assertEquals('CONTENT_BLOCKED', $response->json('error.code'));
    }

    private function tinyPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL1JwAAAABJRU5ErkJggg==',
            true,
        );
    }

    public function test_local_image_preflight_accepts_a_structurally_valid_image(): void
    {
        $user = $this->seedUser();
        $response = $this->postJson('/api/v1/moderation/check-image', [
            'imageBase64' => base64_encode($this->tinyPng()),
            'mimeType' => 'image/png',
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('data.allowed'));
        $this->assertFalse($response->json('data.reviewRequired'));
        $this->assertSame(0, $user->fresh()->strikes);
    }

    public function test_invalid_image_data_is_rejected_without_a_strike(): void
    {
        $user = $this->seedUser();
        $response = $this->postJson('/api/v1/moderation/check-image', [
            'imageBase64' => base64_encode('fake-image-bytes'),
            'mimeType' => 'image/jpeg',
        ]);

        $response->assertStatus(400);
        $this->assertEquals('INVALID_FILE_CONTENTS', $response->json('error.code'));
        $this->assertEquals(0, $user->fresh()->strikes);
    }

    public function test_admin_moderation_settings_report_the_local_review_policy(): void
    {
        $this->actingAsRole('moderator');

        $before = $this->getJson('/api/v1/admin/settings/moderation');
        $before->assertOk()->assertJsonPath('data.mode', 'local_review');
        $this->assertTrue($before->json('data.configured'));

        $set = $this->postJson('/api/v1/admin/settings/moderation', []);
        $set->assertOk();
        $this->assertTrue($set->json('data.configured'));
        $this->assertSame('local_review', $set->json('data.mode'));

        $after = $this->getJson('/api/v1/admin/settings/moderation');
        $this->assertTrue($after->json('data.configured'));
    }
}
