<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled', 'completed', 'booked'];
    public const ACTIVE_STATUSES = ['pending', 'approved', 'booked'];

    protected $fillable = [
        'id', 'staff_profile_id', 'student_user_id', 'slot_date',
        'start_time', 'end_time', 'application_id', 'status',
        'subject', 'notes', 'admin_notes',
    ];

    protected function casts(): array
    {
        return [
            'slot_date' => 'date',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    public static function occupiesSlot(string $status): bool
    {
        return in_array($status, self::ACTIVE_STATUSES, true);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'staffProfileId' => $this->staff_profile_id,
            'staffName' => $this->staff?->name,
            'staffDepartment' => $this->staff?->department,
            'studentUserId' => (string) $this->student_user_id,
            'studentName' => $this->student?->name,
            'date' => $this->slot_date?->toDateString(),
            'startTime' => substr((string) $this->start_time, 0, 5),
            'endTime' => substr((string) $this->end_time, 0, 5),
            'applicationId' => $this->application_id,
            'status' => $this->status === 'booked' ? 'approved' : $this->status,
            'subject' => $this->subject,
            'notes' => $this->notes,
            'adminNotes' => $this->admin_notes,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
