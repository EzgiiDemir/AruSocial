<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Notification as InboxNotification;
use App\Models\User;
use App\Services\RealtimePublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// Real, shared per-peer threads (backend-side ChatStore). Still poll-based,
// not a live socket connection — see docs/EKSIKLER.md for why real-time
// delivery is still a gap.
//
// Real bug fix (docs/EKSIKLER.md sosyal/chat): messages used to be scoped
// only to the *sender's* own copy (`user_id = sender`), so a real second
// account messaging back never actually reached the other side — each
// account only ever saw its own outgoing half of the "conversation". Now
// send() also mirrors a real row into the peer's own thread when `peer`
// resolves to an actual second account (matched by name, the same way
// the rest of the social graph — SocialGraphController — already
// identifies peers), so two real signed-in accounts genuinely see the
// same conversation.
class ChatController extends Controller
{
    use ApiResponds;

    public function threads(): JsonResponse
    {
        $me = $this->currentUser();
        $peers = ChatMessage::where('user_id', $me->id)->distinct()->pluck('peer_name');

        // Real conversation-list data (docs/EKSIKLER.md sosyal/chat):
        // last message, when it was sent, and a real unread count — not
        // just a bare list of names.
        $threads = $peers->map(function ($peer) use ($me) {
            // Tie-break on id, not just sent_at: the DB timestamp column is
            // only second-precision, so two messages sent within the same
            // second would otherwise sort arbitrarily. Message ids are
            // orderedUuid()s (time-sortable), so this reliably picks the
            // truly last message.
            $last = ChatMessage::where('user_id', $me->id)->where('peer_name', $peer)
                ->orderByDesc('sent_at')->orderByDesc('id')->first();
            $unread = ChatMessage::where('user_id', $me->id)->where('peer_name', $peer)
                ->where('from_me', false)->whereNull('read_at')->count();

            return [
                'peerName' => $peer,
                'lastMessage' => $last?->text,
                'lastMessageAt' => $last?->sent_at?->toIso8601String(),
                'unreadCount' => $unread,
            ];
        })->sortByDesc('lastMessageAt')->values();

        return $this->ok($threads);
    }

    public function messages(string $peer): JsonResponse
    {
        $me = $this->currentUser();
        $rows = ChatMessage::where('user_id', $me->id)->where('peer_name', $peer)->orderBy('sent_at')->get();

        // Opening the thread is what actually marks it read — a real
        // action, not a client-side guess.
        ChatMessage::where('user_id', $me->id)->where('peer_name', $peer)
            ->where('from_me', false)->whereNull('read_at')->update(['read_at' => now()]);

        return $this->ok($rows->map(fn ($m) => [
            'id' => $m->id,
            'fromMe' => $m->from_me,
            'text' => $m->text,
            'sentAt' => $m->sent_at?->toIso8601String(),
        ]));
    }

    public function send(Request $request, string $peer): JsonResponse
    {
        $me = $this->currentUser();
        $text = (string) $request->input('text', '');
        if (trim($text) === '') return $this->fail(400, 'VALIDATION', 'text is required.');

        $sentAt = now();
        $message = ChatMessage::create([
            'id' => 'msg-'.Str::orderedUuid(),
            'user_id' => $me->id,
            'peer_name' => $peer,
            'from_me' => true,
            'text' => $text,
            'sent_at' => $sentAt,
            'read_at' => $sentAt,
        ]);

        // Real bidirectional delivery — see class doc comment.
        $peerUser = User::where('name', $peer)->first();
        if ($peerUser && $peerUser->id !== $me->id) {
            ChatMessage::create([
                'id' => 'msg-'.Str::orderedUuid(),
                'user_id' => $peerUser->id,
                'peer_name' => $me->name,
                'from_me' => false,
                'text' => $text,
                'sent_at' => $sentAt,
            ]);
            InboxNotification::create([
                'id' => 'notif-'.Str::uuid(),
                'user_id' => $peerUser->id,
                'kind' => 'message',
                'title' => "Yeni mesaj: {$me->name}",
                'body' => $text,
                'created_at' => $sentAt,
            ]);
            RealtimePublisher::toUser((string) $peerUser->id, 'message.created', 'message', $message->id, (string) $me->id);
            RealtimePublisher::toUser((string) $peerUser->id, 'notification.created', 'notification', null, (string) $me->id);
        }

        return $this->ok([
            'id' => $message->id,
            'fromMe' => true,
            'text' => $message->text,
            'sentAt' => $message->sent_at->toIso8601String(),
        ]);
    }
}
