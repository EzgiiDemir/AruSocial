<?php

namespace App\Services\Ai;

use App\Models\AiEntityAlias;
use App\Models\Club;
use App\Models\FoodVenue;
use App\Models\Place;
use App\Models\ServiceItem;
use App\Models\Sport;
use App\Models\StaffProfile;
use App\Support\PhraseMatcher;
use App\Support\RequestMemo;
use App\Support\TextFold;
use Illuminate\Database\Eloquent\Model;

/**
 * Which canonical campus rows a question names, and how sure we are.
 *
 * Order of trust, and the ranking enforces it:
 *   1. the row's own name, matched as a whole phrase   (method `name`)
 *   2. an operator alias, matched as a whole phrase    (method `alias`)
 *   3. either of those within one typo                 (method `fuzzy`)
 *
 * A fuzzy match is only looked for when nothing matched exactly, so "gym"
 * resolving through an alias can never be outvoted by a near-miss spelling
 * of some other row. Places and services go through PlaceResolver, which
 * already owns their built-in multilingual aliases and the place a service
 * sits in — this class adds the other entity types beside it rather than
 * re-implementing it.
 *
 * Entities are reported to the router and the trace; this class never
 * writes anything into the prompt itself.
 */
final class EntityResolver
{
    private const SCORE = ['name' => 1.0, 'alias' => 0.9, 'fuzzy' => 0.6];

    /** Entity type → the routing domain it implies. */
    public const DOMAIN_FOR_TYPE = [
        AiEntityAlias::TYPE_PLACE => 'places',
        AiEntityAlias::TYPE_SERVICE => 'services',
        AiEntityAlias::TYPE_CLUB => 'clubs',
        AiEntityAlias::TYPE_SPORT => 'sports',
        AiEntityAlias::TYPE_FOOD_VENUE => 'food',
        AiEntityAlias::TYPE_STAFF => 'staff',
    ];

    public function __construct(private readonly PlaceResolver $places) {}

    /**
     * @return list<array{type: string, id: string, name: string, matched: string, method: string, score: float, ambiguous: bool}>
     */
    public function resolve(string $query): array
    {
        // The planner and the knowledge base both ask for the same query in
        // one request; resolve it once.
        return app(RequestMemo::class)->remember('entities:'.sha1(TextFold::fold($query)), fn () => $this->resolveUncached($query));
    }

