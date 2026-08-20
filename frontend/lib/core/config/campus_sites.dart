import 'poi_config.dart';

/// One of ARUCAD's three physical locations. Coordinates match the verified
/// entries in [pois] (main entrance, Nicosia Bandabuliya, the Iris/Age of
/// Bronze workshop cluster) — the same source of truth the live map uses.
class CampusSite {
  final String id;
  final String name;
  final String description;
  final String tourUrl;
  final double lat;
  final double lng;

  const CampusSite({
    required this.id,
    required this.name,
    required this.description,
    required this.tourUrl,
    required this.lat,
    required this.lng,
  });
}

const campusSites = <CampusSite>[
  CampusSite(
    id: 'main',
    name: 'ARUCAD Kyrenia Kampüsü',
    description:
        'Ana kampüs — atölyeler, galeri, kütüphane, sahne, yurt ve sosyal alanların tamamı burada.',
    tourUrl: 'https://360.arucad.edu.tr/tour?campusId=main',
    lat: 35.337395,
    lng: 33.321358,
  ),
  CampusSite(
    id: 'bandabuliya',
    name: 'Bandabuliya Kampüsü (Lefkoşa)',
    description:
        'Lefkoşa\'daki tarihi çarşı binasında yer alan sanat mekânı ve sergi alanı.',
    tourUrl: 'https://360.arucad.edu.tr/tour?campusId=bandabuliya',
    lat: 35.175513,
    lng: 33.365029,
  ),
  CampusSite(
    id: 'atelier',
    name: 'Atölye Binası (Iris)',
    description:
        'Age of Bronze / Iris atölye kümesi — üretim, workshop ve sanat odaları.',
    tourUrl: 'https://360.arucad.edu.tr/tour?campusId=atelier',
    lat: 35.333593,
    lng: 33.330680,
  ),
];

double _distanceSquared(double lat1, double lng1, double lat2, double lng2) {
  final dLat = lat1 - lat2;
  final dLng = lng1 - lng2;
  return dLat * dLat + dLng * dLng;
}

/// Whichever of the three sites' coordinates a point sits closest to — used
/// to group [pois] under a campus without needing a per-poi campus field.
CampusSite nearestSite(double lat, double lng) {
  var best = campusSites.first;
  var bestDist = _distanceSquared(lat, lng, best.lat, best.lng);
  for (final site in campusSites.skip(1)) {
    final dist = _distanceSquared(lat, lng, site.lat, site.lng);
    if (dist < bestDist) {
      bestDist = dist;
      best = site;
    }
  }
  return best;
}

List<Poi> poisForSite(String siteId) =>
    pois.where((p) => nearestSite(p.lat, p.lng).id == siteId).toList();
