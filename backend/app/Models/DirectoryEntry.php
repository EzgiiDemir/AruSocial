<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DirectoryEntry extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id', 'building', 'floor', 'room', 'occupant_name', 'occupant_role',
        'related_service_id',
    ];
}
