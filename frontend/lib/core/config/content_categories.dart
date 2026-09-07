/// Category taxonomy for browsable content (Events, Clubs, Services,
/// Places) — deliberately separate from [CampusTaxonomy]'s academic
/// faculty/department structure (a place or service isn't organized by
/// academic department, it's organized by campus function). Built as the
/// real union of category values already live in the database, not
/// invented. Mirrors backend/app/Support/ContentCategories.php exactly —
/// keep both in parity.
class ContentCategories {
  /// Events + Clubs: what kind of activity it is.
  static const List<String> activity = [
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

  /// Services + Places: what campus function it serves.
  static const List<String> campusFunction = [
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
