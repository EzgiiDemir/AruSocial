<?php

namespace App\Support;

// Category taxonomy for browsable content (Events, Clubs, Services,
// Places) — deliberately SEPARATE from CampusTaxonomy's academic
// faculty/department structure. Real inspection of the live dev DB
// (`sql/database.sqlite`) showed these are a genuinely different concept:
// a place like "Kütüphane" or a service like "Sağlık Merkezi" isn't
// organized by academic department, it's organized by campus function.
// Conflating the two would force nonsensical picks (e.g. a library
// building "belonging to" the Faculty of Design).
//
// Built as the real UNION of category values already live in the
// database, not invented — this app's admin history typed categories in
// a genuine mix of Turkish and English, and rewriting all of it to one
// language would be a much bigger, riskier data migration than "make
// future entries consistent." Only unambiguous same-concept encoding/typo
// duplicates were merged (e.g. "Idari"→"İdari", "Guvenlik"→"Güvenlik",
// "Workshops"→"Workshop"); values that plausibly describe a genuinely
// distinct real thing (e.g. "Admin+Academic" for a specific dual-purpose
// building) were kept rather than guessed away. Mirrored on the Dart side
// by frontend/lib/core/config/content_categories.dart.
class ContentCategories
{
    // Events + Clubs: what kind of activity it is.
    public const ACTIVITY = [
        'Etkinlik',
        'Akademik',
        'Bandabuliya',
        'Öğrenci Etkinliği',
        'Yaratıcı',
        'Film',
        'Digital',
        'Art',
        'Performance',
        'Community',
        'Design',
        'Sports',
        'Culture',
    ];

    // Services + Places: what campus function it serves.
    public const CAMPUS_FUNCTION = [
        'İdari',
        'Career',
        'Akademik',
        'Wellbeing',
        'International',
        'Konaklama',
        'Teknik',
        'Erişilebilirlik',
        'Yardım',
        'Güvenlik',
        'Spor',
        'Sağlık',
        'Entrance',
        'Administration',
        'Academic',
        'Admin+Academic',
        'Education',
        'Studio',
        'Library',
        'Support',
        'Marketing',
        'Art',
        'Social',
        'Workshop',
        'Workshop/Gallery',
        'Gallery',
        'Accommodation',
        'Campus',
    ];
}
