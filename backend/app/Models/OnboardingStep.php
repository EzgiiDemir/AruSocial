<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OnboardingStep extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    public const ACTION_KINDS = ['service', 'list', 'info'];

    protected $fillable = [
        'id', 'group_label', 'title', 'detail', 'action_kind', 'ref_id', 'sort_order', 'active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }
}
