<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushToken extends Model
{
    public $timestamps = false;
    protected $fillable = ['user_id', 'token', 'platform', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $token): void {
            $token->created_at ??= now();
        });
    }
}
