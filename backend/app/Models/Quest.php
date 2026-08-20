<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quest extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['id', 'user_id', 'title', 'subtitle', 'progress', 'target', 'reward'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
