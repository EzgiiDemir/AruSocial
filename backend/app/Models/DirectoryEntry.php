<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DirectoryEntry extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id', 'building', 'floor', 'room', 'occupant_name', 'occupant_role',
        'related_service_id', 'tour_url', 'tour_target',
        'campus_id', 'campus_name', 'building_id', 'category_id',
        'category_name', 'room_number', 'notes', 'splat_scene_id',
        'splat_scene_url', 'location', 'navigation_marker',
        'directory_synced_at',
    ];

    protected $casts = [
        'location' => 'array',
        'navigation_marker' => 'array',
        'directory_synced_at' => 'datetime',
    ];
}
