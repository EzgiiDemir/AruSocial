<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerApplication extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public const STATUSES = ['pending', 'reviewed', 'shortlisted', 'rejected', 'accepted'];

    public const OPEN_STATUSES = ['pending', 'reviewed', 'shortlisted'];

    protected $fillable = [
        'id', 'user_id', 'opportunity_id', 'cv_path', 'cv_original_name',
        'cv_mime', 'status', 'admin_notes',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(CareerOpportunity::class, 'opportunity_id');
    }

    public function toApiArray(bool $includeAdmin = false): array
    {
        $payload = [
            'id' => $this->id,
            'userId' => (string) $this->user_id,
            'userName' => $this->user?->name,
            'userEmail' => $includeAdmin ? $this->user?->email : null,
            'opportunityId' => $this->opportunity_id,
            'opportunityTitle' => $this->opportunity?->title,
            'hasCv' => filled($this->cv_path),
            'cvFileName' => $this->cv_original_name,
            'status' => $this->status,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
        if ($includeAdmin) {
            $payload['adminNotes'] = $this->admin_notes;
        }

        return $payload;
    }
}
