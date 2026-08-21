<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\ModerationReport;
use App\Services\AuditLogger;
use App\Services\RealtimePublisher;
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
        AuditLogger::log($this->currentUser()->name, 'moderation_resolve', 'report',
            "{$report->target_label} → {$report->action}");
        RealtimePublisher::emit('moderation.updated', 'admin', 'report', $report->id, (string) $this->currentUser()->id);

        return $this->ok(['resolved' => true]);
    }
}
