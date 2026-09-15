<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $table = 'activity_log';

    protected $fillable = ['id', 'user_id', 'kind', 'title', 'subtitle', 'meta', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
