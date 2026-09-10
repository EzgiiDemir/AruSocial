<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\MediaItem;
use App\Models\ModerationEvent;
use App\Models\User;
use App\Services\Moderation\ModerationExemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The pipeline scans student content. It does not scan staff content.
 *
 * An announcement written by the university is institutional
 * communication, and holding it behind a classifier means ARUCAD cannot
 * reliably publish its own notices. The exemption is decided from the
 * server-side role assignment and nowhere else.
 *
 * What it deliberately does *not* exempt: bans, structural validation,
 * and accountability. Those are tested here too, because an exemption
 * that quietly widened into any of them would be the dangerous kind.
 */
class StaffModerationExemptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'moderation.enabled' => false,
            'services.moderation.enabled' => false,
            'services.moderation.openai_key' => '',
            'services.local_moderation.binary' => '',
            'moderation.image.enabled' => true,
            'moderation.image.base_url' => 'http://image-moderation.test',
            'moderation.image.thresholds' => ['nsfw' => ['review' => 0.20, 'block' => 0.50]],
        ]);
        // If any staff upload were scanned, this would block it.
        Http::fake(['image-moderation.test/*' => Http::response([
            'success' => true, 'model' => 'm', 'model_version' => 'v',
            'scores' => ['nsfw' => 0.99, 'normal' => 0.01], 'latency_ms' => 5,
        ])]);
    }

    /** Every non-student role the app defines. */
    public static function staffRoles(): array
    {
        return [
            'superAdmin' => ['superAdmin'],
            'contentEditor' => ['contentEditor'],
            'moderator' => ['moderator'],
            'trainer' => ['trainer'],
            'studentAffairs' => ['studentAffairs'],
            'clubManager' => ['clubManager'],
            'careerStaff' => ['careerStaff'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('staffRoles')]
    public function test_staff_text_is_not_scanned(string $role): void
    {
        $this->actingAsRole($role);

        // Text the deterministic engine would certainly refuse from a
        // student — quoted in an announcement about campus rules, say.
        $this->postJson('/api/v1/feed', [
            'text' => 'lanet zenci defol buradan',
        ])->assertOk("{$role} was moderated");

        $this->assertSame(1, FeedPost::includingUnmoderated()->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('staffRoles')]
    public function test_staff_images_are_not_scanned(string $role): void
    {
        Storage::fake('local');
        $this->actingAsRole($role);

        $created = $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg('duyuru.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->assertSame('approved', $created['moderationStatus'],
            "{$role} upload was held despite the exemption");
        $this->assertSame(0, count(Http::recorded()),
            "{$role} upload was sent to the classifier");
    }

    // ---- what the exemption must NOT cover -------------------------

    public function test_a_student_is_still_scanned(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/feed', ['text' => 'lanet zenci defol buradan'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');
    }

    /**
     * Otherwise "promote them to trainer" becomes a way to lift a
     * suspension, which turns role management into a moderation bypass.
     */
    public function test_a_banned_staff_account_is_still_banned(): void
    {
        $staff = $this->actingAsRole('trainer');
        $staff->forceFill(['banned_until' => now()->addDay()])->save();

        $this->postJson('/api/v1/feed', ['text' => 'duyuru'])->assertStatus(403);
    }

    /** A corrupt or enormous file is a problem whoever sent it. */
    public function test_staff_uploads_still_pass_structural_validation(): void
    {
        Storage::fake('local');
        $this->actingAsRole('contentEditor');

        $this->post('/api/v1/media/mine', [
            'file' => \Illuminate\Http\UploadedFile::fake()
                ->createWithContent('broken.jpg', 'MZ'.str_repeat('A', 400)),
        ], ['Accept' => 'application/json'])->assertStatus(400);

        $this->assertSame(0, MediaItem::count());
    }

    /**
     * The exemption skips scanning, not accountability. "Who published
     * this" is exactly the question asked after something goes wrong.
     */
    public function test_an_exempt_submission_still_leaves_evidence(): void
    {
        $staff = $this->actingAsRole('contentEditor');

        $this->postJson('/api/v1/feed', ['text' => 'kampus duyurusu'])->assertOk();

        $event = ModerationEvent::where('user_id', (string) $staff->id)->latest('created_at')->first();
        $this->assertNotNull($event, 'An exempt publication left no trace.');
        $this->assertSame('exempt', $event->decided_by);
        $this->assertStringContainsString('contentEditor', (string) $event->excerpt);
    }

    // ---- the rule itself -------------------------------------------

    public function test_the_rule_is_decided_from_the_server_side_role(): void
    {
        $student = User::firstOrCreate(
            ['email' => 'ogrenci@arucad.edu.tr'],
            ['name' => 'Ogrenci', 'password' => bcrypt('x')],
        );

        $this->assertTrue(ModerationExemption::appliesTo($student),
            'A student must be moderated.');
        $this->assertTrue(ModerationExemption::appliesTo(null),
            'An unattributed submission must be moderated.');
        $this->assertFalse(ModerationExemption::appliesTo($this->actingAsRole('trainer')));
    }

    /**
     * A role nobody has classified yet must default to being scanned.
     * The allowlist is written that way on purpose: a blocklist would
     * silently exempt every role somebody adds later.
     */
    public function test_an_unrecognised_role_is_still_moderated(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'yeni-rol@arucad.edu.tr'],
            ['name' => 'Yeni', 'password' => bcrypt('x')],
        );

        // No role assignment row at all resolves to `student`.
        $this->assertTrue(ModerationExemption::appliesTo($user));
    }
}
