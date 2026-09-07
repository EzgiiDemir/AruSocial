<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaginatedListRequest;
use App\Models\Notification as InboxNotification;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    use ApiResponds;

    public function index(PaginatedListRequest $request): JsonResponse
    {
        $me = $this->currentUser();
        $query = InboxNotification::where('user_id', $me->id)
            ->with('actor')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return $this->okPage($query, $request, fn ($n) => [
            'id' => $n->id,
            'kind' => $n->kind,
            'title' => $n->title,
            'body' => $n->body,
            'data' => $n->data,
            'read' => $n->read_at !== null,
            'createdAt' => $n->created_at?->toIso8601String(),
            'actorUserId' => $n->actor_user_id === null ? null : (string) $n->actor_user_id,
            'actorName' => $n->actor?->name,
            'actorAvatarUrl' => $n->actor?->avatar_url,
        ]);
    }

    public function markRead(string $id): JsonResponse
    {
        $me = $this->currentUser();
        $updated = InboxNotification::where('id', $id)->where('user_id', $me->id)->update(['read_at' => now()]);
        if ($updated === 0) {
            return $this->fail(404, 'NOTIFICATION_NOT_FOUND', 'Notification not found.');
        }

        return $this->ok(['read' => true]);
    }

    public function markAllRead(): JsonResponse
    {
        $me = $this->currentUser();
        InboxNotification::where('user_id', $me->id)->whereNull('read_at')->update(['read_at' => now()]);

        return $this->ok(['read' => true]);
    }
}
