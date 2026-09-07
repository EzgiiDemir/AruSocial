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
        'created_by_user_id', 'responsible_staff_id', 'workflow_status', 'review_note', 'place_id', 'academic_year_id',
        'ai_draft', 'ai_source_media_id',
    ];

    protected function casts(): array
    {
        return [
            'draft' => 'boolean',
            'ai_draft' => 'boolean',
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

    public function responsibleStaff()
    {
        return $this->belongsTo(StaffProfile::class, 'responsible_staff_id');
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** Live campus calendar: not a draft, published, inside its publish window. */
    public function isPubliclyListed(): bool
    {
        if ($this->draft || $this->workflow_status !== 'published') {
            return false;
        }
        if ($this->publish_at && $this->publish_at->isFuture()) {
            return false;
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function scopePubliclyListed($query)
    {
        return $query
            ->where('draft', false)
            ->where('workflow_status', 'published')
            ->where(function ($q) {
                $q->whereNull('publish_at')->orWhere('publish_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }
}
