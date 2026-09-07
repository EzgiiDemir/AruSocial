<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WordpressFormVersion extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'source_url', 'content_hash', 'version', 'payload', 'saved_by',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
