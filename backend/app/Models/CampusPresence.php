<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per user: the place the server resolved their most recent
 * location ping to. Overwritten on every ping and never appended to, so
 * this is a live signal, not a movement history — see the table migration.
 */
class CampusPresence extends Model
{
    protected $table = 'campus_presences';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['user_id', 'place_id', 'updated_at'];

    protected function casts(): array
    {
        return [
            'updated_at' => 'datetime',
        ];
    }

    public function place()
    {
        return $this->belongsTo(Place::class);
    }
}
