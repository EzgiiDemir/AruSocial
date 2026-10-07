<?php

use App\Services\Translations\TranslationCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keep the admin-managed catalogue aligned with the image-only composer.
 *
 * Published catalogue values override the strings bundled in Flutter. The
 * composer stopped accepting videos earlier, but existing installations still
 * advertised "photo or video" because those published rows pre-dated that
 * product change.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const PHOTO_ONLY = [
        'tr' => 'Fotoğraf Ekle',
        'en' => 'Add photo',
        'ru' => 'Добавить фото',
    ];

    /** @var array<string, string> */
    private const PREVIOUS = [
        'tr' => 'Fotoğraf veya video ekle',
        'en' => 'Add photo or video',
        'ru' => 'Добавить фото или видео',
    ];

    public function up(): void
    {
        $this->replace(self::PHOTO_ONLY);
    }

    public function down(): void
    {
        $this->replace(self::PREVIOUS);
    }

    /** @param array<string, string> $values */
    private function replace(array $values): void
    {
        if (! Schema::hasTable('translation_keys') || ! Schema::hasTable('translations')) {
            return;
        }

        $keyId = DB::table('translation_keys')
            ->where('key', 'compose_add_media')
            ->value('id');

        if ($keyId === null) {
            return;
        }

        foreach ($values as $locale => $value) {
            DB::table('translations')
                ->where('translation_key_id', $keyId)
                ->where('locale', $locale)
                ->update([
                    'draft' => $value,
                    'published' => $value,
                    'published_at' => now(),
                    'updated_by' => 'migration:photo-only-composer',
                    'updated_at' => now(),
                ]);
        }

        try {
            TranslationCatalogue::forget();
        } catch (\Throwable) {
            // The rows are authoritative; cache invalidation can retry later.
        }
    }
};
