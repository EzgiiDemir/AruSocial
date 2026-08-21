<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademicStaff extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'name', 'email', 'email_verified', 'type', 'faculty', 'department',
        'title', 'is_department_head', 'is_faculty_dean',
    ];

    protected function casts(): array
    {
        return [
            'email_verified' => 'boolean',
            'is_department_head' => 'boolean',
            'is_faculty_dean' => 'boolean',
        ];
    }
}
