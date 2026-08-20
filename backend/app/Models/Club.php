<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Club extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['id', 'name', 'category', 'description', 'body'];

    protected function casts(): array
    {
        return ['body' => 'array'];
    }
}
