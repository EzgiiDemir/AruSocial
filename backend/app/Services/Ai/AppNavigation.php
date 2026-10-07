<?php

namespace App\Services\Ai;

use App\Support\RequestMemo;
use App\Support\TextFold;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The trusted app destinations (config/aicad_navigation.php), with their
 * labels in every locale read from the app's own translation tables — the
 * one source of truth for "go to the X screen" in an AICAD answer.
 */
final class AppNavigation
{
    /** @return array<string, array{id: string, key: string, parent: ?string, labels: array<string, string>, path: array<string, string>}> */
    public function destinations(): array
    {
        return app(RequestMemo::class)->remember('aicad:navigation', function (): array {
            $config = (array) config('aicad_navigation.destinations', []);
            $labels = $this->labels(array_column($config, 'key'));
            $out = [];
            foreach ($config as $id => $d) {
                $out[$id] = ['id' => $id, 'key' => $d['key'], 'parent' => $d['parent'] ?? null, 'labels' => $labels[$d['key']] ?? []];
            }
            foreach ($out as $id => $d) {
                $out[$id]['path'] = [];
                foreach (['tr', 'en', 'ru'] as $locale) {
                    $own = $d['labels'][$locale] ?? null;
                    $parent = $d['parent'] !== null ? ($out[$d['parent']]['labels'][$locale] ?? null) : null;
                    if ($own !== null) {
                        $out[$id]['path'][$locale] = $parent !== null ? $parent.' → '.$own : $own;
                    }
                }
            }

            return $out;
        });
    }

    /**
     * Whether a folded phrase names a trusted destination (any locale).
     */
    public function names(string $folded): ?string
    {
        foreach ($this->destinations() as $id => $d) {
            foreach ($d['labels'] as $label) {
                $label = TextFold::fold($label);
                if ($label !== '' && str_contains($folded, $label)) {
                    return $id;
                }
            }
        }

        return null;
    }

    /** One line for a prompt: the real destinations, in Turkish (the prompt language). */
    public function promptLine(): string
    {
        $paths = array_values(array_filter(array_map(fn ($d) => $d['path']['tr'] ?? null, $this->destinations())));

        return $paths === [] ? '' : 'Uygulamada GERÇEKTEN var olan bölümler (yalnızca bunlara yönlendir; başka sekme, menü, ekran veya web bölümü adı uydurma): '.implode('; ', $paths).'.';
    }

    /** @param list<string> $keys  @return array<string, array<string, string>> */
    private function labels(array $keys): array
    {
        if ($keys === [] || ! Schema::hasTable('translation_keys') || ! Schema::hasTable('translations')) {
            return [];
        }
        $out = [];
        $rows = DB::table('translations')->join('translation_keys', 'translation_keys.id', '=', 'translations.translation_key_id')
            ->whereIn('translation_keys.key', $keys)->get(['translation_keys.key', 'translations.locale', 'translations.published']);
        foreach ($rows as $row) {
            $value = is_string($row->published) ? trim($row->published) : '';
            if ($value !== '') {
                $out[$row->key][$row->locale] = $value;
            }
        }

        return $out;
    }
}
