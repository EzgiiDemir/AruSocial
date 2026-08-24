<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageCreated;
use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendChatMessageRequest;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Notification as InboxNotification;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Shared 1:1 threads: one conversation per unordered user pair, one
// message row per send, sender_id = the authenticated user. Display names
// are resolved at the edge and never stored as the relation. REST remains
// the history source of truth; MessageCreated is realtime delivery only.
class ChatController extends Controller
{
    use ApiResponds;

    public function __construct(private ConversationService $conversations) {}

    public function threads(): JsonResponse
    {
        $me = $this->currentUser();
        $names = Conversation::query()
            ->whereHas('participants', fn ($q) => $q->where('users.id', $me->id))
            ->with('participants')
            ->get()
            ->map(function (Conversation $conversation) use ($me) {
                return $conversation->otherParticipant($me->id)?->name;
            })
            ->filter()
            ->values();

        return $this->ok($names);
    }

    public function messages(string $peer): JsonResponse
    {
        $me = $this->currentUser();
        $target = $this->peerOrFail($peer);
        if ($target instanceof JsonResponse) {
            return $target;
        }
        if ($target->id === $me->id) {
            return $this->fail(400, 'VALIDATION', 'You cannot open a chat with yourself.');
        }

        $conversation = $this->conversations->findPair($me, $target);
        if ($conversation === null) {
            return $this->ok([]);
        }
        if (! $conversation->hasParticipant($me->id)) {
            return $this->fail(403, 'FORBIDDEN', 'You are not a participant in this conversation.');
        }

        $rows = $conversation->messages()->with('sender')->orderBy('created_at')->orderBy('id')->get();

        return $this->ok($rows->map(fn (ChatMessage $m) => $m->toApiArray($me, $target))->values());
    }

    public function send(SendChatMessageRequest $request, string $peer): JsonResponse
    {
        $me = $this->currentUser();
        $target = $this->peerOrFail($peer);
        if ($target instanceof JsonResponse) {
            return $target;
        }
        if ($target->id === $me->id) {
            return $this->fail(400, 'VALIDATION', 'You cannot message yourself.');
        }

        $text = (string) $request->input('text');

        $message = DB::transaction(function () use ($me, $target, $text) {
            $conversation = $this->conversations->findOrCreatePair($me, $target);
            $created = ChatMessage::create([
                'id' => 'msg-'.Str::uuid(),
                'conversation_id' => $conversation->id,
                'sender_id' => $me->id,
                'body' => $text,
            ]);
            $created->setRelation('sender', $me);

            return $created;
        });

        InboxNotification::notify(
            $target,
            $me,
            'message',
            'Yeni mesaj',
            "{$me->name} sana bir mesaj gönderdi.",
            [
                'conversationId' => (string) $message->conversation_id,
                'messageId' => $message->id,
                'peer' => $me->name,
            ],
        );

        try {
            broadcast(new MessageCreated($message, $me, $target));
        } catch (\Throwable) {
            // Reverb down must not fail REST send; history is the source of truth.
        }

        return $this->ok($message->toApiArray($me, $target));
    }

    private function peerOrFail(string $peer): User|JsonResponse
    {
        $resolved = $this->conversations->resolvePeer(urldecode($peer));
        if ($resolved === 'missing') {
            return $this->fail(404, 'USER_NOT_FOUND', 'Target user not found.');
        }
        if ($resolved === 'ambiguous') {
            return $this->fail(400, 'VALIDATION', 'peer is ambiguous.');
        }

        return $resolved;
    }
}
