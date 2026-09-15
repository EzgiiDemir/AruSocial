<?php

namespace Tests\Feature;

use App\Jobs\ModerateImageJob;
use App\Models\MediaItem;
use App\Models\ModerationAppeal;
use App\Models\ModerationCase;
use App\Services\Moderation\Image\ImageModerationRunner;
use App\Services\Moderation\Workflow\ReportReason;
use App\Services\Moderation\Workflow\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Content the machine holds must reach a person.
 *
 * Only user reports used to open a case, which meant a photo the
 * classifier held for review had a status and nothing else: private, in
 * no queue, with no route to a moderator. That is worse than the review
 * backlog it replaced — a backlog is at least visible, and this was not.
 *
 * The same gap made the automatic decisions most worth contesting the
 * only ones that could not be: an appeal is filed against a case, and
 * blocked content had no case.
 */
class AutomaticVerdictReachesHumanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'moderation.enabled' => false,
            'services.moderation.enabled' => false,
            'services.moderation.openai_key' => '',
            'services.local_moderation.binary' => '',

            'moderation.image.enabled' => true,
            'moderation.image.async' => true,
            'moderation.image.base_url' => 'http://image-moderation.test',
            'moderation.image.thresholds' => [
                'nsfw' => ['review' => 0.35, 'block' => 0.85],
            ],
        ]);
    }

    /** @param array<string, float> $scores */
    private function scannerReturns(array $scores): void
    {
        Http::fake(['image-moderation.test/*' => Http::response([
            'success' => true,
            'model' => 'Falconsai/nsfw_image_detection',
            'model_version' => 'b0d2e6a9',
            'scores' => $scores,
            'latency_ms' => 40,
        ])]);
    }

    private function uploadAndScan(array $scores): array
    {
        Storage::fake('local');
        $user = $this->actingAsUser();
        $this->scannerReturns($scores);

        $created = $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg('photo.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        (new ModerateImageJob($created['id']))
            ->handle(app(ImageModerationRunner::class));

        return [$user, $created['id']];
    }

    public function test_a_held_image_opens_a_case_for_a_moderator(): void
    {
        [, $id] = $this->uploadAndScan(['normal' => 0.45, 'nsfw' => 0.55]);

        $case = ModerationCase::where('content_id', $id)->first();
        $this->assertNotNull($case, 'Held content never reached a moderator.');
        $this->assertSame(ModerationCase::SOURCE_AUTOMATIC, $case->source);
        $this->assertSame('hold', $case->recommendation);
    }

    public function test_a_held_image_is_visible_in_the_moderator_queues(): void
    {
        [, $id] = $this->uploadAndScan(['normal' => 0.45, 'nsfw' => 0.55]);

        $this->actingAsRole('moderator');

        // The case queue, and the older media queue, which listed only
        // `pending` and so hid everything the background worker held.
        $cases = $this->getJson('/api/v1/admin/moderation/cases')->assertOk()->json('data');
        $this->assertContains($id, array_column($cases, 'contentId'));

        $queue = $this->getJson('/api/v1/admin/moderation/queue')->assertOk()->json('data');
        $this->assertContains($id, array_column($queue, 'id'),
            'A review-held item was missing from the media queue.');
    }

    /** Seeing an item and then being refused when acting on it is a bug. */
    public function test_a_moderator_can_resolve_a_review_held_item(): void
    {
        [, $id] = $this->uploadAndScan(['normal' => 0.45, 'nsfw' => 0.55]);

        $this->actingAsRole('moderator');
        $this->postJson("/api/v1/admin/moderation/queue/{$id}/resolve", [
            'action' => 'approved',
        ])->assertOk();

        $this->assertSame('approved', MediaItem::find($id)->moderation_status);
    }

    public function test_a_blocked_image_can_be_appealed(): void
    {
        [$user, $id] = $this->uploadAndScan(['normal' => 0.03, 'nsfw' => 0.97]);

        $this->assertSame('blocked', MediaItem::find($id)->moderation_status);

        $case = ModerationCase::where('content_id', $id)->first();
        $this->assertNotNull($case, 'Blocked content had no case to appeal against.');

        $this->actingAsUser($user);
        $this->postJson('/api/v1/moderation/appeals', [
            'caseId' => $case->id,
            'reason' => 'Bu benim kendi calismam, yanlis karar.',
        ])->assertOk();

        $this->assertSame(1, ModerationAppeal::where('moderation_case_id', $case->id)->count());
    }

    /**
     * A queue containing every published photo is a queue nobody reads.
     */
    public function test_an_allowed_image_opens_no_case(): void
    {
        [, $id] = $this->uploadAndScan(['normal' => 0.99, 'nsfw' => 0.01]);

        $this->assertSame('approved', MediaItem::find($id)->moderation_status);
        $this->assertSame(0, ModerationCase::where('content_id', $id)->count());
    }

    /** A report and a machine verdict about one photo are one item of work. */
    public function test_a_report_and_an_automatic_verdict_share_one_case(): void
    {
        [, $id] = $this->uploadAndScan(['normal' => 0.45, 'nsfw' => 0.55]);

        app(ReportService::class)->report(
            reporter: $this->actingAsUser(),
            targetType: 'image',
            targetId: $id,
            reason: ReportReason::SexualContent,
        );

        $this->assertSame(1, ModerationCase::where('content_id', $id)->count(),
            'The same photo produced two separate cases.');
    }

    /** The more urgent signal decides when a human looks. */
    public function test_reuse_never_lowers_urgency(): void
    {
        [, $id] = $this->uploadAndScan(['normal' => 0.03, 'nsfw' => 0.97]);

        $before = (int) ModerationCase::where('content_id', $id)->value('priority');

        app(ReportService::class)->report(
            reporter: $this->actingAsUser(),
            targetType: 'image',
            targetId: $id,
            // Spam is the least urgent reason there is.
            reason: ReportReason::Spam,
        );

        $this->assertLessThanOrEqual($before,
            (int) ModerationCase::where('content_id', $id)->value('priority'));
    }
}
