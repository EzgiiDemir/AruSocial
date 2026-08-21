<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id', 'title', 'time', 'event_date', 'place_name', 'category', 'attendees', 'xp',
        'draft', 'publish_at', 'expires_at', 'audience', 'organizer', 'organizer_email', 'description',
        'created_by_user_id', 'workflow_status', 'review_note', 'place_id', 'academic_year_id',
    ];

    protected function casts(): array
    {
        return [
            'draft' => 'boolean',
            'publish_at' => 'datetime',
            'expires_at' => 'datetime',
            'event_date' => 'date',
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
}
