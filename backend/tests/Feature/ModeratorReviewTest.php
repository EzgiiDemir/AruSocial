<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\ModerationAppeal;
use App\Models\ModerationCase;
use App\Models\User;
use App\Models\UserViolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The moderator surface: who may use it, what it records, and what it
 * deliberately refuses to do automatically.
 */
class ModeratorReviewTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            ['name' => explode('@', $email)[0], 'password' => bcrypt('x')],
        );
    }

    private function caseFor(User $author, string $decision = 'remove'): ModerationCase
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
            'decision' => $decision === 'open' ? null : null,
            'report_count' => 1,
        ]);
    }

    // ---- authorization --------------------------------------------

    /**
     * Flutter hiding a button is not authorization. These are the calls a
     * modified client or a curl one-liner makes.
     */
    public function test_a_normal_student_cannot_reach_any_moderator_endpoint(): void
    {
        $author = $this->actingAsUser();
        $case = $this->caseFor($author);
        $this->actingAsUser($this->makeUser('student@arucad.edu.tr'));

        $this->getJson('/api/v1/admin/moderation/cases')->assertStatus(403);
        $this->getJson('/api/v1/admin/moderation/cases/'.$case->id)->assertStatus(403);
        $this->postJson('/api/v1/admin/moderation/cases/'.$case->id.'/decide', [
            'contentDecision' => 'approve',
        ])->assertStatus(403);
        $this->getJson('/api/v1/admin/moderation/appeals')->assertStatus(403);
    }

    public function test_an_unauthenticated_caller_is_refused(): void
    {
        $this->getJson('/api/v1/admin/moderation/cases')->assertStatus(401);
    }

    // ---- deciding a case ------------------------------------------

    public function test_a_moderator_can_approve_content(): void
    {
        $author = $this->makeUser('author@arucad.edu.tr');
        $case = $this->caseFor($author);
        $this->actingAsRole('moderator');

        $this->postJson('/api/v1/admin/moderation/cases/'.$case->id.'/decide', [
            'contentDecision' => 'approve',
        ])->assertOk()->assertJsonPath('data.contentDecision', 'approve');

        $this->assertSame(ModerationCase::STATUS_RESOLVED, $case->fresh()->status);
    }

    public function test_a_moderator_can_remove_content(): void
    {
        $author = $this->makeUser('author@arucad.edu.tr');
        $case = $this->caseFor($author);
        $this->actingAsRole('moderator');

        $this->postJson('/api/v1/admin/moderation/cases/'.$case->id.'/decide', [
            'contentDecision' => 'remove',
        ])->assertOk();

        $this->assertSame('removed',
            FeedPost::includingUnmoderated()->find($case->content_id)->moderation_status);
    }

    /**
     * The most important separation in the whole surface. Removing a post
     * is a judgement about the post; banning its author is a judgement
     * about a person, and one must not silently imply the other.
     */
    public function test_removing_content_does_not_punish_the_author(): void
    {
        $author = $this->makeUser('author@arucad.edu.tr');
        $case = $this->caseFor($author);
        $this->actingAsRole('moderator');

        $this->postJson('/api/v1/admin/moderation/cases/'.$case->id.'/decide', [
            'contentDecision' => 'remove',
        ])->assertOk()->assertJsonPath('data.accountAction', 'none');

        $this->assertSame(0, UserViolation::where('user_id', $author->id)->count());
        $this->assertNull($author->fresh()->banned_until);
    }

    /** Penalising someone requires writing down why. */
    public function test_an_account_action_requires_a_note(): void
    {
        $author = $this->makeUser('author@arucad.edu.tr');
        $case = $this->caseFor($author);
        $this->actingAsRole('moderator');

        $this->postJson('/api/v1/admin/moderation/cases/'.$case->id.'/decide', [
            'contentDecision' => 'remove',
            'accountAction' => 'suspend',
        ])->assertStatus(400);

        $this->assertSame(0, UserViolation::where('user_id', $author->id)->count());
    }

    public function test_every_decision_writes_an_audit_entry(): void
    {
        $author = $this->makeUser('author@arucad.edu.tr');
        $case = $this->caseFor($author);
        $moderator = $this->actingAsRole('moderator');

        $this->postJson('/api/v1/admin/moderation/cases/'.$case->id.'/decide', [
            'contentDecision' => 'remove',
            'note' => 'kurallara aykiri',
        ])->assertOk();

        $row = DB::table('moderation_audit_log')
            ->where('action', 'case_decided')
            ->where('moderation_case_id', $case->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame((int) $moderator->id, (int) $row->actor_id);
        $this->assertSame('open', $row->previous_state);
        $this->assertSame('resolved', $row->new_state);
    }

    /** A double-clicked button must not decide the same case twice. */
    public function test_a_decided_case_cannot_be_decided_again(): void
    {
        $author = $this->makeUser('author@arucad.edu.tr');
        $case = $this->caseFor($author);
        $this->actingAsRole('moderator');

        $this->postJson('/api/v1/admin/moderation/cases/'.$case->id.'/decide', [
            'contentDecision' => 'remove',
        ])->assertOk();

        $this->postJson('/api/v1/admin/moderation/cases/'.$case->id.'/decide', [
            'contentDecision' => 'approve',
        ])->assertStatus(409);
    }

    // ---- appeals ---------------------------------------------------

    public function test_an_appeal_reopens_the_case_for_a_human(): void
    {
        $author = $this->makeUser('author@arucad.edu.tr');
        $case = $this->caseFor($author);
        $case->update(['decision' => 'remove', 'status' => ModerationCase::STATUS_RESOLVED]);

        $this->actingAsUser($author);
        $this->postJson('/api/v1/moderation/appeals', [
            'caseId' => $case->id,
            'reason' => 'Bu benim kendi calismam, yanlislikla kaldirildi.',
        ])->assertOk()->assertJsonPath('data.alreadySubmitted', false);

        $this->assertSame(ModerationCase::STATUS_REVIEWING, $case->fresh()->status);
        $this->assertSame(1, ModerationAppeal::where('moderation_case_id', $case->id)->count());
    }

    /** Appealing must not be a way to browse other people's removals. */
    public function test_a_student_cannot_appeal_someone_elses_case(): void
    {
        $author = $this->makeUser('author@arucad.edu.tr');
        $case = $this->caseFor($author);
        $case->update(['decision' => 'remove', 'status' => ModerationCase::STATUS_RESOLVED]);

        $this->actingAsUser($this->makeUser('other@arucad.edu.tr'));
        $this->postJson('/api/v1/moderation/appeals', [
            'caseId' => $case->id,
            'reason' => 'merak ettim',
        ])->assertStatus(404);

        $this->assertSame(0, ModerationAppeal::count());
    }

    public function test_a_second_appeal_does_not_reopen_a_settled_case(): void
    {
        $author = $this->makeUser('author@arucad.edu.tr');
        $case = $this->caseFor($author);
        $case->update(['decision' => 'remove', 'status' => ModerationCase::STATUS_RESOLVED]);
        $this->actingAsUser($author);

        $payload = ['caseId' => $case->id, 'reason' => 'lutfen tekrar bakin'];
        $this->postJson('/api/v1/moderation/appeals', $payload)->assertOk();
        $this->postJson('/api/v1/moderation/appeals', $payload)
            ->assertOk()->assertJsonPath('data.alreadySubmitted', true);

        $this->assertSame(1, ModerationAppeal::count());
    }

    /**
     * Winning an appeal has to undo the cost. Restoring the post but
     * leaving the violation on record is not a reversal.
     */
    public function test_overturning_an_appeal_restores_content_and_clears_the_violation(): void
    {
        $author = $this->makeUser('author@arucad.edu.tr');
        $case = $this->caseFor($author);
        $this->actingAsRole('moderator');

        $this->postJson('/api/v1/admin/moderation/cases/'.$case->id.'/decide', [
            'contentDecision' => 'remove',
            'accountAction' => 'warn',
            'note' => 'kurallara aykiri',
        ])->assertOk();

        $this->assertSame(1, UserViolation::where('user_id', $author->id)->count());

        $this->actingAsUser($author);
        $this->postJson('/api/v1/moderation/appeals', [
            'caseId' => $case->id,
            'reason' => 'yanlis karar',
        ])->assertOk();

        $appeal = ModerationAppeal::first();
        $this->actingAsRole('moderator');
        $this->postJson('/api/v1/admin/moderation/appeals/'.$appeal->id.'/decide', [
            'outcome' => 'overturn',
            'note' => 'Ogrenci hakli, geri alindi.',
        ])->assertOk();

        $this->assertSame('approved', FeedPost::includingUnmoderated()->find($case->content_id)->moderation_status);
        $this->assertSame(0, UserViolation::where('user_id', $author->id)->count(),
            'An overturned decision must not leave the violation on record.');
    }

    /** The student is always told why, so the note is mandatory. */
    public function test_deciding_an_appeal_requires_a_note(): void
    {
        $author = $this->makeUser('author@arucad.edu.tr');
        $case = $this->caseFor($author);
        $case->update(['decision' => 'remove', 'status' => ModerationCase::STATUS_RESOLVED]);

        $this->actingAsUser($author);
        $this->postJson('/api/v1/moderation/appeals', [
            'caseId' => $case->id, 'reason' => 'itiraz',
        ])->assertOk();

        $appeal = ModerationAppeal::first();
        $this->actingAsRole('moderator');
        $this->postJson('/api/v1/admin/moderation/appeals/'.$appeal->id.'/decide', [
            'outcome' => 'uphold',
        ])->assertStatus(400);
    }

    public function test_a_student_cannot_decide_appeals(): void
    {
        $author = $this->makeUser('author@arucad.edu.tr');
        $case = $this->caseFor($author);
        $case->update(['decision' => 'remove', 'status' => ModerationCase::STATUS_RESOLVED]);

        $this->actingAsUser($author);
        $this->postJson('/api/v1/moderation/appeals', [
            'caseId' => $case->id, 'reason' => 'itiraz',
        ])->assertOk();

        $appeal = ModerationAppeal::first();
        $this->postJson('/api/v1/admin/moderation/appeals/'.$appeal->id.'/decide', [
            'outcome' => 'overturn',
            'note' => 'kendi itirazimi kabul ediyorum',
        ])->assertStatus(403);

        $this->assertSame(ModerationAppeal::STATUS_OPEN, $appeal->fresh()->status);
    }
}
