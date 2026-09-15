<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShuttleRoute extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    public const COLOR_KEYS = ['blue', 'yellow', 'success', 'warning', 'campusGreen', 'primary', 'danger'];

    protected $fillable = ['id', 'name', 'color_key', 'stops', 'departures', 'returns', 'sort_order'];

    protected function casts(): array
    {
        return [
            'stops' => 'array',
            'departures' => 'array',
            'returns' => 'array',
            'sort_order' => 'integer',
        ];
    }
}
