<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['id', 'user_id', 'peer_name', 'from_me', 'text', 'sent_at'];

    protected function casts(): array
    {
        return ['from_me' => 'boolean', 'sent_at' => 'datetime'];
    }
}
