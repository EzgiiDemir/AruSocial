<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationStatusEvent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id', 'application_id', 'from_status', 'to_status',
        'note', 'actor_user_id', 'actor_label', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ParticipationApplication::class, 'application_id');
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'fromStatus' => $this->from_status,
            'toStatus' => $this->to_status,
            'note' => $this->note,
            'actorLabel' => $this->actor_label,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
