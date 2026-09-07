import 'poi_config.dart';

/// One of ARUCAD's three physical locations. Coordinates match the verified
/// entries in [pois] (main entrance, Nicosia Bandabuliya, the Iris/Age of
/// Bronze workshop cluster). Live map markers themselves come from
/// `GET /places`; this file is geofencing / map-extent / tour URLs.
///
/// 360 links are the published 3DVista exports on 360.arucad.edu.tr
/// (`/vista_export/.../index.htm`). The `/tour?campusId=` paths do not open
/// a scene.
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

const arucad360MainTourUrl =
    'https://360.arucad.edu.tr/vista_export/Main/index.htm';
const arucad360BandabuliyaTourUrl =
    'https://360.arucad.edu.tr/vista_export/Bandabuliya/index.htm';
const arucad360AtelierTourUrl =
    'https://360.arucad.edu.tr/vista_export/Atelier/index.htm';

const campusSites = <CampusSite>[
  CampusSite(
    id: 'main',
    name: 'ARUCAD Kyrenia Kampüsü',
    description:
        'Ana kampüs — atölyeler, galeri, kütüphane, sahne, yurt ve sosyal alanların tamamı burada.',
    tourUrl: arucad360MainTourUrl,
    lat: 35.337395,
    lng: 33.321358,
  ),
  CampusSite(
    id: 'bandabuliya',
    name: 'Bandabuliya Kampüsü (Lefkoşa)',
    description:
        'Lefkoşa\'daki tarihi çarşı binasında yer alan sanat mekânı ve sergi alanı.',
    tourUrl: arucad360BandabuliyaTourUrl,
    lat: 35.175513,
    lng: 33.365029,
  ),
  CampusSite(
    id: 'atelier',
    name: 'Atölye Binası (Iris)',
    description:
        'Age of Bronze / Iris atölye kümesi — üretim, workshop ve sanat odaları.',
    tourUrl: arucad360AtelierTourUrl,
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

/// Prefer a stored ARUCAD 360 URL; otherwise the nearest campus tour.
/// Legacy `/tour?campusId=` paths are ignored — they never opened a scene.
bool isUsablePlaceTourUrl(String? stored) {
  if (stored == null) return false;
  final s = stored.trim();
  if (s.isEmpty) return false;
  if (s.contains('vista_export')) return true;
  if (s.contains('360.arucad.edu.tr') && !s.contains('/tour?campusId=')) {
    return true;
  }
  return false;
}

String resolvePlaceTourUrl(
    {required double lat, required double lng, String? stored}) {
  if (isUsablePlaceTourUrl(stored)) return stored!.trim();
  return nearestSite(lat, lng).tourUrl;
}
