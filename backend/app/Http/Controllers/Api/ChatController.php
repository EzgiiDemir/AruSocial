<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Real, shared per-peer threads (backend-side ChatStore) — genuinely
// readable from any client hitting this backend, unlike the on-device
// version. Still poll-based, not a live socket connection — see
// docs/EKSIKLER.md §4 for why real-time delivery is still a gap.
class ChatController extends Controller
{
    use ApiResponds;

    public function threads(): JsonResponse
    {
        $me = $this->currentUser();
        $peers = ChatMessage::where('user_id', $me->id)->distinct()->pluck('peer_name');

        return $this->ok($peers);
    }

    public function messages(string $peer): JsonResponse
    {
        $me = $this->currentUser();
        $rows = ChatMessage::where('user_id', $me->id)->where('peer_name', $peer)->orderBy('sent_at')->get();

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

        $message = ChatMessage::create([
            'id' => 'msg-'.\Illuminate\Support\Str::uuid(),
            'user_id' => $me->id,
            'peer_name' => $peer,
            'from_me' => true,
            'text' => $text,
            'sent_at' => now(),
        ]);

        return $this->ok([
            'id' => $message->id,
            'fromMe' => true,
            'text' => $message->text,
            'sentAt' => $message->sent_at->toIso8601String(),
        ]);
    }
}
