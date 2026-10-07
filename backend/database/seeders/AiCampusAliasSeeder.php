<?php

namespace Database\Seeders;

use App\Models\AiEntityAlias;
use App\Models\Club;
use App\Models\ServiceItem;
use Illuminate\Database\Seeder;

/**
 * Official and common office and club names as entity aliases (data in
 * aicad_campus_aliases.json, each entry with its basis), so short Turkish
 * names, official English names and Russian case forms reach the same row.
 *
 *   php artisan db:seed --class=AiCampusAliasSeeder
 *
 * Same contract as AiProgrammeAliasSeeder: idempotent and additive only. A
 * row that already has an alias in a locale is left alone entirely, so a
 * rerun never duplicates a row or brings back a name an operator replaced,
 * and an id that does not exist is skipped. Writes go through the model, so
 * the alias cache and RetrievalVersion are invalidated.
 */
class AiCampusAliasSeeder extends Seeder
{
    /** JSON section => [entity type, model holding the ids, key naming the id]. */
    private const SECTIONS = [
        'services' => [AiEntityAlias::TYPE_SERVICE, ServiceItem::class, 'service'],
        'clubs' => [AiEntityAlias::TYPE_CLUB, Club::class, 'club'],
    ];

    public function run(): void
    {
        $data = json_decode((string) file_get_contents(database_path('seeders/data/aicad_campus_aliases.json')), true) ?: [];
        foreach (self::SECTIONS as $section => [$type, $model, $key]) {
            foreach ($data[$section] ?? [] as $row) {
                $id = (string) $row[$key];
                if (! $model::query()->whereKey($id)->exists()) {
                    continue;
                }
                foreach (['tr', 'en', 'ru'] as $locale) {
                    $managed = AiEntityAlias::query()->where('entity_type', $type)
                        ->where('entity_id', $id)->where('locale', $locale)->exists();
                    if ($managed) {
                        continue;
                    }
                    foreach ((array) ($row[$locale] ?? []) as $alias) {
                        AiEntityAlias::create(['entity_type' => $type, 'entity_id' => $id, 'alias' => $alias,
                            'locale' => $locale, 'active' => true, 'created_by' => 'seeder']);
                    }
                }
            }
        }
    }
}
