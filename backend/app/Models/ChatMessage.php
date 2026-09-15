<?php

namespace App\Models;

use App\Models\Concerns\HasPublicModerationScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    use HasPublicModerationScope;

    protected $table = 'messages';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'conversation_id', 'sender_id', 'body', 'moderation_status'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function toApiArray(User $viewer, User $peer): array
    {
        return [
            'id' => $this->id,
            'fromMe' => (int) $this->sender_id === (int) $viewer->id,
            'text' => $this->body,
            'sentAt' => $this->created_at?->toIso8601String(),
            'sender' => $this->sender?->name ?? '',
            'peer' => $peer->name,
            'conversationId' => (int) $this->conversation_id,
        ];
    }
}
