/// Curated ARUCAD POI coordinates — source: `sql/0002_real_campus_data.sql`
///   [verified]   = Google Places API
///   [calculated] = campus map relative layout (±15–20 m)
///
/// Mock-mode seed via `placesFromPois()`; REST mode uses `GET /places`.
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
  Poi(
      name: 'Ana kampüs girişi',
      category: 'Entrance',
      lat: 35.337395,
      lng: 33.321358,
      note: 'Şair Nedim Sokak No:11, Girne — ana kampüs girişi.',
      verified: true),
  Poi(
      name: 'Arkin Rodin Collection Gallery',
      category: 'Gallery',
      lat: 35.337925,
      lng: 33.320036,
      verified: true,
      note:
          'ARUCAD\'ın kendi Rodin heykel koleksiyonunun sergilendiği, halka açık galeri.'),
  Poi(
      name: 'ARUCAD Dormitory',
      category: 'Accommodation',
      lat: 35.331328,
      lng: 33.318916,
      note: 'Öğrenci yurdu.',
      verified: true),
  Poi(
      name: 'ARUCAD Workshops',
      category: 'Workshops',
      lat: 35.333593,
      lng: 33.330680,
      note: 'İskenderun Caddesi workshop kümesi (Age of Bronze / Art Rooms / Iris).',
      verified: true),
  Poi(
      name: 'Nicosia Bandabuliya Campus',
      category: 'Campus',
      lat: 35.175513,
      lng: 33.365029,
      note:
          'Lefkoşa\'daki ikinci kampüs — Müzik ve Sahne Sanatları Fakültesi, Blackbox Sahne, Bandabuliya Cafe.',
      verified: true),
  Poi(
      name: 'ARUCAD Art Space',
      category: 'Gallery',
      lat: 35.177726,
      lng: 33.360248,
      note: 'Lefkoşa\'da sanat galerisi mekânı.',
      verified: true),

  // Main campus — calculated from map (±15–20 m); campus center verified
  Poi(
      name: 'Rodin',
      category: 'Administration',
      lat: 35.337305,
      lng: 33.321303,
      note:
          'Bina, ARUCAD adını taşıyan Fransız heykeltıraş Auguste Rodin\'e ithaf edilmiş. Rektörlük ve Sanat/Tasarım fakülte dekanlıkları burada.'),
  Poi(
      name: 'Falling Man',
      category: 'Academic',
      lat: 35.337305,
      lng: 33.321027,
      note:
          'Görsel İletişim, Endüstriyel Tasarım, Seramik ve Yeni Medya bölüm başkanlıkları burada.'),
  Poi(
      name: 'Titan',
      category: 'Admin+Academic',
      lat: 35.337170,
      lng: 33.321633,
      note:
          'Öğrenci İşleri, Bilgi İşlem, Mimarlık, Dijital Oyun Tasarımı ve Arkeoloji bölüm başkanlıkları — kampüsün en yoğun idari binası.'),
  Poi(
      name: 'Eve',
      category: 'Education',
      lat: 35.337529,
      lng: 33.321303,
      note:
          '"Eve", Rodin\'in en tanınan kadın nü heykellerinden biri. İngilizce Hazırlık Okulu burada.'),
  Poi(
      name: 'Daniele',
      category: 'Studio',
      lat: 35.337772,
      lng: 33.321688,
      note:
          'İsim Rodin\'in "Danaïde" figürüne bir gönderme. Film, fotoğraf, iç mimarlık stüdyoları ve MAC Lab burada.'),
  Poi(
      name: 'Eternal Spring',
      category: 'Studio',
      lat: 35.337844,
      lng: 33.321468,
      note:
          'Rodin\'in "Ebedi Bahar" heykelinden adını alır. Dijital baskı atölyesi burada.'),
  Poi(
      name: 'Meditation',
      category: 'Library',
      lat: 35.337754,
      lng: 33.321358,
      note:
          'Rodin\'in içe dönük "İç Ses" figürüne selam veren isim — kütüphane, dijital kütüphane ve konferans salonu burada.'),
  Poi(
      name: 'Minotaur',
      category: 'Support',
      lat: 35.337844,
      lng: 33.321270,
      note:
          'Güvenlik, Psikolojik Danışmanlık ve Rehberlik Merkezi, Kampüs Koordinatörlüğü burada.'),
  Poi(
      name: 'Eternal Idol',
      category: 'Marketing',
      lat: 35.337889,
      lng: 33.321193,
      note:
          '"Ebedi Put" — Rodin\'in en duygusal eserlerinden biri. Uluslararası pazarlama ve kurumsal iletişim ofisleri burada.'),
  Poi(
      name: 'The Kiss',
      category: 'Art',
      lat: 35.337799,
      lng: 33.321082,
      note:
          'Rodin\'in dünyaca en ünlü heykeli "Öpücük". Performans stüdyosu, ARUCAD Galerisi ve Sağlık Merkezi burada.'),
  Poi(
      name: 'The Garden',
      category: 'Social',
      lat: 35.337125,
      lng: 33.320972,
      note: 'Kampüsün açık sosyal alanı.'),
  Poi(
      name: 'Carpentry Studio',
      category: 'Workshop',
      lat: 35.337502,
      lng: 33.321226,
      note: 'Marangozluk atölyesi.'),

  Poi(
      name: 'Age of Bronze',
      category: 'Workshop',
      lat: 35.333593,
      lng: 33.330680,
      note:
          'Heykel stüdyosu ve disiplinlerarası atölyeler. Koordinat "ARUCAD WORKSHOPS" (Google Places).',
      verified: true),
  Poi(
      name: 'Art Rooms',
      category: 'Workshop/Gallery',
      lat: 35.333593,
      lng: 33.330680,
      note: 'Haritada Age of Bronze ile aynı adres — aynı koordinat kullanıldı.',
      verified: true),
  Poi(
      name: 'Iris (Atelier Building)',
      category: 'Workshop',
      lat: 35.333593,
      lng: 33.330680,
      note:
          'Seramik, cam üfleme, döküm atölyeleri. Workshop cluster ile aynı koordinat — sahada teyit şart.',
      verified: true),
];
