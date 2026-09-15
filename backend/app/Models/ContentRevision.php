<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentRevision extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['id', 'content_key', 'editor_name', 'snapshot', 'saved_at'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'saved_at' => 'datetime'];
    }
}
