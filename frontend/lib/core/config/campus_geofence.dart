import 'campus_sites.dart';

/// Client copy of the server campus envelopes (`App\Support\CampusGeofence`).
/// Backend still rejects off-campus check-ins; this is for immediate UX.
class CampusGeofence {
  static const halfLat = 0.0055;
  static const halfLng = 0.0065;

  static bool contains(double lat, double lng) {
    for (final site in campusSites) {
      if (_pointInBox(lat, lng, site.lat, site.lng)) return true;
    }
    return false;
  }

  static bool _pointInBox(
      double lat, double lng, double centerLat, double centerLng) {
    return lat <= centerLat + halfLat &&
        lat >= centerLat - halfLat &&
        lng <= centerLng + halfLng &&
        lng >= centerLng - halfLng;
  }
}
