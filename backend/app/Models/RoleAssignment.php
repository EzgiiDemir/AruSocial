<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoleAssignment extends Model
{
    protected $primaryKey = 'email';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['email', 'role', 'permissions', 'assigned_by', 'assigned_at'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'permissions' => 'array'];
    }
}
