<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Checkin extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['id', 'place_id', 'user_id', 'visible_to_others', 'created_at'];

    protected function casts(): array
    {
        return [
            'visible_to_others' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
