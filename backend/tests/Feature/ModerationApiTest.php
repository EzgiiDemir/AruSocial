<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\MediaItem;
use App\Models\ModerationReport;
use App\Models\User;
use App\Services\Moderation\Workflow\AccountEnforcementPolicy;
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

    /**
     * A detection test posts several violations in a row, and severe ones
     * reach a lock part-way through — after which the next post is
     * refused for *who is asking* rather than for what it says, and the
     * test would be measuring enforcement instead of detection.
     *
     * One account per submission keeps the two apart. The ladder itself
     * is pinned by its own tests above.
     */
    private function freshUser(string $tag): User
    {
        return $this->actingAsUser(User::factory()->create([
            'email' => $tag.'@arucad.edu.tr',
        ]));
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
     * The ladder, end to end over HTTP.
     *
     * The engine judges a bare insult a *direct attack* and labels it
     * HAR, which is a `serious` violation worth 3 points — so one
     * refusal reaches the restriction rung and a second reaches the
     * suspension rung. That is stricter than the ladder it replaced
     * (which warned three times first) and it is the deliberate
     * consequence of letting severity, not a count, decide.
     *
     * Nothing here may quietly become a permanent ban: that stays an
     * explicit human decision.
     */
    public function test_a_refusal_restricts_posting_and_a_second_one_suspends(): void
    {
        $user = $this->seedUser();

        // 3 points: a posting restriction, which is NOT a suspension.
        $this->postJson('/api/v1/feed', ['text' => 'salak'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED')
            ->assertJsonPath('error.points', 3);

        $fresh = $user->fresh();
        $this->assertNull($fresh->banned_until, 'A restriction must not suspend the account.');
        $this->assertNotNull($fresh->posting_restricted_until);
        $this->assertEqualsWithDelta(24, now()->diffInHours($fresh->posting_restricted_until, false), 1);

        // The difference that makes it a restriction: reading still works.
        $this->getJson('/api/v1/events')->assertOk();

        // Posting does not, and says so specifically rather than claiming
        // the account is suspended.
        $this->postJson('/api/v1/feed', ['text' => 'merhaba'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'POSTING_RESTRICTED');

        // 6 points: a 72-hour suspension, which locks everything.
        $user->forceFill(['posting_restricted_until' => null])->save();
        $this->postJson('/api/v1/feed', ['text' => 'aptal'])->assertStatus(400);

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->banned_until);
        $this->assertEqualsWithDelta(72, now()->diffInHours($fresh->banned_until, false), 1);
        $this->assertNull($fresh->banned_at, 'Nothing on the ladder bans permanently.');

        // A suspension is enforced everywhere, not just where it was earned.
        $this->getJson('/api/v1/events')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_BANNED');
    }

    /**
     * Every rung, including the ones an HTTP test cannot reach without
     * inventing a dozen violations. Read as "at least this many points".
     */
    public function test_every_rung_of_the_published_ladder(): void
    {
        $policy = app(AccountEnforcementPolicy::class);

        $this->assertSame(['action' => 'none', 'hours' => 0], $policy->consequenceFor(0));
        $this->assertSame(['action' => 'warning', 'hours' => 0], $policy->consequenceFor(1));
        $this->assertSame(['action' => 'posting_restriction', 'hours' => 24], $policy->consequenceFor(3));
        $this->assertSame(['action' => 'temporary_suspension', 'hours' => 72], $policy->consequenceFor(6));
        $this->assertSame(['action' => 'temporary_suspension', 'hours' => 168], $policy->consequenceFor(12));
        $this->assertSame(['action' => 'temporary_suspension', 'hours' => 720], $policy->consequenceFor(20));

        // Past the top rung the longest suspension repeats. It must never
        // escalate into something permanent on its own.
        $this->assertSame(['action' => 'temporary_suspension', 'hours' => 720], $policy->consequenceFor(99));
    }

    /**
     * Severity is the whole point of the change: spam and a credible
     * threat cannot cost the same.
     */
    public function test_severity_decides_what_a_violation_costs(): void
    {
        $spammer = $this->freshUser('spammer');
        $threatener = $this->freshUser('threatener');
        $policy = app(AccountEnforcementPolicy::class);

        $policy->recordAutomatedViolation($spammer, ['SPAM'], 'idem-spam');
        $policy->recordAutomatedViolation($threatener, ['THR'], 'idem-threat');

        $this->assertSame(1, $policy->activePoints($spammer->refresh()));
        $this->assertSame(12, $policy->activePoints($threatener->refresh()));

        $this->assertNull($spammer->posting_restricted_until, 'Spam alone is a warning.');
        $this->assertNotNull($threatener->banned_until, 'A threat is a suspension on its own.');
        $this->assertNull($threatener->banned_at, 'Still not permanent.');
    }

    /** The worst category in a submission decides, not the first listed. */
    public function test_the_worst_category_in_a_submission_decides_the_severity(): void
    {
        $user = $this->freshUser('mixed');
        $policy = app(AccountEnforcementPolicy::class);

        $policy->recordAutomatedViolation($user, ['SPAM', 'THR', 'PROF'], 'idem-mixed');

        $this->assertSame(12, $policy->activePoints($user->refresh()));
    }

    public function test_an_expired_ban_restores_access_without_admin_action(): void
    {
        $user = $this->seedUser();
        $user->update(['strikes' => 4, 'banned_until' => now()->subMinute()]);

        // Server time decides, so a lapsed ban simply stops applying.
        $this->getJson('/api/v1/events')->assertOk();
        $this->assertNull($user->fresh()->banned_until);
    }

    public function test_a_repeated_submission_does_not_cost_two_violations(): void
    {
        $user = $this->seedUser();
        $policy = app(AccountEnforcementPolicy::class);

        $this->postJson('/api/v1/feed', ['text' => 'salak'])->assertStatus(400);

        // The first refusal already restricted posting, so the retry is
        // now refused for who is asking rather than for what it says.
        $this->postJson('/api/v1/feed', ['text' => 'salak'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'POSTING_RESTRICTED');

        $this->assertSame(1, (int) $user->fresh()->strikes, 'A double-tap must not double-punish.');
        $this->assertSame(3, $policy->activePoints($user->fresh()));

        // And the charge itself is keyed to the submission, so even
        // without the restriction above the same act cannot cost twice.
        $other = $this->freshUser('retry');
        $policy->recordAutomatedViolation($other, ['HAR'], 'idem-retry');
        $second = $policy->recordAutomatedViolation($other, ['HAR'], 'idem-retry');

        $this->assertTrue($second['duplicate']);
        $this->assertSame(3, $policy->activePoints($other->refresh()));
    }

    public function test_local_text_policy_normalizes_common_turkish_obfuscation_and_categories(): void
    {
        $obfuscated = $this->freshUser('obfuscation');
        $this->postJson('/api/v1/feed', ['text' => 's4l4k davranma'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');
        $this->assertSame(1, (int) $obfuscated->fresh()->strikes);

        $threat = $this->freshUser('threat');
        $this->postJson('/api/v1/feed', ['text' => 'seni vuracağım'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');
        $this->assertSame(1, (int) $threat->fresh()->strikes);
    }

    public function test_local_text_policy_enforces_english_and_russian_content(): void
    {
        $english = $this->freshUser('english');
        $this->postJson('/api/v1/feed', ['text' => 'I will kill you'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');
        $this->assertSame(1, (int) $english->fresh()->strikes);

        $russian = $this->freshUser('russian');
        $this->postJson('/api/v1/feed', ['text' => 'Я тебя убью'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');
        $this->assertSame(1, (int) $russian->fresh()->strikes);
    }

    public function test_local_text_policy_blocks_hate_speech_and_sexual_harassment(): void
    {
        $hater = $this->freshUser('hate');
        $hate = $this->postJson('/api/v1/feed', [
            'text' => 'Bu göçmenler insan değil, hepsini ülkeden sürmek lazım',
        ]);
        $hate->assertStatus(400)->assertJsonPath('error.code', 'CONTENT_BLOCKED');
        $this->assertStringContainsString('nefret', (string) $hate->json('error.message'));
        $this->assertSame(1, (int) $hater->fresh()->strikes);

        $harasser = $this->freshUser('sexual');
        $sex = $this->postJson('/api/v1/feed', [
            'text' => "Give me your number, gorgeous. I won't let you sleep tonight.",
        ]);
        $sex->assertStatus(400)->assertJsonPath('error.code', 'CONTENT_BLOCKED');
        $this->assertStringContainsString('cinsel', (string) $sex->json('error.message'));
        $this->assertSame(1, (int) $harasser->fresh()->strikes);
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
        // Elongated-letter evasion of an exact BLOCKED_TERMS phrase.
        $stretched = $this->freshUser('stretched');
        $this->postJson('/api/v1/feed', ['text' => 'saaaalak davranma'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');
        $this->assertSame(1, (int) $stretched->fresh()->strikes);

        // A rephrasing the old exact-phrase list would have missed, caught
        // by the gap-tolerant pattern family instead of an exact string.
        $rephraser = $this->freshUser('rephrased');
        $this->postJson('/api/v1/feed', [
            'text' => 'Uyarıyorum, hesabını yakında tamamen sileceğim.',
        ])->assertStatus(400)->assertJsonPath('error.code', 'CONTENT_BLOCKED');
        $this->assertSame(1, (int) $rephraser->fresh()->strikes);
    }

    public function test_reviewer_rejection_of_owned_media_records_a_strike(): void
    {
        Storage::fake(MediaItem::disk());
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
