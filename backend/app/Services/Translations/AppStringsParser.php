<?php

namespace App\Services\Translations;

/**
 * Reads the Flutter app's compiled string table.
 *
 * The app ships a complete reviewed copy of every string in
 * `lib/core/l10n/app_strings.dart`, and the panel manages the same keys.
 * Two things need to read that file — the one-off import, and the test that
 * checks the two have not drifted apart — so the parsing lives here rather
 * than being written twice with two slightly different regexes.
 *
 * Parsing Dart source is not elegant, and it is still the right call: the
 * file is the artefact that exists, and adding a build step to the app just
 * to emit JSON for this would be more moving parts than the job needs.
 */
class AppStringsParser
{
    /**
     * Where the app's table lives, relative to the backend.
     */
    public static function defaultPath(): string
    {
        return base_path('../frontend/lib/core/l10n/app_strings.dart');
    }

    /**
     * Every key in the table, with the text for each locale it declares.
     *
     * @return array<string, array<string, string>>
     */
    public static function parse(string $source): array
    {
        // Each entry: 'some_key': { ... } up to the closing brace.
        $pattern = "/'([a-z0-9_]+)'\s*:\s*\{(.*?)\}\s*,/su";

        if (! preg_match_all($pattern, $source, $entries, PREG_SET_ORDER)) {
            return [];
        }

        $out = [];

        foreach ($entries as [$_, $key, $body]) {
            $values = [];

            // AppLanguage.tr: 'text' — single or double quoted, possibly
            // spanning lines after a trailing colon.
            if (preg_match_all(
                "/AppLanguage\.(tr|en|ru)\s*:\s*(?:'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\")/su",
                $body,
                $pairs,
                PREG_SET_ORDER,
            )) {
                foreach ($pairs as $pair) {
                    $locale = $pair[1];
                    $raw = $pair[2] !== '' ? $pair[2] : ($pair[3] ?? '');
                    $values[$locale] = self::unescape($raw);
                }
            }

            if ($values !== []) {
                $out[$key] = $values;
            }
        }

        return $out;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function parseFile(?string $path = null): array
    {
        $path ??= self::defaultPath();

        return is_file($path) ? self::parse((string) file_get_contents($path)) : [];
    }

    private static function unescape(string $value): string
    {
        return str_replace(
            ['\\n', "\\'", '\\"', '\\\\'],
            ["\n", "'", '"', '\\'],
            $value,
        );
    }
}
