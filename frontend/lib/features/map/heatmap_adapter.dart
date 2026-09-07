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
  static List<CampusPulseZone> zonesFromOccupancy(List<Map<String, dynamic>> data) {
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
      final radius = 10 + occ * 3; // meters
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

  /// Heat zones from actual last-two-hour check-ins returned by `/places`.
  /// A place with no recent activity still has its real POI marker, but does
  /// not receive an invented glow or a forced crowd colour.
  static List<CampusPulseZone> pulseZones(List<CampusPlace> places) {
    final zones = <CampusPulseZone>[];
    for (final place in places) {
      if (place.lat.abs() < 0.01 || place.lng.abs() < 0.01) continue;
      if (place.recentCheckins <= 0) continue;
      final normalized =
          (place.recentCheckins / 5).clamp(0.2, 1.0).toDouble();
      zones.add(CampusPulseZone(
        center: GeoPoint(place.lat, place.lng),
        baseRadiusMeters:
            10 + (place.recentCheckins.clamp(1, 8) * 2).toDouble(),
        color: campusDensityInfo(place).$1,
        intensity: normalized,
      ));
    }
    return zones;
  }
}
