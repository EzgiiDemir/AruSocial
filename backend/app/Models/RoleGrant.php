<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleGrant extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'role', 'is_primary', 'scope_type', 'scope_id',
        'permissions', 'denied_permissions', 'can_publish', 'can_export',
        'sensitive_data_access', 'starts_at', 'expires_at', 'assigned_by', 'status',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean', 'permissions' => 'array', 'denied_permissions' => 'array',
            'can_publish' => 'boolean', 'can_export' => 'boolean', 'sensitive_data_access' => 'boolean',
            'starts_at' => 'datetime', 'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::saving(function (RoleGrant $grant): void {
            if ($grant->is_primary && filled($grant->user_id)) {
                static::query()->where('user_id', $grant->user_id)
                    ->when($grant->exists, fn (Builder $query) => $query->whereKeyNot($grant->getKey()))
                    ->update(['is_primary' => false]);
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
