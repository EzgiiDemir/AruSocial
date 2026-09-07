import 'package:geolocator/geolocator.dart';

import 'campus_sites.dart';
import 'poi_config.dart';
import '../models/campus_models.dart';
import '../models/geo_point.dart';

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
  'ı': 'i',
  'İ': 'i',
  'ğ': 'g',
  'Ğ': 'g',
  'ü': 'u',
  'Ü': 'u',
  'ş': 's',
  'Ş': 's',
  'ö': 'o',
  'Ö': 'o',
  'ç': 'c',
  'Ç': 'c',
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
  final curatedCoords = curated.map((p) => _coordKey(p.lat, p.lng)).toSet();
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
      description: (poi.note != null && poi.note!.trim().isNotEmpty)
          ? poi.note!
          : _descriptionFor(category, poi),
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

Poi poiFromPlace(CampusPlace place) => Poi(
      name: place.name,
      category: place.category,
      lat: place.lat,
      lng: place.lng,
      note: place.description.trim().isEmpty ? null : place.description.trim(),
    );

/// The 19 named locations from `sql/0002_real_campus_data.sql` (entrance /
/// cluster aliases are extra and not in this list).
const sqlCampusPoiNames = <String>[
  'Rodin',
  'Falling Man',
  'Titan',
  'Eve',
  'Daniele',
  'Eternal Spring',
  'Meditation',
  'Minotaur',
  'Eternal Idol',
  'The Kiss',
  'The Garden',
  'Carpentry Studio',
  'Arkin Rodin Collection Gallery',
  'ARUCAD Dormitory',
  'Nicosia Bandabuliya Campus',
  'ARUCAD Art Space',
  'Age of Bronze',
  'Art Rooms',
  'Iris (Atelier Building)',
];

CampusPlace campusPlaceFromPoi(Poi poi) {
  final site = nearestSite(poi.lat, poi.lng);
  final meters =
      Geolocator.distanceBetween(site.lat, site.lng, poi.lat, poi.lng);
  final category = normalizeCategory(poi.category);
  return CampusPlace(
    id: slugify(poi.name),
    name: poi.name,
    category: category,
    lat: poi.lat,
    lng: poi.lng,
    description: (poi.note != null && poi.note!.trim().isNotEmpty)
        ? poi.note!
        : _descriptionFor(category, poi),
    distance: meters < 30 ? site.name : '${meters.round()} m · ${site.name}',
    density: 'quiet',
    street: site.name,
    tourUrl: site.tourUrl,
    accessible: true,
    photos: 0,
    rating: 0,
  );
}

String _mapNameKey(String name) => name
    .replaceAll(RegExp(r'[\u200B-\u200D\uFEFF]'), '')
    .trim()
    .toLowerCase()
    .replaceAll(RegExp(r'\s+'), ' ');

/// Student-facing place list: drop legacy `place-*` aliases and collapse
/// duplicate display names (e.g. The Garden seeded thrice).
List<CampusPlace> uniqueCampusPlaces(List<CampusPlace> fromApi) {
  final preferred = <String, CampusPlace>{};
  final fallback = <String, CampusPlace>{};
  for (final place in fromApi) {
    final key = _mapNameKey(place.name);
    if (place.id.startsWith('place-')) {
      fallback.putIfAbsent(key, () => place);
    } else {
      preferred.putIfAbsent(key, () => place);
    }
  }
  for (final entry in fallback.entries) {
    preferred.putIfAbsent(entry.key, () => entry.value);
  }
  final list = preferred.values.toList()
    ..sort((a, b) => a.name.toLowerCase().compareTo(b.name.toLowerCase()));
  return list;
}

/// Pins the live map actually draws: API/mock places plus any catalog POI
/// the backend list is missing, collapsing legacy `place-*` aliases so the
/// same building is not labelled twice.
List<CampusPlace> campusMapPlaces(List<CampusPlace> fromApi) {
  final byName = <String, CampusPlace>{};
  for (final place in uniqueCampusPlaces(fromApi)) {
    byName[_mapNameKey(place.name)] = place;
  }
  for (final poi in pois) {
    byName.putIfAbsent(_mapNameKey(poi.name), () => campusPlaceFromPoi(poi));
  }
  var list = byName.values.toList();
  final names = list.map((p) => p.name).toSet();
  if (names.contains('Age of Bronze') &&
      names.contains('Art Rooms') &&
      names.contains('Iris (Atelier Building)')) {
    list = list.where((p) => p.name != 'ARUCAD Workshops').toList();
  }
  return list;
}

class CampusMapPin {
  final CampusPlace place;
  final GeoPoint display;
  const CampusMapPin({required this.place, required this.display});
}

/// Workshop cluster (and any other shared coordinate) keeps stored lat/lng
/// for navigation; labels are nudged a few metres so names stay tappable.
List<CampusMapPin> spreadOverlappingMapPins(List<CampusPlace> places) {
  final groups = <String, List<CampusPlace>>{};
  for (final place in places) {
    final key = _coordKey(place.lat, place.lng);
    groups.putIfAbsent(key, () => []).add(place);
  }
  final pins = <CampusMapPin>[];
  for (final group in groups.values) {
    if (group.length == 1) {
      final place = group.first;
      pins.add(
          CampusMapPin(place: place, display: GeoPoint(place.lat, place.lng)));
      continue;
    }
    for (var i = 0; i < group.length; i++) {
      final place = group[i];
      final offset = (i - (group.length - 1) / 2) * 0.00009;
      pins.add(CampusMapPin(
        place: place,
        display: GeoPoint(place.lat, place.lng + offset),
      ));
    }
  }
  return pins;
}

/// First camera should read the Girne building names, not zoom to Lefkoşa.
List<GeoPoint> mainCampusCameraExtent(List<CampusPlace> places) {
  final cluster = places
      .where((p) =>
          p.lat >= 35.3369 &&
          p.lat <= 35.33805 &&
          p.lng >= 33.3199 &&
          p.lng <= 33.32185)
      .toList();
  if (cluster.length >= 6) {
    return cluster.map((p) => GeoPoint(p.lat, p.lng)).toList();
  }
  final girne =
      places.where((p) => p.lat >= 35.328 && p.lat <= 35.342).toList();
  if (girne.isNotEmpty) {
    return girne.map((p) => GeoPoint(p.lat, p.lng)).toList();
  }
  if (places.isEmpty) return const [GeoPoint(35.33715, 33.32135)];
  return places.map((p) => GeoPoint(p.lat, p.lng)).toList();
}
