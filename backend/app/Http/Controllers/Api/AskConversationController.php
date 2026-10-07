<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaginatedListRequest;
use App\Models\AskConversation;
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
        $query = AskConversation::where('user_id', $me->id)
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

    /**
     * Deleting a conversation that is already gone is not a failure.
     *
     * This returned 404, and the app showed the student a network error for
     * an outcome that was exactly what they asked for: the conversation is
     * not there. It happens on an ordinary double tap, on a retry after a
     * slow first request, and when the list on screen is older than the
     * server — none of which is the student doing anything wrong.
     *
     * Safe as well as kinder. The response is identical whether the id never
     * existed, was already deleted, or belongs to somebody else, so nothing
     * is revealed about other people's conversations; `ownedBy` still scopes
     * the actual delete to the authenticated user.
     *
     * `show` keeps its 404: there is no sensible way to return a
     * conversation that is not there, while "it is gone" is a complete
     * answer to "delete it".
     */
    public function destroy(string $id, AskConversationService $ask): JsonResponse
    {
        $conversation = $ask->ownedBy($this->currentUser(), $id);
        $conversation?->delete();

        return $this->ok(['deleted' => true]);
    }
}
