import 'package:geolocator/geolocator.dart';

import 'campus_sites.dart';
import 'poi_config.dart';
import '../models/campus_models.dart';

/// Collapses the raw Google-Places / calculated-on-map categories in
/// [poi_config.dart] down to a small, student-facing taxonomy so Explore's
/// filter chips stay usable instead of listing a dozen near-duplicates
/// ("Workshop", "Workshops", "Workshop/Gallery", ...).
String normalizeCategory(String raw) {
  switch (raw) {
    case 'Entrance':
      return 'Giriş';
    case 'Workshop':
    case 'Workshops':
    case 'Workshop/Gallery':
      return 'Atölye';
    case 'Gallery':
    case 'Art':
      return 'Galeri';
    case 'Library':
      return 'Kütüphane';
    case 'Social':
      return 'Sosyal Alan';
    case 'Accommodation':
      return 'Konaklama';
    case 'Administration':
    case 'Admin+Academic':
    case 'Support':
    case 'Marketing':
      return 'İdari';
    case 'Academic':
    case 'Education':
      return 'Akademik';
    case 'Studio':
      return 'Stüdyo';
    case 'Performance':
      return 'Sahne';
    case 'Outdoor':
      return 'Sosyal Alan';
    case 'Study':
      return 'Kütüphane';
    case 'Campus':
      return 'Kampüs';
    default:
      return raw;
  }
}

String _descriptionFor(String normalizedCategory, Poi poi) {
  switch (normalizedCategory) {
    case 'Giriş':
      return 'Kampüsün ana giriş noktası.';
    case 'Atölye':
      return 'Üretim ve atölye çalışmaları için kullanılan alan.';
    case 'Galeri':
      return 'Sergi ve galeri alanı.';
    case 'Kütüphane':
      return 'Sessiz çalışma ve kaynak alanı.';
    case 'Sosyal Alan':
      return 'Ders arası mola ve sosyal buluşma alanı.';
    case 'Konaklama':
      return 'Öğrenci yurdu.';
    case 'İdari':
      return 'İdari / destek birimi.';
    case 'Akademik':
      return 'Akademik birim.';
    case 'Stüdyo':
      return 'Sanat / tasarım stüdyosu.';
    case 'Sahne':
      return 'Gösteri ve etkinlik sahnesi.';
    case 'Kampüs':
      return 'ARUCAD kampüs yerleşkesi.';
    default:
      return poi.note ?? 'Kampüs içinde bir alan.';
  }
}

const _turkishMap = {
  'ı': 'i', 'İ': 'i', 'ğ': 'g', 'Ğ': 'g', 'ü': 'u', 'Ü': 'u',
  'ş': 's', 'Ş': 's', 'ö': 'o', 'Ö': 'o', 'ç': 'c', 'Ç': 'c',
};

String slugify(String name) {
  var out = name;
  _turkishMap.forEach((k, v) => out = out.replaceAll(k, v));
  out = out.toLowerCase().replaceAll(RegExp(r'[^a-z0-9]+'), '-');
  return out.replaceAll(RegExp(r'^-+|-+$'), '');
}

String _coordKey(double lat, double lng) =>
    '${lat.toStringAsFixed(5)},${lng.toStringAsFixed(5)}';

/// Every real, verified/calculated ARUCAD location from [pois] that isn't
/// already represented by one of the [curated] hand-authored places (they
/// share exact coordinates — e.g. "Atelier" *is* "Carpentry Studio" — so we
/// don't list the same pin twice under two names).
List<CampusPlace> placesFromPois(List<CampusPlace> curated) {
  final curatedCoords =
      curated.map((p) => _coordKey(p.lat, p.lng)).toSet();
  final generated = <CampusPlace>[];
  for (final poi in pois) {
    final key = _coordKey(poi.lat, poi.lng);
    if (curatedCoords.contains(key)) continue;
    final site = nearestSite(poi.lat, poi.lng);
    final meters =
        Geolocator.distanceBetween(site.lat, site.lng, poi.lat, poi.lng);
    final category = normalizeCategory(poi.category);
    generated.add(CampusPlace(
      id: slugify(poi.name),
      name: poi.name,
      category: category,
      lat: poi.lat,
      lng: poi.lng,
      description: _descriptionFor(category, poi),
      distance: meters < 30 ? site.name : '${meters.round()} m · ${site.name}',
      density: 'Bilinmiyor',
      street: site.name,
      tourUrl: site.tourUrl,
      accessible: true,
      photos: 0,
      rating: 0,
    ));
  }
  return generated;
}
