<?php

namespace App\Services;

// A real, deterministic Turkish-keyword classifier — not a second AI call
// (extra latency/cost for a label nobody's waiting on) and not a fake
// random label. Same "honest heuristic, clearly documented as one" spirit
// as ModerationService's blocked-terms list or AcademicRoutingService's
// department matching: a real deployment might swap this for an actual
// classifier, but every category this returns today is a genuine keyword
// match against the real question text, not invented.
class AskArucadCategorizer
{
    private const CATEGORY_KEYWORDS = [
        'akademik' => ['ders', 'sınav', 'not', 'akademik', 'bölüm', 'dekan', 'danışman', 'transkript', 'hoca', 'öğretim'],
        'spor' => ['spor', 'antrenman', 'fitness', 'salon', 'takım', 'maç', 'yüzme'],
        'kulüp' => ['kulüp', 'topluluk', 'üye ol', 'kulübe'],
        'kariyer' => ['kariyer', 'staj', 'iş ilan', 'cv', 'mülakat', 'işe alım'],
        'kampüs hizmetleri' => ['sağlık', 'kütüphane', 'danışma', 'destek birimi', 'psikolojik', 'teknik destek', 'it destek'],
        'yemek' => ['yemek', 'menü', 'kantin', 'kafeterya', 'restoran', 'öğle yemeği'],
        'sosyal' => ['etkinlik', 'parti', 'buluşma', 'sosyal', 'arkadaş'],
        'ulaşım' => ['servis', 'otobüs', 'shuttle', 'ulaşım', 'park yeri', 'otopark'],
        'yardım' => ['yardım', 'nasıl yapılır', 'sorun', 'hata', 'çalışmıyor'],
        'kampüs' => ['nerede', 'kampüs', 'bina', 'harita', 'konum', 'saat kaçta', 'açık mı'],
    ];

    public static function categorize(string $question): string
    {
        $normalized = mb_strtolower($question);
        foreach (self::CATEGORY_KEYWORDS as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $keyword)) {
                    return $category;
                }
            }
        }

        return 'diğer';
    }
}
