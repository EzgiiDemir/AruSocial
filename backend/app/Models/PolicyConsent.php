<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record that one person accepted one version of one document.
 *
 * Append-only in practice: accepting the same version twice is a no-op, and
 * a new version is a new row. Nothing updates or deletes these except the
 * cascade when the account itself is erased — a consent record is evidence,
 * and evidence that can be edited is not evidence.
 */
class PolicyConsent extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'document', 'version', 'locale', 'accepted_at'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
