<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AskArucadLog extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['id', 'user_id', 'question', 'category', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
