<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffProfile extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'name', 'faculty', 'department', 'title', 'email',
        'is_department_head', 'active', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_department_head' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'faculty' => $this->faculty,
            'department' => $this->department,
            'title' => $this->title,
            'email' => $this->email,
            'isDepartmentHead' => (bool) $this->is_department_head,
            'active' => (bool) $this->active,
            'userId' => $this->user_id !== null ? (string) $this->user_id : null,
        ];
    }
}
