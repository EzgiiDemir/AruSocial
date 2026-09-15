<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// Admin-managed, per-category question schema for the two-stage apply
// flow — see docs/API_CONTRACT.md's Applications section. Never hardcode
// a category's questions in Dart/PHP; always read them from here so a
// staff member can add/reorder/retire a question without a deploy.
class ApplicationQuestion extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'target_type', 'stage', 'type', 'label', 'help_text',
        'options', 'required', 'sort_order', 'active',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'required' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'targetType' => $this->target_type,
            'stage' => $this->stage,
            'type' => $this->type,
            'label' => $this->label,
            'helpText' => $this->help_text,
            'options' => $this->options ?? [],
            'required' => $this->required,
            'sortOrder' => $this->sort_order,
            'active' => $this->active,
        ];
    }
}
