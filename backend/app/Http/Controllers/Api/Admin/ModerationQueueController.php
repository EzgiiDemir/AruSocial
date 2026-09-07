<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Events\CampusDataChanged;
use App\Http\Requests\PaginatedListRequest;
use App\Http\Requests\ResolveModerationQueueRequest;
use App\Models\MediaItem;
use App\Models\ModerationReport;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

// Human visual moderation queue for every media upload. Distinct from
// student-filed post/place reports — those stay on ReportController.
class ModerationQueueController extends Controller
{
    use ApiResponds;

    public function index(PaginatedListRequest $request): JsonResponse
    {
        $query = MediaItem::where('moderation_status', 'pending')
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id');

        return $this->okPage($query, $request, fn (MediaItem $m) => [
            'id' => $m->id,
            // Pending files must never use the public media URL. A reviewer
            // receives this five-minute, tamper-proof preview URL only as a
            // result of passing the moderation permission check above.
            'url' => URL::temporarySignedRoute(
                'api.media.review-file',
                now()->addMinutes(5),
                ['id' => $m->id],
            ),
            'fileName' => $m->file_name,
            'mimeType' => $m->mime_type,
            'uploadedAt' => $m->uploaded_at?->toIso8601String(),
            'uploadedBy' => $m->uploaded_by,
            'moderationStatus' => $m->moderation_status,
        ]);
    }

    public function resolve(ResolveModerationQueueRequest $request, string $id): JsonResponse
    {
        $item = MediaItem::find($id);
        if (! $item) {
            return $this->fail(404, 'MEDIA_NOT_FOUND', 'Media item not found.');
        }
        if ($item->moderation_status !== 'pending') {
            return $this->fail(409, 'ALREADY_REVIEWED', 'This media item is not awaiting review.');
        }

        $action = $request->input('action'); // approved | rejected
        $item->moderation_status = $action;
        $item->save();

        if ($action === 'rejected') {
            Storage::disk(MediaItem::disk())->delete($item->file_path);
            // A reviewer-confirmed policy violation is a real consequence for
            // the submitting account. Three strikes trigger the existing
            // account-wide ban middleware; an admin library item has no owner.
            if ($item->user_id !== null && ($submitter = User::find($item->user_id))) {
                ModerationService::recordMediaViolation($submitter, 'reviewer_confirmed');
            }
            // Keep the row so audit/history can reference it, or delete —
            // product choice: keep metadata, clear file. Soft keep.
        }

        ModerationReport::create([
            'id' => $this->newId('report'),
            'kind' => 'media',
            'target_id' => $item->id,
            'target_label' => $item->file_name,
            'reason' => 'human_queue_'.$action,
            'reported_at' => now(),
            'action' => $action === 'approved' ? 'dismissed' : 'removed',
        ]);

        AuditLogger::logAsCurrentUser('moderation', 'media', $item->file_name.' → '.$action);
        $this->announce($item->id, $action);

        return $this->ok([
            'id' => $item->id,
            'moderationStatus' => $item->moderation_status,
        ]);
    }

    private function announce(string $id, string $action): void
    {
        try {
            broadcast(new CampusDataChanged(['media', 'moderation'], $action, $id));
        } catch (\Throwable) {
            // Moderation status is already persisted and visible by REST.
        }
    }
}
