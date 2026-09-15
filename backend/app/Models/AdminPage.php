<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdminPage extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id', 'title', 'slug', 'translations', 'blocks', 'status',
        'publish_at', 'expires_at', 'audiences', 'updated_at', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'translations' => 'array',
            'blocks' => 'array',
            'audiences' => 'array',
            'publish_at' => 'datetime',
            'expires_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
