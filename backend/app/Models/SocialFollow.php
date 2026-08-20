<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialFollow extends Model
{
    public $timestamps = false;
    protected $fillable = ['follower_user_id', 'followed_name', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
