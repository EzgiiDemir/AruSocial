<?php

namespace App\Services\Ai;

use App\Models\AiEntityAlias;
use App\Models\KnowledgeFact;
use App\Support\PhraseMatcher;
use App\Support\RequestMemo;
use App\Support\TextFold;

/**
 * Canonical programmes, across languages.
 *
 * A programme has no table of its own: it is the subject of its extracted
 * knowledge_facts. Its canonical id is the folded name of that subject; its
 * other names (English, Russian, an old name) are operator-managed aliases of
 * type `programme`. When an alias equals another fact subject — the English
 * page's "New Media and Communication" — the two subjects are ONE programme,
 * so its facts are never duplicated per language.
 *
 * A name that maps to nothing ("Graphic Design", which ARUCAD does not list)
 * stays unresolved: "not found in the current data", never "does not exist".
 */
final class ProgrammeCatalog
{
    /** @return list<array{id: string, name: string, names: list<string>}> */
    public function all(): array
    {
        return app(RequestMemo::class)->remember('programmes:'.RetrievalVersion::counter(), function (): array {
            $groups = [];
            foreach (KnowledgeFact::query()->where('subject_type', KnowledgeFact::SUBJECT_PROGRAMME)
                ->select(['subject', 'subject_folded'])->distinct()->get() as $fact) {
                $id = trim((string) $fact->subject_folded);
                if ($id !== '') {
                    $groups[$id] ??= ['id' => $id, 'name' => (string) $fact->subject, 'names' => [$id]];
                }
            }
            foreach ((array) (AiEntityAlias::index()[AiEntityAlias::TYPE_PROGRAMME] ?? []) as $id => $aliases) {
                if (! isset($groups[$id])) {
                    continue;
                }
                foreach ($aliases as $alias) {
                    $groups[$id]['names'][] = $alias;
                    // An alias naming another subject merges it into this programme.
                    if ($alias !== $id && isset($groups[$alias])) {
                        array_push($groups[$id]['names'], ...$groups[$alias]['names']);
                        unset($groups[$alias]);
                    }
                }
            }

            return array_values(array_map(fn (array $g) => ['id' => $g['id'], 'name' => $g['name'], 'names' => array_values(array_unique($g['names']))], $groups));
        });
    }

    /**
     * Programmes a text names, longest match first; a name inside a longer
     * matched name ("mimarlık" in "iç mimarlık ve çevre tasarımı") does not
     * count separately.
     *
     * @return list<array{id: string, name: string, names: list<string>}>
     */
    public function inText(string $text): array
    {
        $folded = TextFold::fold($text);
        $hits = [];
        foreach ($this->all() as $programme) {
            foreach ($programme['names'] as $name) {
                $at = PhraseMatcher::position($folded, $name, 5);
                if ($at !== null && (! isset($hits[$programme['id']]) || mb_strlen($name) > $hits[$programme['id']]['length'])) {
                    $hits[$programme['id']] = ['programme' => $programme, 'start' => $at, 'length' => mb_strlen($name)];
                }
            }
        }
        uasort($hits, fn ($a, $b) => $b['length'] <=> $a['length']);
        $kept = [];
        foreach ($hits as $hit) {
            $inside = collect($kept)->contains(fn ($k) => $hit['start'] >= $k['start'] && $hit['start'] + $hit['length'] <= $k['start'] + $k['length']);
            if (! $inside) {
                $kept[] = $hit;
            }
        }

        return array_map(fn ($k) => $k['programme'], $kept);
    }

    /** The canonical programme a fact subject belongs to. */
    public function canonicalOf(string $foldedSubject): ?array
    {
        foreach ($this->all() as $programme) {
            if (in_array($foldedSubject, $programme['names'], true)) {
                return $programme;
            }
        }

        return null;
    }
}
