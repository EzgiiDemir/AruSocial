<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Draft extends Model
{
    protected $primaryKey = 'content_key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['content_key', 'blocks', 'updated_at'];

    protected function casts(): array
    {
        return ['blocks' => 'array', 'updated_at' => 'datetime'];
    }
}
