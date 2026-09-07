<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AchievementDefinition extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id', 'title', 'subtitle', 'trigger_kind', 'threshold', 'sort_order', 'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
