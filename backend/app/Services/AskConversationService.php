<?php

namespace App\Services;

use App\Models\AskConversation;
use App\Models\AskMessage;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AskConversationService
{
    public function isAvailable(): bool
    {
        return Schema::hasTable('ask_conversations') && Schema::hasTable('ask_messages');
    }

    public function listFor(User $user): \Illuminate\Support\Collection
    {
        if (! $this->isAvailable()) {
            return collect();
        }

        return AskConversation::where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->get();
    }

    public function ownedBy(User $user, string $id): ?AskConversation
    {
        if (! $this->isAvailable()) {
            return null;
        }

        return AskConversation::where('user_id', $user->id)->where('id', $id)->first();
    }

    public function appendTurn(User $user, ?string $conversationId, string $userText, string $assistantText): ?AskConversation
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $conversation = null;
        if ($conversationId) {
            $conversation = $this->ownedBy($user, $conversationId);
        }
        if (! $conversation) {
            $title = mb_substr(trim($userText), 0, 80);
            if ($title === '') {
                $title = 'Ask ARUCAD';
            }
            $conversation = AskConversation::create([
                'id' => 'ask-'.Str::uuid(),
                'user_id' => $user->id,
                'title' => $title,
            ]);
        }

        AskMessage::create([
            'id' => 'askm-'.Str::uuid(),
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userText,
            'created_at' => now(),
        ]);
        AskMessage::create([
            'id' => 'askm-'.Str::uuid(),
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $assistantText,
            'created_at' => now(),
        ]);
        $conversation->touch();

        return $conversation->fresh();
    }

    public function toJson(AskConversation $conversation, bool $withMessages = false): array
    {
        $payload = [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'updatedAt' => $conversation->updated_at?->toIso8601String(),
        ];
        if ($withMessages) {
            $payload['messages'] = $conversation->messages->map(fn (AskMessage $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'fromUser' => $m->role === 'user',
                'text' => $m->content,
                'at' => $m->created_at?->toIso8601String(),
            ])->values();
        }

        return $payload;
    }
}
