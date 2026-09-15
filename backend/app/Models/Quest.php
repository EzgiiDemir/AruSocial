<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quest extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['id', 'user_id', 'title', 'subtitle', 'progress', 'target', 'reward', 'kind'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
