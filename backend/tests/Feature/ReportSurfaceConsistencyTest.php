<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\ModerationCase;
use App\Models\ModerationReport;
use App\Models\Place;
use App\Models\PostComment;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every report surface has to behave the same way.
 *
 * They did not: each controller hand-rolled its own report, so some
 * recorded the reporter and some did not, some deduplicated and none
 * really did, and the reason was free text everywhere. A report from the
 * chat screen and a report from the feed have to arrive at the queue
 * identically, or the queue ends up measuring which screen the report
 * came from rather than the thing being reported.
 *
 * These tests are deliberately repetitive across surfaces. That is the
 * point — the bug was that the surfaces differed.
 */
class ReportSurfaceConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            ['name' => explode('@', $email)[0], 'password' => bcrypt('x')],
        );
    }

    /** @return array{0: string, 1: string, 2: string} url, targetType, targetId */
    private function surface(string $kind, User $owner): array
    {
        return match ($kind) {
            'post' => (function () use ($owner) {
                $post = FeedPost::create([
                    'id' => 'post-'.uniqid(),
                    'author_id' => $owner->id,
                    'name' => $owner->name,
                    'text' => 'test gonderi',
                    'visibility' => 'everyone',
                    'created_at' => now(),
                ]);

                return ["/api/v1/feed/{$post->id}/report", 'post', $post->id];
            })(),
            'place' => (function () {
                $place = Place::create([
                    'id' => 'place-'.uniqid(),
                    'name' => 'Test Yeri',
                    'category' => 'Sosyal',
                    'lat' => 35.33, 'lng' => 33.32,
                ]);

                return ["/api/v1/places/{$place->id}/report", 'place', $place->id];
            })(),
            'user' => ['/api/v1/social/report-user', 'user', (string) $owner->id],
            'comment' => (function () use ($owner) {
                $post = FeedPost::create([
                    'id' => 'post-'.uniqid(),
                    'author_id' => $owner->id,
                    'name' => $owner->name,
                    'text' => 'ana gonderi',
                    'visibility' => 'everyone',
                    'created_at' => now(),
                ]);
                $comment = PostComment::create([
                    'id' => 'comment-'.uniqid(),
                    'post_id' => $post->id,
                    'user_id' => $owner->id,
                    'text' => 'test yorum',
                    'meta' => 'az önce',
                    'created_at' => now(),
                    'moderation_status' => 'approved',
                ]);

                return ["/api/v1/feed/comments/{$comment->id}/report", 'comment', $comment->id];
            })(),
            'story' => (function () use ($owner) {
                $story = Story::create([
                    'id' => 'story-'.uniqid(),
                    'author_id' => $owner->id,
                    'author_name' => $owner->name,
                    'text' => 'test hikaye',
                    'visibility' => 'friends',
                    'created_at' => now(),
                    'moderation_status' => 'approved',
                ]);

                return ["/api/v1/stories/{$story->id}/report", 'story', $story->id];
            })(),
        };
    }

    public static function surfaces(): array
    {
        return [
            'feed post' => ['post'],
            'place' => ['place'],
            'user profile' => ['user'],
            'comment' => ['comment'],
            'story' => ['story'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function test_a_report_records_the_reporter_and_a_reason_code(string $kind): void
    {
        $owner = $this->makeUser('owner@arucad.edu.tr');
        [$url, $targetType, $targetId] = $this->surface($kind, $owner);
        $reporter = $this->actingAsUser($this->makeUser('reporter@arucad.edu.tr'));

        $payload = ['reasonCode' => 'harassment', 'reason' => 'rahatsiz edici'];
        if ($kind === 'user') {
            $payload['peer'] = $owner->name;
        }

        $this->postJson($url, $payload)->assertOk();

        $report = ModerationReport::where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->first();

        $this->assertNotNull($report, "{$kind}: no normalized report row was written");
        $this->assertSame((int) $reporter->id, (int) $report->reporter_user_id,
            "{$kind}: the reporter was not recorded");
        $this->assertSame('harassment', $report->reason_code,
            "{$kind}: the reason code was not recorded");
        $this->assertNotNull($report->moderation_case_id,
            "{$kind}: the report did not open a case");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function test_repeat_reports_are_deduplicated_on_every_surface(string $kind): void
    {
        $owner = $this->makeUser('owner@arucad.edu.tr');
        [$url, $targetType, $targetId] = $this->surface($kind, $owner);
        $this->actingAsUser($this->makeUser('reporter@arucad.edu.tr'));

        $payload = ['reasonCode' => 'spam'];
        if ($kind === 'user') {
            $payload['peer'] = $owner->name;
        }

        for ($i = 0; $i < 4; $i++) {
            $this->postJson($url, $payload)->assertOk();
        }

        $this->assertSame(1, ModerationReport::where('target_type', $targetType)
            ->where('target_id', $targetId)->count(),
            "{$kind}: repeated reports were not deduplicated");

        $case = ModerationCase::where('content_id', $targetId)->first();
        $this->assertSame(1, (int) $case->report_count,
            "{$kind}: one person was counted as several reporters");
    }

    /**
     * A duplicate is answered exactly like a first report. Anything else
     * confirms the earlier report exists, which tells a reporter their
     * target can be probed and invites a second account.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function test_a_duplicate_is_indistinguishable_from_a_first_report(string $kind): void
    {
        $owner = $this->makeUser('owner@arucad.edu.tr');
        [$url] = $this->surface($kind, $owner);
        $this->actingAsUser($this->makeUser('reporter@arucad.edu.tr'));

        $payload = ['reasonCode' => 'spam'];
        if ($kind === 'user') {
            $payload['peer'] = $owner->name;
        }

        $first = $this->postJson($url, $payload);
        $second = $this->postJson($url, $payload);

        // `meta.request_id` is unique per request by design, so compare
        // the parts a caller could actually learn something from.
        $this->assertSame($first->status(), $second->status());
        $this->assertSame($first->json('data'), $second->json('data'),
            "{$kind}: a duplicate report is distinguishable from a first one");
        $this->assertSame($first->json('error'), $second->json('error'));
    }

    /** Report descriptions are user text and go through the same gate. */
    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function test_an_abusive_report_description_is_refused(string $kind): void
    {
        $owner = $this->makeUser('owner@arucad.edu.tr');
        [$url] = $this->surface($kind, $owner);
        $this->actingAsUser($this->makeUser('reporter@arucad.edu.tr'));

        $payload = [
            'reasonCode' => 'other',
            'reason' => 'lanet zenci defol buradan',
        ];
        if ($kind === 'user') {
            $payload['peer'] = $owner->name;
        }

        $this->postJson($url, $payload)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');

        $this->assertSame(0, ModerationReport::whereNotNull('reporter_user_id')->count(),
            "{$kind}: an abusive report was still stored");
    }

    /** Reporting requires an account — it is not an anonymous channel. */
    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function test_reporting_requires_authentication(string $kind): void
    {
        $owner = $this->makeUser('owner@arucad.edu.tr');
        [$url] = $this->surface($kind, $owner);

        $this->postJson($url, ['reasonCode' => 'spam'])->assertStatus(401);
    }
}
