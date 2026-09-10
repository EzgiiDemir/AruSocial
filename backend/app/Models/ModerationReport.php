<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModerationReport extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id', 'kind', 'target_id', 'target_label', 'reason', 'reported_at', 'action',
        // Added with the workflow tables. `kind`/`reason` are kept in step
        // with these so the existing admin screens keep working while
        // they migrate to the normalized columns.
        'reporter_user_id', 'target_type', 'reason_code', 'description',
        'status', 'moderation_case_id',
    ];

    protected function casts(): array
    {
        return ['reported_at' => 'datetime'];
    }

    public function reporter(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }
}
