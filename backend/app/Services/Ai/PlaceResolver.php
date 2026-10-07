<?php

namespace App\Services\Ai;

use App\Models\AiEntityAlias;
use App\Models\Place;
use App\Models\ServiceItem;
use App\Support\PhraseMatcher;
use App\Support\TextFold;

/** Deterministic multilingual place/service resolution over canonical rows. */
final class PlaceResolver
{
    /** @return list<array{place: Place, service: ?ServiceItem, matched: string}> */
    public function mentioned(string $query): array
    {
        $needle = TextFold::fold($query);
        $matches = [];

        foreach (ServiceItem::query()->get() as $service) {
            foreach ($this->serviceAliases($service) as $alias) {
                $position = $this->matchPosition($needle, $alias);
                if ($position !== null) {
                    $place = $this->placeForBuilding((string) $service->building);
                    if ($place !== null) {
                        if (! isset($matches[$place->id]) || $position < $matches[$place->id]['position']) {
                            $matches[$place->id] = ['place' => $place, 'service' => $service, 'matched' => $alias, 'position' => $position];
                        }
                    }
                }
            }
        }

        foreach ($this->canonicalPlaces() as $place) {
            foreach ($this->placeAliases($place) as $alias) {
                $position = $this->matchPosition($needle, $alias);
                if ($position !== null) {
                    $matches[$place->id] ??= ['place' => $place, 'service' => null, 'matched' => $alias, 'position' => $position];
                }
            }
        }

        $matches = array_values($matches);
        usort($matches, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return array_map(static function (array $match): array {
            unset($match['position']);

            return $match;
        }, $matches);
    }

    public function component(Place $place, ?ServiceItem $service = null): array
    {
        return array_filter([
            'id' => (string) $place->id,
            'name' => (string) $place->name,
            'category' => (string) $place->category,
            'description' => trim((string) ($service?->description ?: $place->description)),
            'building' => $service?->building,
            'floor' => $service?->floor,
            'room' => $service?->room,
            'latitude' => (float) $place->lat,
            'longitude' => (float) $place->lng,
            'hours' => $service?->hours,
            'contact' => $service?->contact,
            'serviceId' => $service?->id,
            'serviceName' => $service?->title,
            'navigationAvailable' => $place->lat !== null && $place->lng !== null,
            'tourUrl' => $place->tour_url,
            'tourTarget' => $place->tour_target,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    private function placeForBuilding(string $building): ?Place
    {
        $target = TextFold::fold($building);
        if ($target === '') {
            return null;
        }

        foreach ($this->canonicalPlaces() as $place) {
            if (TextFold::fold((string) $place->name) === $target) {
                return $place;
            }
        }

        return null;
    }

    /** @return list<Place> */
    private function canonicalPlaces(): array
    {
        $unique = [];
        foreach (Place::query()->get() as $place) {
            $key = TextFold::fold((string) $place->name);
            if (! isset($unique[$key]) || str_starts_with((string) $unique[$key]->id, 'place-')) {
                $unique[$key] = $place;
            }
        }

        return array_values($unique);
    }

    /** @return list<string> */
    private function serviceAliases(ServiceItem $service): array
    {
        $aliases = [(string) $service->title, (string) $service->id];
        $known = [
            // The Russian entries include the phrasing this application itself
            // uses when it tells a student where to go. PersonalDataCapabilities
            // sends them to "отдел по работе со студентами", and the resolver
            // did not recognise that phrase — so the assistant named an office
            // it could not then answer a question about.
            'student-affairs' => ['ogrenci isleri', 'student affairs', 'registrar',
                'студенческий отдел', 'учебный отдел', 'отдел по работе со студентами',
                'студенческий офис'],
            'international' => ['uluslararasi ofis', 'international office',
                'international relations', 'международный отдел', 'международный офис',
                'отдел по работе с иностранными студентами'],
            'library' => ['kutuphane', 'library', 'библиотека'],
            'it' => ['bilgi islem', 'it office', 'it destek', 'it support',
                'information technology', 'it department', 'айти отдел',
                'ит отдел', 'техподдержка'],
            'pdr' => ['psikolojik danismanlik', 'counseling', 'psychological support', 'консультация психолога'],
            'accessibility' => ['engelli ogrenci', 'engelsiz kampus', 'accessibility', 'disabled student support', 'доступность'],
            'career' => ['kariyer ofisi', 'kariyer ve mezun', 'career office', 'career services', 'карьерный центр'],
            'lost-found' => ['kayip esya', 'kayip bulunan', 'lost and found', 'lost property', 'бюро находок'],
            'dormitory' => ['yurt', 'ogrenci yurdu', 'dorm', 'dormitory', 'общежитие'],
            'academic-advis' => ['akademik danisman', 'academic advisor', 'academic advising', 'академический консультант'],
        ];
        foreach ($known as $idPart => $terms) {
            $serviceId = TextFold::fold((string) $service->id);
            $titleKey = str_replace('-', ' ', $idPart);
            if ($serviceId === $idPart
                || str_starts_with($serviceId, $idPart.'-')
                || str_ends_with($serviceId, '-'.$idPart)
                || $this->matchPosition(TextFold::fold((string) $service->title), $titleKey) !== null) {
                $aliases = array_merge($aliases, $terms);
            }
        }
        // Operator-maintained aliases (admin → AICAD → Aliases).
        $aliases = array_merge($aliases, AiEntityAlias::for(AiEntityAlias::TYPE_SERVICE, (string) $service->id));

        return array_values(array_unique(array_filter(array_map(TextFold::fold(...), $aliases))));
    }

    /**
     * Match a complete alias, not a byte sequence inside an unrelated word.
     * See PhraseMatcher for why a leading word boundary plus a short
     * inflection is the rule.
     */
    private function matchPosition(string $text, string $alias): ?int
    {
        return PhraseMatcher::position($text, $alias);
    }

    /** @return list<string> */
    private function placeAliases(Place $place): array
    {
        $aliases = [(string) $place->name];
        $name = TextFold::fold((string) $place->name);
        if ($name === 'meditation') {
            $aliases = array_merge($aliases, ['kutuphane', 'library', 'библиотека']);
        }
        if (in_array($name, ['ana kampus girisi', 'main campus entrance'], true)) {
            $aliases = array_merge($aliases, [
                'ana kampus girisini', 'kampus girisi', 'kampus girisini',
                'main campus entrance', 'campus entrance',
                'главный вход', 'вход в кампус',
            ]);
        }
        $aliases = array_merge($aliases, AiEntityAlias::for(AiEntityAlias::TYPE_PLACE, (string) $place->id));

        return array_values(array_unique(array_filter(array_map(TextFold::fold(...), $aliases))));
    }
}
