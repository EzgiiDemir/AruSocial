<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminPage extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['id', 'title', 'slug', 'blocks', 'status', 'updated_at', 'updated_by'];

    protected function casts(): array
    {
        return ['blocks' => 'array', 'updated_at' => 'datetime'];
    }
}
