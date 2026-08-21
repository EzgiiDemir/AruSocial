<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id', 'title', 'time', 'end_time', 'event_date', 'place_name', 'category', 'attendees', 'xp',
        'draft', 'publish_at', 'expires_at', 'audience', 'organizer', 'organizer_email', 'description',
        'created_by_user_id', 'workflow_status', 'review_note', 'place_id', 'academic_year_id',
        'student_number', 'phone', 'faculty', 'department', 'estimated_attendees', 'purpose',
        'requirements', 'poster_url', 'assigned_staff_id', 'form_opened_at', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'draft' => 'boolean',
            'publish_at' => 'datetime',
            'expires_at' => 'datetime',
            'event_date' => 'date',
            'form_opened_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function participationTypes()
    {
        return $this->hasMany(EventParticipationType::class);
    }

    public function joins()
    {
        return $this->hasMany(EventJoin::class);
    }

    public function place()
    {
        return $this->belongsTo(Place::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function assignedStaff()
    {
        return $this->belongsTo(AcademicStaff::class, 'assigned_staff_id');
    }
}
