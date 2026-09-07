import 'campus_sites.dart';
import '../models/campus_models.dart';

/// Resolved 360° export URL plus optional scene/hotspot target for [place].
({String url, String? target}) resolvePlaceTour(CampusPlace place) {
  return (
    url: resolvePlaceTourUrl(
      lat: place.lat,
      lng: place.lng,
      stored: place.tourUrl,
    ),
    target: place.tourTarget,
  );
}

/// Case-insensitive name contains match against repository places.
CampusPlace? placeMatchingLocationTag(
  Iterable<CampusPlace> places,
  String? locationTag,
) {
  final tag = locationTag?.trim().toLowerCase();
  if (tag == null || tag.isEmpty) return null;
  for (final place in places) {
    final name = place.name.trim().toLowerCase();
    if (name.isEmpty) continue;
    if (name == tag || name.contains(tag) || tag.contains(name)) {
      return place;
    }
  }
  return null;
}
