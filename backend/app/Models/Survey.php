<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Survey extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id', 'question', 'description', 'starts_at', 'ends_at',
        'target_audience', 'multiple_choice', 'anonymous', 'show_results',
        'active', 'created_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'multiple_choice' => 'boolean',
            'anonymous' => 'boolean',
            'show_results' => 'boolean',
            'active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function options()
    {
        return $this->hasMany(SurveyOption::class)->orderBy('sort_order');
    }

    public function responses()
    {
        return $this->hasMany(SurveyResponse::class);
    }
}
