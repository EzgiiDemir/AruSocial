<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminAuditLog extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $table = 'admin_audit_log';

    protected $fillable = ['id', 'actor_name', 'action', 'target_type', 'target_label', 'at'];

    protected function casts(): array
    {
        return ['at' => 'datetime'];
    }
}
