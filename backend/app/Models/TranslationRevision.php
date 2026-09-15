<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every value this string has ever been published as.
 *
 * Append-only by intent: there is no update path and the panel offers no
 * edit. Rollback works by republishing one of these, which writes another
 * row — so the log shows what happened rather than being rewritten to look
 * like it never did.
 */
class TranslationRevision extends Model
{
    public $timestamps = false;

    protected $fillable = ['translation_key_id', 'locale', 'value', 'actor', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function translationKey(): BelongsTo
    {
        return $this->belongsTo(TranslationKey::class);
    }
}
