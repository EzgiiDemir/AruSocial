<?php

namespace App\Console\Commands;

use App\Models\Translation;
use App\Models\TranslationKey;
use App\Services\Translations\AppStringsParser;
use App\Services\Translations\TranslationCatalogue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the translation tables from the app's compiled string table.
 *
 * `app_strings.dart` holds ~594 keys in a nested Dart map. They are the
 * real, reviewed wording this app ships with, so the database starts as a
 * copy of them rather than empty — otherwise the first publish would have
 * to retype the entire app.
 *
 * Idempotent: a key that already exists keeps its published value and is
 * not overwritten. Running this twice is safe, and running it after adding
 * keys to the Dart file imports only the new ones.
 */
class ImportTranslationsFromApp extends Command
{
    protected $signature = 'translations:import
                            {--path= : app_strings.dart (defaults to the sibling frontend)}
                            {--publish : Publish imported values immediately}
                            {--dry-run : Report what would be imported}';

    protected $description = 'Import the app’s compiled strings into the translation tables';

    public function handle(): int
    {
        $path = $this->option('path') ?: AppStringsParser::defaultPath();

        if (! is_file($path)) {
            $this->error("Not found: {$path}");

            return self::FAILURE;
        }

        $parsed = AppStringsParser::parse((string) file_get_contents($path));

        if ($parsed === []) {
            $this->error('No keys parsed — the Dart table format may have changed.');

            return self::FAILURE;
        }

        $this->line(sprintf('Parsed %d keys.', count($parsed)));

        if ($this->option('dry-run')) {
            foreach (array_slice($parsed, 0, 5, true) as $key => $row) {
                $this->line('  '.$key.'  '.json_encode($row, JSON_UNESCAPED_UNICODE));
            }
            $this->warn('Dry run. Re-run without --dry-run to write.');

            return self::SUCCESS;
        }

        $created = 0;
        $filled = 0;

        DB::transaction(function () use ($parsed, &$created, &$filled) {
            foreach ($parsed as $key => $byLocale) {
                $model = TranslationKey::firstOrCreate(
                    ['key' => $key],
                    ['area' => $this->areaFor($key), 'placeholders' => $this->placeholdersIn($byLocale)],
                );

                if ($model->wasRecentlyCreated) {
                    $created++;
                }

                foreach ($byLocale as $locale => $value) {
                    if (! in_array($locale, TranslationKey::LOCALES, true)) {
                        continue;
                    }

                    $row = Translation::firstOrNew([
                        'translation_key_id' => $model->id,
                        'locale' => $locale,
                    ]);

                    // Never clobber something a human has already published
                    // from the panel — the Dart file stops being the source
                    // of truth the moment somebody edits a string here.
                    if (filled($row->published)) {
                        continue;
                    }

                    $row->draft = $value;

                    if ($this->option('publish')) {
                        $row->published = $value;
                        $row->published_at = now();
                        $row->updated_by = 'translations:import';
                    }

                    $row->save();
                    $filled++;
                }
            }
        });

        TranslationCatalogue::forget();

        $this->info(sprintf('%d new key(s), %d value(s) written.%s',
            $created, $filled,
            $this->option('publish') ? ' Published.' : ' Left as drafts.'));

        return self::SUCCESS;
    }

    /** The screen a key belongs to, from its prefix. */
    private function areaFor(string $key): string
    {
        $prefix = explode('_', $key)[0];

        return match ($prefix) {
            'nav' => 'navigation',
            'sp' => 'social profile',
            'ch', 'chat' => 'chat',
            'legal' => 'legal',
            'profile' => 'profile',
            'settings' => 'settings',
            'admin', 'mod' => 'admin',
            'compose' => 'compose',
            'place' => 'places',
            'time' => 'time',
            default => $prefix,
        };
    }

    /**
     * Placeholder names used by any translation of this key.
     *
     * Collected across languages, because a placeholder present in Turkish
     * and missing from Russian is exactly the bug the validation rule
     * exists to catch.
     *
     * @param  array<string, string>  $byLocale
     * @return list<string>
     */
    private function placeholdersIn(array $byLocale): array
    {
        $found = [];

        foreach ($byLocale as $value) {
            if (preg_match_all('/\{([a-zA-Z][a-zA-Z0-9_]*)\}/', $value, $m)) {
                $found = array_merge($found, $m[1]);
            }
        }

        return array_values(array_unique($found));
    }
}
