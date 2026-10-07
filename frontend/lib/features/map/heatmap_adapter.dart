import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/core/theme/campus_density.dart';
import 'package:arucad_campus_prototype/features/map/maplibre_campus_map.dart';

/// Heatmap adapter: turns live/derived crowd data into [CampusPulseZone]s
/// for our own map's painter to draw.
class HeatmapAdapter {
  /// Convert an occupancy list to pulse zones. Unused by the live map — crowd
  /// glow comes from [pulseZones] (check-ins / density). Kept for tests and
  /// any future occupancy feed that actually exists.
  static List<CampusPulseZone> zonesFromOccupancy(
      List<Map<String, dynamic>> data) {
    final zones = <CampusPulseZone>[];
    for (final item in data) {
      final latRaw = item['lat'];
      final lngRaw = item['lng'];
      final occRaw = item['occupancy'];
      if (latRaw is! num || lngRaw is! num || occRaw is! num) continue;
      final lat = latRaw.toDouble();
      final lng = lngRaw.toDouble();
      if (lat.abs() < 0.01 || lng.abs() < 0.01) continue;
      final occ = occRaw.toDouble();
      // Keep input radii bounded. Rendering applies its own zoom-aware pixel
      // scaling, so large occupancy values must not create city-sized blobs.
      final radius = 7 + (occ.clamp(0, 100) / 16.7);
      final t = (occ / 100).clamp(0.0, 1.0);
      final color = t < 0.34
          ? ArucadColors.campusGreen
          : t < 0.67
              ? ArucadColors.yellow
              : ArucadColors.danger;
      zones.add(CampusPulseZone(
        center: GeoPoint(lat, lng),
        baseRadiusMeters: radius,
        color: color,
        intensity: t,
      ));
    }
    return zones;
  }

  /// Heat zones for the campus glow.
  ///
  /// [live] is the head count per place from real location pings
  /// (`GET /presence/live`) and is preferred wherever it has a number: a
  /// check-in is a deliberate act most students never perform, so a glow
  /// built only from check-ins showed the buildings where people post,
  /// not the buildings where people are. A place absent from [live] falls
  /// back to its last-two-hour check-ins, and a place with neither still
  /// gets its real POI marker but no invented glow.
  static List<CampusPulseZone> pulseZones(
    List<CampusPlace> places, {
    Map<String, int> live = const {},
  }) {
    final zones = <CampusPulseZone>[];
    for (final place in places) {
      if (place.lat.abs() < 0.01 || place.lng.abs() < 0.01) continue;
      final here = live[place.id] ?? place.recentCheckins;
      if (here <= 0) continue;
      zones.add(CampusPulseZone(
        center: GeoPoint(place.lat, place.lng),
        baseRadiusMeters: 7 + (here.clamp(1, 8) * 0.75).toDouble(),
        color: crowdColorForCount(here),
        intensity: (here / 5).clamp(0.2, 1.0).toDouble(),
      ));
    }
    return zones;
  }
}
