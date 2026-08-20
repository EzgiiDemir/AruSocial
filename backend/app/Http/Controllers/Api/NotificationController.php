<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Notification as InboxNotification;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    use ApiResponds;

    public function index(): JsonResponse
    {
        $me = $this->currentUser();
        $rows = InboxNotification::where('user_id', $me->id)->orderByDesc('created_at')->limit(100)->get();

        return $this->ok($rows->map(fn ($n) => [
            'id' => $n->id,
            'kind' => $n->kind,
            'title' => $n->title,
            'body' => $n->body,
            'read' => $n->read_at !== null,
            'createdAt' => $n->created_at?->toIso8601String(),
        ]));
    }

    public function markRead(string $id): JsonResponse
    {
        $me = $this->currentUser();
        InboxNotification::where('id', $id)->where('user_id', $me->id)->update(['read_at' => now()]);

        return $this->ok(['read' => true]);
    }

    public function markAllRead(): JsonResponse
    {
        $me = $this->currentUser();
        InboxNotification::where('user_id', $me->id)->whereNull('read_at')->update(['read_at' => now()]);

        return $this->ok(['read' => true]);
    }
}
