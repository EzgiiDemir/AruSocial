<?php

namespace App\Services;

use App\Models\Club;
use App\Models\Event;
use App\Models\FoodVenue;
use App\Models\Place;
use App\Models\ServiceItem;
use App\Models\Sport;
use App\Support\SchemaColumnCache;

/**
 * Grounded campus answers when Groq is not configured or upstream fails.
 * Uses live catalog rows — not canned marketing copy.
 */
class CampusAskFallback
{
    public function answer(string $prompt): string
    {
        $q = mb_strtolower(trim($prompt));
        if ($q === '') {
            return 'Bir yer, etkinlik, servis veya birim adı yaz, kampüs kayıtlarından bakayım.';
        }

        foreach (Place::query()->get() as $place) {
            if ($place->name !== '' && mb_stripos($q, mb_strtolower($place->name)) !== false) {
                $bits = array_filter([$place->category, $place->street, $place->distance]);

                return $place->name.' kampüste. '.
                    ($place->description ? $place->description.' ' : '').
                    implode(' · ', $bits);
            }
        }

        // Real fix: Schema::hasColumn() used to re-query the database's own
        // schema metadata on every fallback call. SchemaColumnCache answers
        // it once per worker process instead.
        $events = Event::query();
        if (SchemaColumnCache::hasColumn('events', 'draft')) {
            $events->where('draft', false);
        }
        if (SchemaColumnCache::hasColumn('events', 'workflow_status')) {
            $events->where('workflow_status', 'published');
        }
        $catalog = $events->limit(40)->get();
        foreach ($catalog as $event) {
            if ($event->title !== '' && mb_stripos($q, mb_strtolower($event->title)) !== false) {
                return $event->title.' · '.$event->place_name.', saat '.$event->time.
                    ($event->attendees ? ' · '.$event->attendees.' katılımcı' : '').'.';
            }
        }
        if (str_contains($q, 'etkinlik') && $catalog->isNotEmpty) {
            $listed = $catalog->take(3)->map(fn ($e) => $e->title.' · '.$e->place_name)->implode('; ');

            return 'Yakın etkinlikler: '.$listed.'.';
        }

        foreach (ServiceItem::all() as $service) {
            if (mb_stripos($q, mb_strtolower($service->title)) !== false
                || ($service->category && mb_stripos($q, mb_strtolower($service->category)) !== false)) {
                $where = collect([$service->building, $service->floor, $service->room])->filter()->implode(', ');

                return $service->title.': '.$service->description.
                    ($where !== '' ? ' Konum: '.$where.'.' : '').
                    ($service->contact ? ' İletişim: '.$service->contact : '');
            }
        }

        foreach (Club::all() as $club) {
            if (mb_stripos($q, mb_strtolower($club->name)) !== false) {
                return $club->name.' ('.$club->category.'): '.$club->description;
            }
        }

        foreach (Sport::all() as $sport) {
            if (mb_stripos($q, mb_strtolower($sport->name)) !== false) {
                return $sport->name.': '.$sport->facility.' kullanılıyor. Keşfet içinden ön başvuru yapabilirsin.';
            }
        }

        if (str_contains($q, 'yemek') || str_contains($q, 'menü') || str_contains($q, 'menu') || str_contains($q, 'garden')) {
            $venue = FoodVenue::with('dailyMenus')->first();
            if ($venue) {
                $menu = $venue->dailyMenus->first();
                $items = $menu && is_array($menu->items) ? implode(', ', $menu->items) : 'bugünün menüsü henüz girilmedi';

                return $venue->name.': '.$items;
            }
        }

        if (str_contains($q, 'servis') || str_contains($q, 'otobüs') || str_contains($q, 'shuttle')) {
            return 'Kampüs servis saatleri Keşfet ve haritadaki Servis bölümünde. Lefkoşa, Alsancak, Çatalköy ve atölye hatları var.';
        }

        if (str_contains($q, 'merhaba') || str_contains($q, 'selam') || str_contains($q, 'hello')) {
            return 'Merhaba, ben Ask ARUCAD. Yer, etkinlik, kulüp, spor veya birim sorabilirsin.';
        }

        $placeNames = Place::query()->limit(5)->pluck('name')->filter()->implode(', ');

        return 'Bunu kayıtta net eşleştiremedim.'.
            ($placeNames !== '' ? ' Denemek için bir yer adı yaz, örneğin: '.$placeNames.'.' : ' Yer veya etkinlik adı ile tekrar sor.');
    }
}
