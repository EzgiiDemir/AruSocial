<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CareerProfile extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'headline', 'expertise', 'cv_url',
        'cv_path', 'cv_original_name', 'cv_mime', 'cv_size_bytes',
        'looking_for_internships', 'looking_for_jobs', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'looking_for_internships' => 'boolean',
            'looking_for_jobs' => 'boolean',
            'updated_at' => 'datetime',
        ];
    }
}
