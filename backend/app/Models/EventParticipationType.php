<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventParticipationType extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['id', 'event_id', 'label', 'sort_order'];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
