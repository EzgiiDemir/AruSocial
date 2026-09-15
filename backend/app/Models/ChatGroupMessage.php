<?php

namespace App\Models;

use App\Models\Concerns\HasPublicModerationScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatGroupMessage extends Model
{
    use HasPublicModerationScope;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['id', 'group_id', 'sender_user_id', 'text', 'created_at', 'moderation_status'];

    protected function casts(): array
    {
        return [
            'sender_user_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ChatGroup::class, 'group_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'groupId' => $this->group_id,
            'senderId' => (string) $this->sender_user_id,
            'senderName' => $this->sender?->name,
            'text' => $this->text,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
