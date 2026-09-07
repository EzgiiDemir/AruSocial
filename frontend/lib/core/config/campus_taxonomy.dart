/// ARUCAD's real academic structure (faculties → departments), sourced
/// from arucad.edu.tr — not invented. Backs the Trainer Panel's
/// department scoping and the admin Staff directory's faculty/department
/// pickers. Mirrors backend/app/Support/CampusTaxonomy.php exactly — keep
/// both in parity, the same discipline this codebase already applies to
/// GranularPermissions::KEYS <-> the UserRole enum.
class CampusTaxonomy {
  static const Map<String, List<String>> faculties = {
    'Faculty of Arts': [
      'Archaeology',
      'Film Design and Management',
      'Photography',
      'Fine Arts',
      'Ceramics',
      'Textile and Fashion Design',
    ],
    'Faculty of Design': [
      'Industrial Design',
      'Interior Architecture and Environmental Design',
      'Urban Design and Landscape Architecture',
      'Architecture',
    ],
    'Faculty of Communication': [
      'Visual Communication Design',
      'New Media and Communication',
      'Digital Game Design',
    ],
    'Faculty of Music and Performing Arts': [
      'Modern Dance',
      'Acting',
      'Sound Design',
    ],
  };

  static const List<String> nonAcademicDepartments = [
    'Student Affairs',
    'Sports',
    'Library',
    'Career',
    'IT',
    'Health',
    'International',
    'Security',
    'Administration',
    'Accommodation',
    'Transport',
    'Counseling',
    'Rectorate',
  ];

  static List<String> get departments => [
        ...nonAcademicDepartments,
        for (final list in faculties.values) ...list,
      ];

  static String? facultyOf(String department) {
    for (final entry in faculties.entries) {
      if (entry.value.contains(department)) return entry.key;
    }
    return null;
  }
}
