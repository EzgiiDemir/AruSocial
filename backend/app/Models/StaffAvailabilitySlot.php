<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffAvailabilitySlot extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'staff_profile_id', 'slot_date', 'start_time', 'end_time', 'is_blocked',
    ];

    protected function casts(): array
    {
        return [
            'slot_date' => 'date',
            'is_blocked' => 'boolean',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }

    public function toApiArray(bool $booked = false): array
    {
        $start = substr((string) $this->start_time, 0, 5);
        $end = substr((string) $this->end_time, 0, 5);
        $date = $this->slot_date?->toDateString();
        $past = false;
        if ($date) {
            try {
                $past = Carbon::parse("{$date} {$start}")->isPast();
            } catch (\Throwable) {
                $past = false;
            }
        }

        $status = 'available';
        if ($this->is_blocked) {
            $status = 'unavailable';
        } elseif ($booked) {
            $status = 'booked';
        } elseif ($past) {
            $status = 'past';
        }

        return [
            'id' => $this->id,
            'staffProfileId' => $this->staff_profile_id,
            'date' => $date,
            'startTime' => $start,
            'endTime' => $end,
            'isBlocked' => (bool) $this->is_blocked,
            'available' => $status === 'available',
            'status' => $status,
        ];
    }
}
