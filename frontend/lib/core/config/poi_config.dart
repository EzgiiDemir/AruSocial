class Poi {
  final String name;
  final String category;
  final double lat;
  final double lng;
  final String? note;
  final bool verified;

  const Poi(
      {required this.name,
      required this.category,
      required this.lat,
      required this.lng,
      this.note,
      this.verified = false});
}

const pois = <Poi>[
  // Verified (Google Places)
  Poi(
      name: 'Ana kampüs girişi',
      category: 'Entrance',
      lat: 35.337395,
      lng: 33.321358,
      verified: true),
  Poi(
      name: 'Arkin Rodin Collection Gallery',
      category: 'Gallery',
      lat: 35.337925,
      lng: 33.320036,
      verified: true,
      note: 'Official map vs Google Places address discrepancy noted'),
  Poi(
      name: 'ARUCAD Dormitory',
      category: 'Accommodation',
      lat: 35.331328,
      lng: 33.318916,
      verified: true),
  Poi(
      name: 'Atelier Arkın',
      category: 'Workshop',
      lat: 35.338124,
      lng: 33.321697,
      note: 'Verify Iris/Age of Bronze relation',
      verified: true),
  Poi(
      name: 'ARUCAD Workshops',
      category: 'Workshops',
      lat: 35.333593,
      lng: 33.330680,
      note: 'Iris / Age of Bronze cluster - verify',
      verified: true),
  Poi(
      name: 'Nicosia Bandabuliya Campus',
      category: 'Campus',
      lat: 35.175513,
      lng: 33.365029,
      verified: true),
  Poi(
      name: 'ARUCAD Art Space (Lefkoşa)',
      category: 'Gallery',
      lat: 35.177726,
      lng: 33.360248,
      verified: true),

  // Calculated from campus map (±15-20m possible)
  Poi(
      name: 'Rodin',
      category: 'Administration',
      lat: 35.337305,
      lng: 33.321303,
      note: 'Calculated from map; ±15-20m possible',
      verified: false),
  Poi(
      name: 'Falling Man',
      category: 'Academic',
      lat: 35.337305,
      lng: 33.321027,
      note: 'Calculated from map; ±15-20m possible',
      verified: false),
  Poi(
      name: 'Titan',
      category: 'Admin+Academic',
      lat: 35.337170,
      lng: 33.321633,
      note: 'Calculated from map; ±15-20m possible',
      verified: false),
  Poi(
      name: 'Eve',
      category: 'Education',
      lat: 35.337529,
      lng: 33.321303,
      note: 'Calculated from map; ±15-20m possible',
      verified: false),
  Poi(
      name: 'Daniele',
      category: 'Studio',
      lat: 35.337772,
      lng: 33.321688,
      note: 'Calculated from map; ±15-20m possible',
      verified: false),
  Poi(
      name: 'Eternal Spring',
      category: 'Studio',
      lat: 35.337844,
      lng: 33.321468,
      note: 'Calculated from map; ±15-20m possible',
      verified: false),
  Poi(
      name: 'Meditation',
      category: 'Library',
      lat: 35.337754,
      lng: 33.321358,
      note: 'Calculated from map; ±15-20m possible',
      verified: false),
  Poi(
      name: 'Minotaur',
      category: 'Support',
      lat: 35.337844,
      lng: 33.321270,
      note: 'Calculated from map; ±15-20m possible',
      verified: false),
  Poi(
      name: 'Eternal Idol',
      category: 'Marketing',
      lat: 35.337889,
      lng: 33.321193,
      note: 'Calculated from map; ±15-20m possible',
      verified: false),
  Poi(
      name: 'The Kiss',
      category: 'Art',
      lat: 35.337799,
      lng: 33.321082,
      note: 'Calculated from map; ±15-20m possible',
      verified: false),
  Poi(
      name: 'The Garden',
      category: 'Social',
      lat: 35.337125,
      lng: 33.320972,
      note: 'Calculated from map; ±15-20m possible',
      verified: false),
  Poi(
      name: 'Carpentry Studio',
      category: 'Workshop',
      lat: 35.337502,
      lng: 33.321226,
      note: 'Calculated from map; ±15-20m possible',
      verified: false),

  // Workshop cluster (Age of Bronze / Art Rooms / Iris) — same coord used where ambiguous
  Poi(
      name: 'Age of Bronze',
      category: 'Workshop',
      lat: 35.333593,
      lng: 33.330680,
      note: 'Workshop cluster (Age of Bronze). Verify on site',
      verified: true),
  Poi(
      name: 'Art Rooms',
      category: 'Workshop/Gallery',
      lat: 35.333593,
      lng: 33.330680,
      note:
          'No separate Google Places record; using same coord as Age of Bronze',
      verified: true),
  Poi(
      name: 'Iris (Atelier Building)',
      category: 'Workshop',
      lat: 35.333593,
      lng: 33.330680,
      note: 'Part of workshop cluster; verify on site',
      verified: true),
];
