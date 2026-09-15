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

    protected $fillable = ['id', 'title', 'slug', 'blocks', 'status', 'updated_at', 'updated_by'];

    protected function casts(): array
    {
        return ['blocks' => 'array', 'updated_at' => 'datetime'];
    }
}
