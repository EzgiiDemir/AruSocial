<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\ModerationReport;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('moderator');
    }

    // Real bug fix: resolving a "post" report with action=removed used to
    // only flip the report's own status — the reported FeedPost stayed
    // live, so a moderator's decision had no visible effect for students.
    public function test_resolving_a_post_report_as_removed_actually_deletes_the_post(): void
    {
        $post = FeedPost::create([
            'id' => 'post-1', 'author_id' => '1', 'name' => 'Test', 'text' => 'hi',
            'meta' => 'now', 'created_at' => now(),
        ]);
        $report = ModerationReport::create([
            'id' => 'report-1', 'kind' => 'post', 'target_id' => $post->id,
            'target_label' => 'hi', 'reason' => 'spam', 'reported_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/admin/reports/{$report->id}/resolve", ['action' => 'removed']);

        $response->assertOk();
        $this->assertDatabaseMissing('feed_posts', ['id' => 'post-1']);
        $this->assertDatabaseHas('moderation_reports', ['id' => 'report-1', 'action' => 'removed']);
    }

    public function test_resolving_a_post_report_as_dismissed_keeps_the_post(): void
    {
        $post = FeedPost::create([
            'id' => 'post-1', 'author_id' => '1', 'name' => 'Test', 'text' => 'hi',
            'meta' => 'now', 'created_at' => now(),
        ]);
        $report = ModerationReport::create([
            'id' => 'report-1', 'kind' => 'post', 'target_id' => $post->id,
            'target_label' => 'hi', 'reason' => 'spam', 'reported_at' => now(),
        ]);

        $this->postJson("/api/v1/admin/reports/{$report->id}/resolve", ['action' => 'dismissed'])->assertOk();

        $this->assertDatabaseHas('feed_posts', ['id' => 'post-1']);
    }

    public function test_resolving_a_place_report_as_removed_does_not_delete_the_real_place(): void
    {
        \App\Models\Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Social', 'lat' => 1, 'lng' => 1]);
        $report = ModerationReport::create([
            'id' => 'report-1', 'kind' => 'place', 'target_id' => 'p1',
            'target_label' => 'Garden', 'reason' => 'inaccurate', 'reported_at' => now(),
        ]);

        $this->postJson("/api/v1/admin/reports/{$report->id}/resolve", ['action' => 'removed'])->assertOk();

        $this->assertDatabaseHas('places', ['id' => 'p1']);
    }

    public function test_moderator_can_directly_delete_a_review(): void
    {
        \App\Models\Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Social', 'lat' => 1, 'lng' => 1]);
        $review = Review::create([
            'id' => 'review-1', 'place_id' => 'p1', 'user_id' => 1,
            'rating' => 5, 'comment' => 'great', 'meta' => 'now', 'created_at' => now(),
        ]);

        $this->postJson("/api/v1/admin/reviews/{$review->id}/delete")->assertOk();

        $this->assertDatabaseMissing('reviews', ['id' => 'review-1']);
    }
}
