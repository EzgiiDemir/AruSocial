<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CareerOpportunity extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id', 'title', 'kind', 'organization', 'department', 'url', 'deadline',
        'description', 'purpose', 'skills', 'experience', 'education',
        'work_type', 'location', 'posted_at', 'extra_info',
        'published', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'deadline' => 'date',
            'posted_at' => 'date',
            'published' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
