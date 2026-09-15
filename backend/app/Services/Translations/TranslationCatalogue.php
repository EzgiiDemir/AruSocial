<?php

namespace App\Services\Translations;

use App\Models\Translation;
use App\Models\TranslationKey;
use Illuminate\Support\Facades\Cache;

/**
 * The published strings, assembled once and cached.
 *
 * Every app launch asks for a whole language. Reading 594 rows per request
 * is work the database should do about once, not thousands of times a
 * morning, so the assembled map is cached and dropped whenever anything is
 * published (see Translation::publish).
 *
 * The cache key carries a version so an invalidation cannot half-apply: a
 * new version is a different key, and the old one expires on its own rather
 * than being deleted from every cache node at the same instant.
 */
class TranslationCatalogue
{
    private const VERSION_KEY = 'translations.version';

    private const TTL_SECONDS = 3600;

    /**
     * A monotonic stamp that changes whenever anything is published.
     *
     * The app sends it back as an ETag; when it matches, the server answers
     * 304 and the phone downloads nothing. That is what makes checking for
     * updates on every launch cheap enough to actually do.
     */
    public static function version(): string
    {
        return (string) Cache::rememberForever(
            self::VERSION_KEY,
            fn () => (string) now()->getTimestampMs(),
        );
    }

    /** Drop the cached maps and mint a new version. */
    public static function forget(): void
    {
        foreach (TranslationKey::LOCALES as $locale) {
            Cache::forget(self::cacheKey($locale));
        }

        Cache::forever(self::VERSION_KEY, (string) now()->getTimestampMs());
    }

    private static function cacheKey(string $locale): string
    {
        return 'translations.map.'.$locale;
    }

    /**
     * Every published string for one language, falling back to Turkish.
     *
     * The fallback is applied here rather than on the phone so that an app
     * missing one Russian string shows the Turkish one — never the raw key.
     * A screen reading `sp_private` to a student is worse than a screen in
     * the wrong language.
     *
     * @return array<string, string>
     */
    public static function for(string $locale): array
    {
        if (! in_array($locale, TranslationKey::LOCALES, true)) {
            $locale = TranslationKey::FALLBACK;
        }

        return Cache::remember(
            self::cacheKey($locale),
            self::TTL_SECONDS,
            function () use ($locale): array {
                $fallback = self::published(TranslationKey::FALLBACK);

                return $locale === TranslationKey::FALLBACK
                    ? $fallback
                    : array_merge($fallback, self::published($locale));
            },
        );
    }

    /**
     * Published values only — a draft must never reach a phone.
     *
     * @return array<string, string>
     */
    private static function published(string $locale): array
    {
        return Translation::query()
            ->where('locale', $locale)
            ->whereNotNull('published')
            ->join('translation_keys', 'translation_keys.id', '=', 'translations.translation_key_id')
            ->pluck('translations.published', 'translation_keys.key')
            ->all();
    }

    /**
     * Keys with no published value in a language.
     *
     * Drives the missing-translation warning in the panel. Computed from the
     * database rather than the cache, because a panel that reports on a
     * cached copy would tell a translator their work is still missing for up
     * to an hour after they published it.
     *
     * @return array<string, list<string>> locale => missing keys
     */
    public static function missing(): array
    {
        $all = TranslationKey::pluck('key', 'id');
        $out = [];

        foreach (TranslationKey::LOCALES as $locale) {
            $have = Translation::query()
                ->where('locale', $locale)
                ->whereNotNull('published')
                ->pluck('translation_key_id')
                ->all();

            $out[$locale] = array_values($all->except($have)->all());
        }

        return $out;
    }
}
