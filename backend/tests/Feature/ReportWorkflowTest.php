<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\ModerationCase;
use App\Models\ModerationReport;
use App\Models\User;
use App\Models\UserViolation;
use App\Services\Moderation\Workflow\AccountEnforcementPolicy;
use App\Services\Moderation\Workflow\ReportReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reports are a signal, not a verdict.
 *
 * The rules these pin down are the ones that decide whether moderation
 * can be captured by whoever organises the most clicks: a single account
 * cannot manufacture a crowd, a crowd cannot remove content by itself,
 * and nothing here touches an account without a human confirming it.
 */
class ReportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /** actingAsUser() takes a model, so each extra reporter is made here. */
    private function makeUser(string $email): \App\Models\User
    {
        return \App\Models\User::firstOrCreate(
            ['email' => $email],
            ['name' => explode('@', $email)[0], 'password' => bcrypt('x')],
        );
    }

    private function makePost(User $author, string $text = 'kampus fotografi'): FeedPost
    {
        return FeedPost::create([
            'id' => 'post-'.uniqid(),
            'author_id' => $author->id,
            'name' => $author->name,
            'text' => $text,
            'visibility' => 'everyone',
            'created_at' => now(),
        ]);
    }

    public function test_a_student_can_report_a_post(): void
    {
        $author = $this->actingAsUser();
        $target = $this->makePost($author);
        $reporter = $this->actingAsUser($this->makeUser('reporter@arucad.edu.tr'));

        $this->postJson("/api/v1/feed/{$target->id}/report", [
            'reasonCode' => 'harassment',
            'reason' => 'Bana surekli hakaret ediyor.',
        ])->assertOk()->assertJsonPath('data.reported', true);

        $report = ModerationReport::where('target_id', $target->id)->first();
        $this->assertNotNull($report);
        $this->assertSame((int) $reporter->id, (int) $report->reporter_user_id);
        $this->assertSame('harassment', $report->reason_code);
        $this->assertNotNull($report->moderation_case_id);
    }

    /**
     * One person clicking ten times is not ten reports. Counting rows
     * instead of people is exactly how a single account fabricates the
     * appearance of a consensus.
     */
    public function test_repeated_reports_from_one_person_count_once(): void
    {
        $author = $this->actingAsUser();
        $target = $this->makePost($author);
        $this->actingAsUser($this->makeUser('spammer@arucad.edu.tr'));

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/v1/feed/{$target->id}/report", [
                'reasonCode' => 'spam',
            ])->assertOk();
        }

        $this->assertSame(1, ModerationReport::where('target_id', $target->id)->count());

        $case = ModerationCase::where('content_id', $target->id)->first();
        $this->assertSame(1, (int) $case->report_count);
    }

    public function test_independent_reporters_are_counted_separately(): void
    {
        $author = $this->actingAsUser();
        $target = $this->makePost($author);

        foreach (['a@arucad.edu.tr', 'b@arucad.edu.tr', 'c@arucad.edu.tr'] as $email) {
            $this->actingAsUser($this->makeUser($email));
            $this->postJson("/api/v1/feed/{$target->id}/report", [
                'reasonCode' => 'harassment',
            ])->assertOk();
        }

        $case = ModerationCase::where('content_id', $target->id)->first();
        $this->assertSame(3, (int) $case->report_count);
    }

    /** Every report converges on one case, not one case per report. */
    public function test_reports_share_a_single_case(): void
    {
        $author = $this->actingAsUser();
        $target = $this->makePost($author);

        foreach (['a@arucad.edu.tr', 'b@arucad.edu.tr'] as $email) {
            $this->actingAsUser($this->makeUser($email));
            $this->postJson("/api/v1/feed/{$target->id}/report", ['reasonCode' => 'hate'])->assertOk();
        }

        $this->assertSame(1, ModerationCase::where('content_id', $target->id)->count());
    }

    /**
     * The rule that matters most. Reports raise priority and open a case;
     * they never remove content or penalise anyone on their own.
     */
    public function test_reports_alone_never_remove_content_or_punish_the_author(): void
    {
        $author = $this->actingAsUser();
        $target = $this->makePost($author);

        foreach (['a@arucad.edu.tr', 'b@arucad.edu.tr', 'c@arucad.edu.tr', 'd@arucad.edu.tr'] as $email) {
            $this->actingAsUser($this->makeUser($email));
            $this->postJson("/api/v1/feed/{$target->id}/report", ['reasonCode' => 'hate'])->assertOk();
        }

        $this->assertNotNull(FeedPost::find($target->id), 'Reports must not delete content.');
        $this->assertSame(0, (int) $author->fresh()->strikes);
        $this->assertNull($author->fresh()->banned_until);
        $this->assertSame(0, UserViolation::where('user_id', $author->id)->count());
    }

    /** A more urgent claim pulls the case up the queue. */
    public function test_a_severe_reason_raises_case_priority(): void
    {
        $author = $this->actingAsUser();
        $spammed = $this->makePost($author, 'spam post');
        $threatened = $this->makePost($author, 'threat post');

        $this->actingAsUser($this->makeUser('a@arucad.edu.tr'));
        $this->postJson("/api/v1/feed/{$spammed->id}/report", ['reasonCode' => 'spam'])->assertOk();
        $this->postJson("/api/v1/feed/{$threatened->id}/report", ['reasonCode' => 'threat'])->assertOk();

        $spamCase = ModerationCase::where('content_id', $spammed->id)->first();
        $threatCase = ModerationCase::where('content_id', $threatened->id)->first();

        $this->assertLessThan((int) $spamCase->priority, (int) $threatCase->priority,
            'A threat must be worked before spam.');
    }

    public function test_an_unknown_reason_code_falls_back_rather_than_failing(): void
    {
        $author = $this->actingAsUser();
        $target = $this->makePost($author);
        $this->actingAsUser($this->makeUser('a@arucad.edu.tr'));

        $this->postJson("/api/v1/feed/{$target->id}/report", [
            'reasonCode' => 'because_i_dislike_them',
        ])->assertOk();

        $this->assertSame('other',
            ModerationReport::where('target_id', $target->id)->value('reason_code'));
    }

    public function test_reporting_writes_an_audit_entry(): void
    {
        $author = $this->actingAsUser();
        $target = $this->makePost($author);
        $reporter = $this->actingAsUser($this->makeUser('a@arucad.edu.tr'));

        $this->postJson("/api/v1/feed/{$target->id}/report", ['reasonCode' => 'hate'])->assertOk();

        $row = DB::table('moderation_audit_log')
            ->where('action', 'report_created')
            ->where('target_id', $target->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame((int) $reporter->id, (int) $row->actor_id);
    }
}
