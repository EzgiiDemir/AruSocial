<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Named to match the real in-app notification inbox concept — not to be
// confused with Illuminate\Notifications\Notification (Laravel's queued
// mail/SMS/push base class, a different namespace entirely). Always
// import this one explicitly as App\Models\Notification.
class Notification extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['id', 'user_id', 'kind', 'title', 'body', 'read_at', 'created_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
