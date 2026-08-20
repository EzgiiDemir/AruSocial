import 'package:geolocator/geolocator.dart';

import '../config/campus_sites.dart';

/// A real, GPS-based "are you actually on campus" check. The specific
/// business rule for what should change when a student is off-campus
/// hasn't been finalized (per the product brief this app is built from),
/// so this deliberately stays a single configurable radius + an
/// informational signal rather than a hardcoded feature-blocking policy —
/// screens can read [isNearAnyCampus] and decide what, if anything, to
/// restrict once that rule exists.
class CampusAccessPolicy {
  /// How close (meters) to any real campus counts as "on campus". A real
  /// deployment would tune this per-campus and likely make it admin
  /// configurable rather than a compile-time constant.
  static const onCampusRadiusMeters = 3000.0;

  static bool isNearAnyCampus(double lat, double lng) {
    for (final site in campusSites) {
      final meters = Geolocator.distanceBetween(lat, lng, site.lat, site.lng);
      if (meters <= onCampusRadiusMeters) return true;
    }
    return false;
  }
}
