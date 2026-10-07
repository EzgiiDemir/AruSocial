<?php

namespace App\Http\Controllers\Api\Admin;

use App\Events\CampusDataChanged;
use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaginatedListRequest;
use App\Models\FeedPost;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedModerationController extends Controller
{
    use ApiResponds;

    public function index(PaginatedListRequest $request): JsonResponse
    {
        $query = FeedPost::includingUnmoderated()->with(['comments.user'])
            ->withCount('likes')
            ->where('workflow_status', 'pending_review')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return $this->okPage($query, $request, function (FeedPost $p) {
            return [
                'id' => $p->id,
                'authorId' => (string) $p->author_id,
                'name' => $p->name,
                'text' => $p->text,
                'meta' => $p->meta,
                'imageUrl' => $p->image_url,
                'visibility' => $p->visibility,
                'postType' => $p->post_type,
                'official' => $p->official,
                'workflowStatus' => $p->workflow_status,
                'reviewNote' => $p->review_note,
                'createdAt' => $p->created_at?->toIso8601String(),
            ];
        });
    }

    public function approve(string $id): JsonResponse
    {
        $post = FeedPost::includingUnmoderated()->find($id);
        if (! $post) {
            return $this->fail(404, 'POST_NOT_FOUND', 'Post not found.');
        }
        if ($post->workflow_status !== 'pending_review') {
            return $this->fail(409, 'ALREADY_REVIEWED', 'This post is not awaiting review.');
        }
        $post->update(['workflow_status' => 'published', 'review_note' => null, 'moderation_status' => 'approved']);
        AuditLogger::logAsCurrentUser('moderation', 'post', $post->id.' → published');
        $this->announce($post->id, 'published', ['feed', 'moderation']);

        return $this->ok(['id' => $post->id, 'workflowStatus' => $post->fresh()->workflow_status]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $post = FeedPost::includingUnmoderated()->find($id);
        if (! $post) {
            return $this->fail(404, 'POST_NOT_FOUND', 'Post not found.');
        }
        if ($post->workflow_status !== 'pending_review') {
            return $this->fail(409, 'ALREADY_REVIEWED', 'This post is not awaiting review.');
        }
        $note = trim((string) $request->input('reviewNote', ''));
        $post->update([
            'workflow_status' => 'rejected',
            'review_note' => $note !== '' ? $note : 'rejected',
            'moderation_status' => 'blocked',
        ]);
        AuditLogger::logAsCurrentUser('moderation', 'post', $post->id.' → rejected');
        $this->announce($post->id, 'rejected', ['moderation']);

        return $this->ok(['id' => $post->id, 'workflowStatus' => $post->fresh()->workflow_status]);
    }

    /** @param list<string> $resources */
    private function announce(string $id, string $action, array $resources): void
    {
        try {
            broadcast(new CampusDataChanged($resources, $action, $id));
        } catch (\Throwable) {
            // REST moderation state remains the source of truth.
        }
    }
}
