<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialBlock extends Model
{
    public $timestamps = false;
    protected $fillable = ['blocker_user_id', 'blocked_name', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
