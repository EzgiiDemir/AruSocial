<?php

namespace App\Models;

use App\Services\AuditLogger;
use App\Services\Translations\TranslationCatalogue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One string in one language, in two states at once.
 *
 * `draft` is what a translator is working on; `published` is what the app
 * serves. They are separate columns rather than a status flag because both
 * values have to exist simultaneously — the whole point of "preview and
 * publish" is that somebody can rewrite a string while students keep seeing
 * the old one.
 */
class Translation extends Model
{
    protected $fillable = [
        'translation_key_id', 'locale', 'draft', 'published',
        'published_at', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function translationKey(): BelongsTo
    {
        return $this->belongsTo(TranslationKey::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(TranslationRevision::class, 'translation_key_id', 'translation_key_id')
            ->where('locale', $this->locale)
            ->latest('created_at');
    }

    public function hasUnpublishedChanges(): bool
    {
        return filled($this->draft) && $this->draft !== $this->published;
    }

    /**
     * Make the draft live.
     *
     * Everything that must happen together happens here rather than in the
     * panel: the revision is written, the cache the app reads is dropped,
     * and the action is audited. A publish that updated the column but
     * forgot the cache would look successful and change nothing for days.
     */
    public function publish(?string $actor = null): void
    {
        if (blank($this->draft)) {
            return;
        }

        $this->forceFill([
            'published' => $this->draft,
            'published_at' => now(),
            'updated_by' => $actor,
        ])->save();

        TranslationRevision::create([
            'translation_key_id' => $this->translation_key_id,
            'locale' => $this->locale,
            'value' => $this->published,
            'actor' => $actor,
            'created_at' => now(),
        ]);

        TranslationCatalogue::forget();

        AuditLogger::log('system', 'translation_publish', 'translation', sprintf(
            '%s [%s] published by %s',
            $this->translationKey?->key ?? $this->translation_key_id,
            $this->locale,
            $actor ?? 'system',
        ));
    }

    /**
     * Put a previous published value back.
     *
     * Rollback republishes rather than rewriting history: the revision log
     * gains an entry showing the restore happened. An audit trail you can
     * quietly edit is not an audit trail.
     */
    public function rollbackTo(TranslationRevision $revision, ?string $actor = null): void
    {
        $this->forceFill(['draft' => $revision->value])->save();
        $this->publish($actor);
    }
}
