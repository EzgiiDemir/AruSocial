<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModerationReport extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['id', 'kind', 'target_id', 'target_label', 'reason', 'reported_at', 'action'];

    protected function casts(): array
    {
        return ['reported_at' => 'datetime'];
    }
}
