<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationApplication extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public const STATUSES = ['pending', 'reviewed', 'shortlisted', 'rejected', 'accepted'];

    public const OPEN_STATUSES = ['pending', 'reviewed', 'shortlisted'];

    protected $fillable = [
        'id', 'user_id', 'consultation_id', 'status', 'notes', 'admin_notes',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class, 'consultation_id');
    }

    public function toApiArray(bool $includeAdmin = false): array
    {
        $payload = [
            'id' => $this->id,
            'userId' => (string) $this->user_id,
            'userName' => $this->user?->name,
            'userEmail' => $includeAdmin ? $this->user?->email : null,
            'consultationId' => $this->consultation_id,
            'consultationTitle' => $this->consultation?->title,
            'status' => $this->status,
            'notes' => $this->notes,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
        if ($includeAdmin) {
            $payload['adminNotes'] = $this->admin_notes;
        }

        return $payload;
    }
}
