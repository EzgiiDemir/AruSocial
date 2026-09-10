<?php

namespace Tests\Feature;

use App\Jobs\ModerateImageJob;
use App\Models\FeedPost;
use App\Models\ModerationAppeal;
use App\Models\ModerationCase;
use App\Models\Notification;
use App\Models\User;
use App\Services\Moderation\Image\ImageModerationRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A student has to be told what happened to their content.
 *
 * Every decision used to be silent outside the immediate API response. A
 * post removed an hour later, an appeal decided the next morning, a
 * warning on an account — none of it reached the person it was about.
 * Enforcement nobody is told about is indistinguishable from the app
 * being broken, and it is the quickest way to make someone believe they
 * were treated arbitrarily.
 */
class ModerationNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            ['name' => explode('@', $email)[0], 'password' => bcrypt('x')],
        );
    }

    private function caseFor(User $author): ModerationCase
    {
        $post = FeedPost::create([
            'id' => 'post-'.uniqid(),
            'author_id' => $author->id,
            'name' => $author->name,
            'text' => 'incelenecek gonderi',
            'visibility' => 'everyone',
            'created_at' => now(),
        ]);

        return ModerationCase::create([
            'id' => (string) Str::uuid(),
            'content_type' => 'post',
            'content_id' => $post->id,
            'user_id' => $author->id,
            'source' => ModerationCase::SOURCE_USER_REPORT,
            'priority' => 20,
            'status' => ModerationCase::STATUS_OPEN,
            'report_count' => 1,
        ]);
    }

    public function test_removing_content_tells_its_author(): void
    {
        $author = $this->makeUser('author@arucad.edu.tr');
        $case = $this->caseFor($author);
        $this->actingAsRole('moderator');

        $this->postJson('/api/v1/admin/moderation/cases/'.$case->id.'/decide', [
            'contentDecision' => 'remove',
        ])->assertOk();

        $notice = Notification::where('user_id', $author->id)
            ->where('kind', 'moderation_removed')->first();

        $this->assertNotNull($notice, 'The author was never told their post was removed.');
        $this->assertSame($case->id, $notice->data['caseId'] ?? null,
            'The notice must carry the case, or the appeal button has nothing to open.');
    }

    /**
     * Two separate decisions, two separate messages. Merging them is how
     * "your post was removed" gets read as "you are banned".
     */
    public function test_a_penalty_is_a_separate_notice_from_the_content_one(): void
    {
        $author = $this->makeUser('author@arucad.edu.tr');
        $case = $this->caseFor($author);
        $this->actingAsRole('moderator');

        $this->postJson('/api/v1/admin/moderation/cases/'.$case->id.'/decide', [
            'contentDecision' => 'remove',
            'accountAction' => 'warn',
            'note' => 'kurallara aykiri',
        ])->assertOk();

        $this->assertSame(1, Notification::where('user_id', $author->id)
            ->where('kind', 'moderation_removed')->count());
        $this->assertSame(1, Notification::where('user_id', $author->id)
            ->where('kind', 'moderation_penalty')->count());
    }

    /** Removing a post without an account action must not warn anyone. */
    public function test_removing_content_alone_sends_no_penalty_notice(): void
    {
        $author = $this->makeUser('author@arucad.edu.tr');
        $case = $this->caseFor($author);
        $this->actingAsRole('moderator');

        $this->postJson('/api/v1/admin/moderation/cases/'.$case->id.'/decide', [
            'contentDecision' => 'remove',
        ])->assertOk();

        $this->assertSame(0, Notification::where('user_id', $author->id)
            ->where('kind', 'moderation_penalty')->count());
    }

    public function test_an_appeal_decision_reaches_the_student(): void
    {
        $author = $this->makeUser('author@arucad.edu.tr');
        $case = $this->caseFor($author);
        $case->update(['decision' => 'remove', 'status' => ModerationCase::STATUS_RESOLVED]);

        $this->actingAsUser($author);
        $this->postJson('/api/v1/moderation/appeals', [
            'caseId' => $case->id, 'reason' => 'yanlis karar',
        ])->assertOk();

        $appeal = ModerationAppeal::first();
        $this->actingAsRole('moderator');
        $this->postJson('/api/v1/admin/moderation/appeals/'.$appeal->id.'/decide', [
            'outcome' => 'overturn',
            'note' => 'Ogrenci hakli.',
        ])->assertOk();

        $notice = Notification::where('user_id', $author->id)
            ->where('kind', 'appeal_accepted')->first();

        $this->assertNotNull($notice);
        $this->assertStringContainsString('Ogrenci hakli.', (string) $notice->body,
            'The moderator note must reach the student, not just the audit log.');
    }

    // ---- queued image verdicts -------------------------------------

    private function scannerReturns(array $scores): void
    {
        config([
            'moderation.enabled' => false,
            'services.moderation.enabled' => false,
            'services.moderation.openai_key' => '',
            'services.local_moderation.binary' => '',
            'moderation.image.enabled' => true,
            'moderation.image.async' => true,
            'moderation.image.base_url' => 'http://image-moderation.test',
            'moderation.image.thresholds' => ['nsfw' => ['review' => 0.35, 'block' => 0.85]],
        ]);
        Http::fake(['image-moderation.test/*' => Http::response([
            'success' => true, 'model' => 'm', 'model_version' => 'v',
            'scores' => $scores, 'latency_ms' => 10,
        ])]);
    }

    private function uploadAndScan(array $scores): User
    {
        Storage::fake('local');
        Queue::fake();
        $user = $this->actingAsUser();
        $this->scannerReturns($scores);

        $id = $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg('photo.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        (new ModerateImageJob($id))->handle(app(ImageModerationRunner::class));

        return $user;
    }

    /**
     * In queued mode the upload already returned 201, so the verdict lands
     * after the student has moved on. Without a notice their photo simply
     * never appears.
     */
    public function test_a_queued_block_tells_the_uploader(): void
    {
        $user = $this->uploadAndScan(['normal' => 0.02, 'nsfw' => 0.98]);

        $this->assertSame(1, Notification::where('user_id', $user->id)
            ->where('kind', 'moderation_removed')->count());
    }

    public function test_a_queued_hold_tells_the_uploader(): void
    {
        $user = $this->uploadAndScan(['normal' => 0.5, 'nsfw' => 0.5]);

        $this->assertSame(1, Notification::where('user_id', $user->id)
            ->where('kind', 'moderation_review')->count());
    }

    /**
     * Telling someone their ordinary photo passed is noise, and it
     * advertises that every upload is inspected.
     */
    public function test_an_allowed_image_notifies_nobody(): void
    {
        $user = $this->uploadAndScan(['normal' => 0.99, 'nsfw' => 0.01]);

        $this->assertSame(0, Notification::where('user_id', $user->id)
            ->where('kind', 'like', 'moderation_%')->count());
    }

    // ---- what the copy may never contain ---------------------------

    /**
     * A score is not an explanation. Publishing one invites an argument
     * about the number instead of the behaviour, and tells anyone probing
     * the system exactly where the threshold sits.
     */
    public function test_no_notice_ever_leaks_a_score_or_internal_category(): void
    {
        $user = $this->uploadAndScan(['normal' => 0.02, 'nsfw' => 0.98]);

        foreach (Notification::where('user_id', $user->id)->get() as $notice) {
            $text = mb_strtolower($notice->title.' '.$notice->body);
            foreach (['nsfw', 'score', '0.9', 'threshold', 'model'] as $leak) {
                $this->assertStringNotContainsString($leak, $text,
                    "a moderation notice leaked \"{$leak}\"");
            }
        }
    }

    /** A moderation decision is the platform's, not one moderator's. */
    public function test_notices_do_not_name_the_moderator(): void
    {
        $author = $this->makeUser('author@arucad.edu.tr');
        $case = $this->caseFor($author);
        $moderator = $this->actingAsRole('moderator');

        $this->postJson('/api/v1/admin/moderation/cases/'.$case->id.'/decide', [
            'contentDecision' => 'remove',
        ])->assertOk();

        $notice = Notification::where('user_id', $author->id)->first();

        // Naming them invites the student to take it up with that person
        // directly rather than through appeals.
        $this->assertNull($notice->actor_user_id);
        $this->assertStringNotContainsString(
            (string) $moderator->name, (string) $notice->body);
    }
}
