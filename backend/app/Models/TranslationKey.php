<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One translatable string, independent of any language.
 *
 * The key is the contract with the app: Flutter calls `t('nav_profile')`
 * and gets whatever this row's published Turkish, English or Russian value
 * currently is. Renaming a key is therefore a breaking change for every
 * installed build, which is why it is not something the panel offers
 * casually.
 */
class TranslationKey extends Model
{
    protected $fillable = ['key', 'description', 'area', 'placeholders', 'is_plural'];

    protected function casts(): array
    {
        return [
            'placeholders' => 'array',
            'is_plural' => 'boolean',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(Translation::class);
    }

    /** The locales this app ships. Turkish is the fallback everywhere. */
    public const LOCALES = ['tr', 'en', 'ru'];

    public const FALLBACK = 'tr';

    /**
     * Languages with no published value yet.
     *
     * This is the "automatically detect missing translations" requirement:
     * a key added for a new screen starts with Turkish only, and this is
     * what surfaces the other two before a student meets an English screen
     * with Turkish words on it.
     *
     * @return list<string>
     */
    public function missingLocales(): array
    {
        $present = $this->translations
            ->filter(fn (Translation $t) => filled($t->published))
            ->pluck('locale')
            ->all();

        return array_values(array_diff(self::LOCALES, $present));
    }

    /** Languages whose draft differs from what students are seeing. */
    public function unpublishedLocales(): array
    {
        return $this->translations
            ->filter(fn (Translation $t) => $t->hasUnpublishedChanges())
            ->pluck('locale')
            ->values()
            ->all();
    }
}
