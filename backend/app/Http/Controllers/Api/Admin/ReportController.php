<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\FeedPost;
use App\Models\ModerationReport;
use App\Models\Review;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use ApiResponds;

    public function index(): JsonResponse
    {
        $reports = ModerationReport::orderByDesc('reported_at')->get()->map(fn ($r) => [
            'id' => $r->id,
            'kind' => $r->kind,
            'targetId' => $r->target_id,
            'targetLabel' => $r->target_label,
            'reason' => $r->reason,
            'reportedAt' => $r->reported_at?->toIso8601String(),
        ]);

        return $this->ok($reports);
    }

    public function resolve(Request $request, string $id): JsonResponse
    {
        $report = ModerationReport::find($id);
        if (! $report) {
            return $this->fail(404, 'REPORT_NOT_FOUND', 'Report not found.');
        }
        $report->action = $request->input('action');
        $report->save();
        AuditLogger::logAsCurrentUser('moderation', $report->kind, $report->target_label.' → '.$report->action);

        // "Removed" used to only flip this report's own status — the
        // reported post itself stayed live, so a moderator's decision had
        // no real effect a student would ever see. Now it actually takes
        // the post down.
        if ($report->action === 'removed' && $report->kind === 'post') {
            FeedPost::where('id', $report->target_id)->delete();
        }

        return $this->ok(['resolved' => true]);
    }

    // Reviews have no report queue of their own (low volume — the
    // ceremony of a full ModerationReport row isn't worth it) — a
    // moderator removing one is a direct action instead.
    public function destroyReview(string $id): JsonResponse
    {
        $review = Review::find($id);
        if ($review) {
            AuditLogger::logAsCurrentUser('moderation', 'review', "Yorum silindi: {$review->id}");
            $review->delete();
        }

        return $this->ok(['deleted' => true]);
    }
}
