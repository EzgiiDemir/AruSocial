<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\MediaItem;
use App\Models\ModerationAppeal;
use App\Models\ModerationCase;
use App\Models\ModerationEvent;
use App\Models\ModerationReport;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `moderation:status` reports the failures that look like nothing.
 *
 * Each condition it covers is invisible from outside the system: a
 * stopped classifier presents to a student as "my photo was rejected",
 * and content held with no moderator draining the queue presents as a
 * successful upload that never appears. Both have already happened here.
 *
 * The exit code is the part that matters most, because this is meant to
 * run unattended — a check that reports a problem and still exits 0 is
 * a check nobody finds out about.
 */
class ModerationStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'moderation.image.enabled' => true,
            'moderation.image.base_url' => 'http://image-moderation.test',
            'moderation.enforcement.enabled' => true,
        ]);

    }

    /**
     * Exactly one fake per test. Registering one in setUp and another in
     * the test merges the stubs rather than replacing them, so the first
     * registered response silently wins and the test asserts against a
     * classifier state it never actually set up.
     */
    private function classifierReturns(mixed $response): void
    {
        Http::fake(['image-moderation.test/*' => $response]);
    }

    private function classifierIsHealthy(): void
    {
        $this->classifierReturns(Http::response([
            'ok' => true, 'model' => 'Falconsai/nsfw_image_detection',
            'model_version' => 'abc123',
            'signals' => ['nsfw' => 'Falconsai/nsfw_image_detection', 'clip' => 'openai/clip-vit-base-patch32'],
        ]));
    }

    private function heldMedia(string $uploadedAt): void
    {
        MediaItem::create([
            'id' => 'media-'.Str::uuid(),
            'file_path' => 'media/x.jpg',
            'file_name' => 'x.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10,
            'uploaded_at' => $uploadedAt,
            'uploaded_by' => 'student',
            'moderation_status' => 'pending',
        ]);
    }

    // ---- the healthy case ------------------------------------------

    /**
     * Guarded because a check that cannot pass gets ignored, and an
     * ignored check is the same as no check.
     */
    public function test_a_healthy_pipeline_exits_zero(): void
    {
        $this->classifierIsHealthy();

        $this->artisan('moderation:status')
            ->expectsOutputToContain('No problems')
            ->assertExitCode(0);
    }

    // ---- conditions that must be caught ----------------------------

    public function test_an_unreachable_classifier_is_reported(): void
    {
        $this->classifierReturns(fn () => throw new \RuntimeException('refused'));

        $this->artisan('moderation:status')
            ->expectsOutputToContain('unreachable')
            ->assertExitCode(1);
    }

    public function test_a_loaded_service_with_broken_weights_is_distinguished(): void
    {
        $this->classifierReturns(Http::response([], 503));

        // A different fix from "the process is not running", so it must
        // not collapse into the same word.
        $this->artisan('moderation:status')
            ->expectsOutputToContain('model_not_loaded')
            ->assertExitCode(1);
    }

    public function test_content_stuck_in_review_is_reported(): void
    {
        $this->classifierIsHealthy();
        $this->heldMedia(now()->subDays(9)->toDateTimeString());

        $this->artisan('moderation:status')
            ->expectsOutputToContain('nobody is draining the review queue')
            ->assertExitCode(1);
    }

    /**
     * Depth alone is not a problem — a queue with recent items in it is
     * a queue being used. Age is the signal, so a fresh backlog must not
     * page anyone.
     */
    public function test_recently_held_content_is_not_a_problem(): void
    {
        $this->classifierIsHealthy();
        $this->heldMedia(now()->subMinutes(10)->toDateTimeString());

        $this->artisan('moderation:status')
            ->expectsOutputToContain('No problems')
            ->assertExitCode(0);
    }

    public function test_the_stale_threshold_is_configurable(): void
    {
        $this->classifierIsHealthy();
        $this->heldMedia(now()->subHours(3)->toDateTimeString());

        $this->artisan('moderation:status', ['--stale-hours' => 2])->assertExitCode(1);
        $this->artisan('moderation:status', ['--stale-hours' => 48])->assertExitCode(0);
    }

    /**
     * A running service with CLIP missing is the dangerous case: the
     * process is up, /health says ok, and every image is silently judged
     * on the NSFW score alone — the configuration that was measured
     * publishing a nude photograph at 0.0090.
     */
    public function test_a_missing_clip_signal_is_reported_even_though_the_service_is_up(): void
    {
        $this->classifierReturns(Http::response([
            'ok' => true, 'model' => 'Falconsai/nsfw_image_detection',
            'model_version' => 'abc123',
            'signals' => ['nsfw' => 'Falconsai/nsfw_image_detection'],
        ]));

        $this->artisan('moderation:status')
            ->expectsOutputToContain('CLIP signal is not loaded')
            ->assertExitCode(1);
    }

    /**
     * The semantic text layer degrades to the phrase list when it cannot
     * be reached, which publishes paraphrase without saying so.
     */
    public function test_an_unreachable_text_layer_is_reported(): void
    {
        $this->classifierIsHealthy();
        config([
            'moderation.text.enabled' => true,
            'moderation.text.base_url' => 'http://text-moderation.test',
        ]);
        Http::fake([
            'image-moderation.test/*' => Http::response([
                'ok' => true, 'model' => 'm', 'model_version' => 'v',
                'signals' => ['nsfw' => 'm', 'clip' => 'c'],
            ]),
            'text-moderation.test/*' => Http::response([], 500),
        ]);

        $this->artisan('moderation:status')
            ->expectsOutputToContain('semantic text layer is unreachable')
            ->assertExitCode(1);
    }

    /**
     * Reports carry `reported_at`, not `created_at` — the model sets
     * `$timestamps = false`. Reading the wrong column printed "oldest -h"
     * for every backlog, which is the one number the 24-hour response
     * commitment depends on.
     */
    public function test_report_age_is_measured_from_the_column_that_is_populated(): void
    {
        $this->classifierIsHealthy();
        ModerationReport::create([
            'id' => 'report-'.Str::uuid(),
            'kind' => 'post',
            'target_id' => 'post-1',
            'target_label' => 'post-1',
            'reason' => 'spam',
            'reported_at' => now()->subHours(30),
            'status' => 'open',
        ]);

        $this->assertSame(1, Artisan::call('moderation:status', ['--json' => true]));
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(1, $decoded['reports']['unresolved']);
        $this->assertNotNull($decoded['reports']['oldest_hours'],
            'Report age came back null, so a stale backlog would never alert.');
        $this->assertGreaterThan(24, $decoded['reports']['oldest_hours']);
    }

    /**
     * Overturned appeals are the only false-positive signal this system
     * can measure in production. A high rate means moderation is refusing
     * legitimate content while every other line of the report looks
     * healthy — which is exactly the failure nobody notices.
     */
    /** Appeals reference a real case and a real author. */
    private function appeal(string $id, string $status): void
    {
        $user = User::firstOrCreate(
            ['email' => 'itiraz@arucad.edu.tr'],
            ['name' => 'Itiraz', 'password' => bcrypt('x')],
        );
        // Real UUIDs: `moderation_cases.id` and `moderation_appeals.id` are
        // uuid columns in PostgreSQL and plain varchar in SQLite, so a
        // readable fixture id like "case-over-1" passed locally and was
        // rejected outright by the database the app actually runs on.
        $case = ModerationCase::create([
            'id' => (string) Str::uuid(),
            'content_type' => 'post',
            'content_id' => 'post-'.$id,
            'user_id' => $user->id,
            'source' => 'user_report',
            'priority' => 50,
            'status' => 'resolved',
            'decision' => 'remove',
        ]);
        ModerationAppeal::create([
            'id' => (string) Str::uuid(),
            'moderation_case_id' => $case->id,
            'user_id' => $user->id,
            'original_decision' => 'remove',
            'reason' => 'itiraz gerekcesi',
            'status' => $status,
        ]);
    }

    public function test_a_high_appeal_overturn_rate_is_reported(): void
    {
        $this->classifierIsHealthy();

        foreach (range(1, 4) as $i) {
            $this->appeal('over-'.$i, 'overturned');
        }
        $this->appeal('upheld-1', 'upheld');

        $this->artisan('moderation:status')
            ->expectsOutputToContain('appeals overturned')
            ->assertExitCode(1);
    }

    /**
     * One overturn out of one is not evidence of anything. Alerting on it
     * would train people to ignore the alert.
     */
    public function test_a_single_overturn_does_not_alert(): void
    {
        $this->classifierIsHealthy();
        $this->appeal('solo', 'overturned');

        $this->artisan('moderation:status')
            ->expectsOutputToContain('No problems')
            ->assertExitCode(0);
    }

    /** "Which policy is live right now" is the first question after a bad call. */
    public function test_the_live_policy_versions_are_reported(): void
    {
        $this->classifierIsHealthy();

        $this->assertSame(0, Artisan::call('moderation:status', ['--json' => true]));
        $decoded = json_decode(Artisan::output(), true);

        $this->assertArrayHasKey('policy', $decoded);
        $this->assertNotEmpty($decoded['policy']['image_policy']);
        $this->assertContains('nsfw', $decoded['policy']['image_categories']);
    }

    /**
     * Switched off deliberately during testing, so it is not an outage —
     * but the only way it goes wrong is by being forgotten, which is
     * exactly what an unattended check is for.
     */
    public function test_disabled_enforcement_is_surfaced(): void
    {
        $this->classifierIsHealthy();
        config(['moderation.enforcement.enabled' => false]);

        $this->artisan('moderation:status')
            ->expectsOutputToContain('Account enforcement is OFF')
            ->assertExitCode(1);
    }

    // ---- output --------------------------------------------------

    public function test_json_output_is_machine_readable(): void
    {
        $this->classifierIsHealthy();
        ModerationEvent::create([
            'id' => (string) Str::uuid(),
            'user_id' => '1',
            'content_type' => 'text',
            'source_feature' => 'feed.store',
            'action' => ModerationEvent::ACTION_REJECTED,
            'flagged' => true,
            'decided_by' => 'local',
        ]);

        // `Artisan::call` rather than `$this->artisan`, because this test
        // needs the text back and only the former populates `output()`.
        $exit = Artisan::call('moderation:status', ['--json' => true]);
        $this->assertSame(0, $exit);

        // Round-tripped rather than string-matched: the contract is that
        // a monitor can parse it, not that it contains some substring.
        $decoded = json_decode(Artisan::output(), true);

        $this->assertIsArray($decoded, 'Output did not parse as JSON.');
        $this->assertSame('on', $decoded['enforcement']);
        $this->assertSame('ok', $decoded['classifier']['status']);
        $this->assertSame(1, $decoded['decisions_24h']['rejected']);
        $this->assertSame([], $decoded['problems']);
    }

    /**
     * The command exists to count rows the global scope hides from every
     * other query path. If it inherited that scope it would report an
     * empty queue no matter how much was stuck in it.
     */
    public function test_held_content_is_counted_past_the_approved_only_scope(): void
    {
        $this->classifierIsHealthy();
        $user = $this->actingAsUser();

        FeedPost::create([
            'id' => 'post-held',
            'author_id' => $user->id,
            'name' => $user->name,
            'text' => 'beklemede',
            'moderation_status' => 'pending',
            'created_at' => now()->subDays(4),
        ]);

        $this->assertSame(0, FeedPost::count(),
            'Precondition: the scope must hide it from ordinary queries.');

        $this->assertSame(1, Artisan::call('moderation:status', ['--json' => true]));

        $decoded = json_decode(Artisan::output(), true);
        $this->assertSame(1, $decoded['held']['post']['count']);
    }

    // ---- the alert ---------------------------------------------------

    /**
     * A problem has to reach somewhere a person looks.
     *
     * The command previously computed its problem list, printed it to a
     * terminal nobody was watching and returned a non-zero exit code.
     * Under the scheduler that exit code goes nowhere — the classifier
     * could be down for a week with every upload failing closed, and the
     * only trace would be students saying their photos "don't work".
     */
    public function test_a_problem_is_written_to_the_log(): void
    {
        $this->classifierReturns(Http::response([], 500));

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'moderation.status.problems'
                    && $context['problems'] !== []
                    && str_contains(implode(' ', $context['problems']), 'classifier');
            });

        $this->assertSame(1, Artisan::call('moderation:status'));
    }

    /**
     * The healthy path must stay quiet in the error log, or the alert
     * becomes noise and gets muted — which is the same as not having one.
     */
    public function test_a_healthy_pipeline_raises_nothing(): void
    {
        $this->classifierIsHealthy();

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->never();

        $this->assertSame(0, Artisan::call('moderation:status'));
    }

    /**
     * The hourly schedule is the difference between a check that exists
     * and a check that runs. Asserted here because nothing else would
     * notice its removal.
     */
    public function test_the_check_is_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'moderation:status'));

        $this->assertCount(1, $events, 'moderation:status is not scheduled.');
        $this->assertSame('0 * * * *', $events->first()->expression,
            'The pipeline check should run hourly.');
    }
}