    /** @return list<array<string, mixed>> */
    private function resolveUncached(string $query): array
    {
        $text = TextFold::fold($query);
        if (trim($text) === '') {
            return [];
        }

        $found = [];
        foreach ($this->places->mentioned($query) as $hit) {
            $place = $hit['place'];
            $service = $hit['service'];
            // PlaceResolver does not say whether the hit was the name or one
            // of its built-in aliases; comparing to the folded name does.
            if ($service !== null) {
                $this->add($found, AiEntityAlias::TYPE_SERVICE, (string) $service->id, (string) $service->title,
                    $hit['matched'], $hit['matched'] === TextFold::fold((string) $service->title) ? 'name' : 'alias');
            }
            $this->add($found, AiEntityAlias::TYPE_PLACE, (string) $place->id, (string) $place->name,
                $hit['matched'], $hit['matched'] === TextFold::fold((string) $place->name) ? 'name' : 'alias');
        }

        $candidates = $this->otherCandidates();
        foreach ($candidates as $candidate) {
            foreach ($candidate['phrases'] as $phrase => $method) {
                if (PhraseMatcher::position($text, $phrase) !== null) {
                    $this->add($found, $candidate['type'], $candidate['id'], $candidate['name'], $phrase, $method);
                    break;
                }
            }
        }

        if ($found === []) {
            foreach ($this->fuzzyCandidates($candidates) as $candidate) {
                foreach (array_keys($candidate['phrases']) as $phrase) {
                    // A one-word phrase under five letters cannot be fuzzy
                    // matched safely; PhraseMatcher::fuzzy enforces that.
                    if (PhraseMatcher::fuzzy($text, $phrase)) {
                        $this->add($found, $candidate['type'], $candidate['id'], $candidate['name'], $phrase, 'fuzzy');
                        break;
                    }
                }
            }
        }

        // A name inside a longer matched name of the same type does not count
        // separately: "masa tenisi" is table tennis, not also "tenis".
        $found = array_values(array_filter($found, fn (array $e) => ! collect($found)->contains(
            fn (array $o) => $o['type'] === $e['type'] && $o['id'] !== $e['id'] && mb_strlen((string) $o['matched']) > mb_strlen((string) $e['matched'])
                && str_contains((string) $o['matched'], (string) $e['matched']))));
        usort($found, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return $this->markAmbiguity($found);
    }

    /**
     * One phrase naming two rows of the same type ("idari bina" for two
     * administration buildings) is ambiguous: both stay in the result —
     * nothing is silently chosen — and both say so, with a halved score so
     * neither outranks an unambiguous match. A phrase shared across types
     * (an office and the building it is in) is normal and not flagged.
     *
     * @param  list<array<string, mixed>>  $found
     * @return list<array<string, mixed>>
     */
    private function markAmbiguity(array $found): array
    {
        $groups = [];
        foreach ($found as $i => $entity) {
            $groups[$entity['type'].'|'.$entity['matched']][] = $i;
        }
        foreach ($groups as $members) {
            $ambiguous = count($members) > 1;
            foreach ($members as $i) {
                $found[$i]['ambiguous'] = $ambiguous;
                if ($ambiguous) {
                    $found[$i]['score'] = round($found[$i]['score'] / 2, 2);
                }
            }
        }
        usort($found, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return $found;
    }

    /**
     * @param  array<string, array<string, mixed>>  $found
     */
    private function add(array &$found, string $type, string $id, string $name, string $matched, string $method): void
    {
        $key = $type.':'.$id;
        $score = self::SCORE[$method];
        if (! isset($found[$key]) || $found[$key]['score'] < $score) {
            $found[$key] = compact('type', 'id', 'name', 'matched', 'method', 'score');
        }
    }

    /**
     * Clubs, sports, food venues and staff: name plus operator aliases.
     *
     * @return list<array{type: string, id: string, name: string, phrases: array<string, string>}>
     */
    private function otherCandidates(): array
    {
        $sets = [
            AiEntityAlias::TYPE_CLUB => fn () => Club::query()->get(['id', 'name']),
            AiEntityAlias::TYPE_SPORT => fn () => Sport::query()->get(['id', 'name', 'facility', 'place_id']),
            AiEntityAlias::TYPE_FOOD_VENUE => fn () => FoodVenue::query()->get(['id', 'name']),
            AiEntityAlias::TYPE_STAFF => fn () => StaffProfile::query()->get(['id', 'name']),
        ];

        $out = [];
        foreach ($sets as $type => $rows) {
            /** @var Model $row */
            foreach ($rows() as $row) {
                $id = (string) $row->getKey();
                $phrases = [];
                $name = TextFold::fold(trim((string) $row->getAttribute('name')));
                if ($name !== '') {
                    $phrases[$name] = 'name';
                }
                if ($type === AiEntityAlias::TYPE_SPORT) {
                    foreach (self::sportShortNames($name) as $short) {
                        $phrases[$short] ??= 'alias';
                    }
                }
                foreach (AiEntityAlias::for($type, $id) as $alias) {
                    $phrases[$alias] ??= 'alias';
                }
                if ($phrases !== []) {
                    $out[] = ['type' => $type, 'id' => $id, 'name' => (string) $row->getAttribute('name'), 'phrases' => $phrases];
                }
            }
        }

        // A sports facility name ("Spor Salonu") names the campus place staff
        // linked its teams to — only when every team there points at the same
        // place. The name itself is never matched to a place.
        foreach (Sport::query()->whereNotNull('facility')->get(['facility', 'place_id'])->groupBy(fn ($s) => TextFold::fold(trim((string) $s->facility))) as $facility => $teams) {
            $places = $teams->pluck('place_id')->unique();
            if ($facility !== '' && $places->count() === 1 && $places->first() !== null && ($place = Place::query()->find($places->first())) !== null) {
                $out[] = ['type' => AiEntityAlias::TYPE_PLACE, 'id' => (string) $place->id, 'name' => (string) $place->name, 'phrases' => [$facility => 'alias']];
            }
        }

        return $out;
    }

    /**
     * Shorter forms of a team's canonical name that students use: without the
     * "ARUCAD" prefix and the "(Erkek)" qualifier, and without a trailing
     * "takımı/kulübü" ("ARUCAD Basketbol Takımı (Erkek)" → "basketbol
     * takımı", "basketbol"). Derived from the name itself, never invented.
     *
     * @return list<string>
     */
    public static function sportShortNames(string $foldedName): array
    {
        $core = trim((string) preg_replace(['/^arucad\s+/u', '/\s*\([^)]*\)\s*$/u'], '', $foldedName));
        $bare = trim((string) preg_replace('/\s+(?:takimi|kulubu)$/u', '', $core));

        return array_values(array_unique(array_filter([$core, $bare], fn ($v) => $v !== $foldedName && mb_strlen($v) >= 4)));
    }

    /**
     * Everything that may be fuzzy matched: the candidates above plus every
     * place name, service title and their operator aliases. PlaceResolver's
     * built-in aliases stay exact-only, as the operational path relies on
     * them.
     *
     * @param  list<array{type: string, id: string, name: string, phrases: array<string, string>}>  $candidates
     * @return list<array{type: string, id: string, name: string, phrases: array<string, string>}>
     */
    private function fuzzyCandidates(array $candidates): array
    {
        $rows = [
            AiEntityAlias::TYPE_PLACE => Place::query()->get(['id', 'name'])
                ->map(fn (Place $p) => [(string) $p->id, (string) $p->name]),
            AiEntityAlias::TYPE_SERVICE => ServiceItem::query()->get(['id', 'title'])
                ->map(fn (ServiceItem $s) => [(string) $s->id, (string) $s->title]),
        ];
        foreach ($rows as $type => $list) {
            foreach ($list as [$id, $label]) {
                $phrases = array_fill_keys(AiEntityAlias::for($type, $id), 'alias');
                $name = TextFold::fold(trim($label));
                if ($name !== '') {
                    $phrases[$name] = 'name';
                }
                $candidates[] = ['type' => $type, 'id' => $id, 'name' => $label, 'phrases' => $phrases];
            }
        }

        return $candidates;
    }
}
