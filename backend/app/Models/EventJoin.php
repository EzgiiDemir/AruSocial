<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventJoin extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id', 'event_id', 'user_id', 'participation_type_id', 'joined_at', 'form_submitted_at', 'approved_at', 'approved_by',
    ];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'form_submitted_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function participationType()
    {
        return $this->belongsTo(EventParticipationType::class, 'participation_type_id');
    }
}
