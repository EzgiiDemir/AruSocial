<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Consultation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'title', 'purpose', 'audience', 'content', 'outcomes',
        'duration', 'format', 'requirements', 'counselor_name',
        'counselor_staff_id', 'published',
    ];

    protected function casts(): array
    {
        return ['published' => 'boolean'];
    }

    public function counselor(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'counselor_staff_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ConsultationApplication::class, 'consultation_id');
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'purpose' => $this->purpose,
            'audience' => $this->audience,
            'content' => $this->content,
            'outcomes' => $this->outcomes,
            'duration' => $this->duration,
            'format' => $this->format,
            'requirements' => $this->requirements,
            'counselorName' => $this->counselor_name ?: $this->counselor?->name,
            'counselorStaffId' => $this->counselor_staff_id,
            'published' => (bool) $this->published,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
