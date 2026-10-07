<?php

namespace Database\Seeders;

use App\Models\AiEntityAlias;
use App\Support\TextFold;
use Illuminate\Database\Seeder;

/**
 * Official English and Russian programme names as `programme` aliases, so a
 * question in any supported language reaches the same programme facts.
 *
 *   php artisan db:seed --class=AiProgrammeAliasSeeder
 *
 * Idempotent and additive only: a programme that already has an alias in
 * a locale is left alone entirely — whether the seeder added it or an
 * operator added, renamed or deactivated one. So a rerun never duplicates a
 * row and never brings back a name an operator replaced. Writes go through
 * the model, so the alias cache and RetrievalVersion are invalidated.
 */
class AiProgrammeAliasSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode((string) file_get_contents(database_path('seeders/data/aicad_programme_aliases.json')), true) ?: [];
        foreach ($data['programmes'] ?? [] as $row) {
            $id = TextFold::fold(trim((string) $row['programme']));
            foreach (['en', 'ru'] as $locale) {
                $managed = AiEntityAlias::query()->where('entity_type', AiEntityAlias::TYPE_PROGRAMME)
                    ->where('entity_id', $id)->where('locale', $locale)->exists();
                if ($managed) {
                    continue;
                }
                foreach ((array) ($row[$locale] ?? []) as $alias) {
                    AiEntityAlias::create(['entity_type' => AiEntityAlias::TYPE_PROGRAMME, 'entity_id' => $id, 'alias' => $alias,
                        'locale' => $locale, 'active' => true, 'created_by' => 'seeder']);
                }
            }
        }
    }
}
