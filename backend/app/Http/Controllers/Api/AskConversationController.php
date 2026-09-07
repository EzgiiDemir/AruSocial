<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaginatedListRequest;
use App\Services\AskConversationService;
use Illuminate\Http\JsonResponse;

class AskConversationController extends Controller
{
    use ApiResponds;

    public function index(PaginatedListRequest $request, AskConversationService $ask): JsonResponse
    {
        if (! $ask->isAvailable()) {
            $perPage = (int) $request->input('perPage', 20);

            return $this->ok([], 200, [
                'currentPage' => 1,
                'perPage' => max(1, min(50, $perPage)),
                'total' => 0,
                'lastPage' => 1,
            ]);
        }

        $me = $this->currentUser();
        $query = \App\Models\AskConversation::where('user_id', $me->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        return $this->okPage($query, $request, fn ($c) => $ask->toJson($c));
    }

    public function show(string $id, AskConversationService $ask): JsonResponse
    {
        $conversation = $ask->ownedBy($this->currentUser(), $id);
        if (! $conversation) {
            return $this->fail(404, 'ASK_CONVERSATION_NOT_FOUND', 'Conversation not found.');
        }

        return $this->ok($ask->toJson($conversation->load('messages'), true));
    }

    public function destroy(string $id, AskConversationService $ask): JsonResponse
    {
        $conversation = $ask->ownedBy($this->currentUser(), $id);
        if (! $conversation) {
            return $this->fail(404, 'ASK_CONVERSATION_NOT_FOUND', 'Conversation not found.');
        }
        $conversation->delete();

        return $this->ok(['deleted' => true]);
    }
}
