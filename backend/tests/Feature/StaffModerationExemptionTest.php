<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\MediaItem;
use App\Models\ModerationEvent;
use App\Models\User;
use App\Services\Moderation\ModerationExemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Staff are exempt where they publish *as the university*, and nowhere
 * else.
 *
 * An announcement written by ARUCAD is institutional communication, and
 * holding it behind a classifier means the university cannot reliably
 * publish its own notices. A member of staff writing a personal feed post
 * or story is not doing that — they are posting as themselves, and are
 * scanned like anyone else.
 *
 * The first version of this rule exempted staff on every surface, which
 * meant hate speech published from the accounts university staff use
 * daily. It was reported as "moderation has broken"; it was this. The
 * split below is what that report cost, so it is tested from both sides:
 * the institutional surface must stay exempt, and every social surface
 * must not.
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
        // Answers "unsafe" for anything actually scanned.
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

    // ---- personal surfaces: staff are scanned ----------------------

    #[DataProvider('staffRoles')]
    public function test_staff_personal_posts_are_scanned(string $role): void
    {
        $this->actingAsRole($role);

        $this->postJson('/api/v1/feed', ['text' => 'lanet zenci defol buradan'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');

        $this->assertSame(0, FeedPost::includingUnmoderated()->count(),
            "{$role} bypassed moderation on a personal post");
    }

    #[DataProvider('staffRoles')]
    public function test_staff_personal_uploads_are_scanned(string $role): void
    {
        Storage::fake('local');
        $this->actingAsRole($role);

        $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg('kisisel.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(400);

        $this->assertGreaterThan(0, count(Http::recorded()),
            "{$role} bypassed the classifier on a personal upload");
        $this->assertSame(0, MediaItem::count());
    }

    public function test_staff_stories_are_scanned(): void
    {
        $this->actingAsRole('trainer');

        $this->postJson('/api/v1/stories', ['text' => 'lanet zenci defol'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');
    }

    // ---- institutional surfaces: staff are exempt ------------------

    /**
     * The shared media library is institutional. `$adminLibrary` comes
     * from the route, never the request body, so a personal upload cannot
     * claim to be a library one.
     */
    public function test_staff_library_uploads_are_exempt(): void
    {
        Storage::fake('local');
        $this->actingAsRole('contentEditor');

        $created = $this->post('/api/v1/media', [
            'file' => $this->fakeJpeg('duyuru-gorseli.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->assertSame('approved', $created['moderationStatus']);
        $this->assertSame(0, count(Http::recorded()),
            'A library upload was sent to the classifier.');
    }

    public function test_a_student_cannot_reach_the_library_route(): void
    {
        Storage::fake('local');
        $this->actingAsUser();

        // The exemption is scoped by surface, but the surface itself is
        // still behind a permission — otherwise "post to the library"
        // would be a bypass anyone could use.
        $this->post('/api/v1/media', [
            'file' => $this->fakeJpeg('deneme.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(403);
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

    /** A corrupt file is a problem whoever sent it. */
    public function test_staff_library_uploads_still_pass_structural_validation(): void
    {
        Storage::fake('local');
        $this->actingAsRole('contentEditor');

        $this->post('/api/v1/media', [
            'file' => UploadedFile::fake()
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

        $this->postJson('/api/v1/admin/places', [
            'id' => 'place-duyuru',
            'name' => 'Yeni Atolye',
            'category' => 'Akademik',
            'lat' => 35.33,
            'lng' => 33.32,
        ])->assertSuccessful();

        $event = ModerationEvent::where('user_id', (string) $staff->id)
            ->where('decided_by', 'exempt')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($event, 'An exempt publication left no trace.');
        $this->assertStringContainsString('contentEditor', (string) $event->excerpt);
    }

    // ---- the rule itself -------------------------------------------

    public function test_the_rule_is_decided_from_the_server_side_role(): void
    {
        $student = User::firstOrCreate(
            ['email' => 'ogrenci@arucad.edu.tr'],
            ['name' => 'Ogrenci', 'password' => bcrypt('x')],
        );

        $this->assertTrue(ModerationExemption::appliesTo($student, 'admin.place.upsert'),
            'A student must be moderated even on an institutional surface.');
        $this->assertTrue(ModerationExemption::appliesTo(null, 'admin.place.upsert'),
            'An unattributed submission must be moderated.');

        $trainer = $this->actingAsRole('trainer');
        $this->assertFalse(ModerationExemption::appliesTo($trainer, 'admin.place.upsert'));
        $this->assertTrue(ModerationExemption::appliesTo($trainer, 'feed.store'),
            'Staff must be moderated on personal surfaces.');
        $this->assertTrue(ModerationExemption::appliesTo($trainer, 'story.store'));
    }

    /**
     * An unknown surface counts as personal. Defaulting to exempt is how
     * a caller that forgot to pass one would open a hole in silence.
     */
    public function test_an_unknown_surface_is_moderated(): void
    {
        $trainer = $this->actingAsRole('trainer');

        $this->assertTrue(ModerationExemption::appliesTo($trainer, ''));
        $this->assertTrue(ModerationExemption::appliesTo($trainer, 'some.new.feature'));
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

        $this->assertTrue(ModerationExemption::appliesTo($user, 'admin.place.upsert'));
    }
}
