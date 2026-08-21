import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/map/maplibre_campus_map.dart';

/// Heatmap adapter: turns live/derived crowd data into [CampusPulseZone]s
/// for our own map's painter to draw.
class HeatmapAdapter {
  /// Convert an occupancy list to pulse zones as a fallback.
  ///
  /// This needs a live occupancy service (`http://localhost:3333/occupancy`
  /// in this prototype) to actually return data — on a normal device with
  /// no such service reachable it silently returns nothing, which is why
  /// [pulseCircles] below exists as a real, always-available fallback.
  static List<CampusPulseZone> zonesFromOccupancy(List<Map<String, dynamic>> data) {
    final zones = <CampusPulseZone>[];
    for (final item in data) {
      final lat = (item['lat'] as num).toDouble();
      final lng = (item['lng'] as num).toDouble();
      final occ = (item['occupancy'] as num).toDouble();
      final radius = 10 + occ * 3; // meters
      final t = (occ / 100).clamp(0.0, 1.0);
      final color = Color.lerp(ArucadColors.success, ArucadColors.danger, t) ?? ArucadColors.danger;
      zones.add(CampusPulseZone(
        center: GeoPoint(lat, lng),
        baseRadiusMeters: radius,
        color: color,
        intensity: t,
      ));
    }
    return zones;
  }

  /// A real "Campus Pulse" the map can always draw — derived straight from
  /// each place's own [CampusPlace.density] (the same value already shown
  /// as a label on its card/detail screen), turned into a soft red/yellow/
  /// green glow instead of a live sensor feed we don't actually have.
  static List<CampusPulseZone> pulseZones(List<CampusPlace> places) {
    final zones = <CampusPulseZone>[];
    for (final place in places) {
      final raw = place.density.toLowerCase();
      final Color color;
      final double intensity;
      if (raw.contains('busy') || raw.contains('high')) {
        color = ArucadColors.danger;
        intensity = 1.0;
      } else if (raw.contains('moderate')) {
        color = ArucadColors.warning;
        intensity = 0.6;
      } else if (raw.contains('quiet')) {
        color = ArucadColors.success;
        intensity = 0.3;
      } else {
        continue; // Unknown density — no invented color/glow for it.
      }
      // Kept deliberately small — real campus POIs sit as close as 20-30m
      // apart, so anything bigger than this just merges into one blob and
      // stops being readable as "this specific place is busy."
      zones.add(CampusPulseZone(
        center: GeoPoint(place.lat, place.lng),
        baseRadiusMeters: 7 + intensity * 8,
        color: color,
        intensity: intensity,
      ));
    }
    return zones;
  }

  /// The real thing (docs/EKSIKLER.md harita/heatmap): pulse zones driven
  /// by [CampusRepository.getPlaceDensity]'s real, time-windowed check-in
  /// counts instead of [pulseZones]'s static seeded density string. Same
  /// color/intensity/size mapping so the visual language doesn't change,
  /// just what decides it.
  static List<CampusPulseZone> pulseZonesFromDensity(
      List<CampusPlace> places, List<PlaceDensity> density) {
    final levelByPlaceId = {for (final d in density) d.placeId: d.level};
    final zones = <CampusPulseZone>[];
    for (final place in places) {
      final level = levelByPlaceId[place.id];
      final Color color;
      final double intensity;
      switch (level) {
        case 'busy':
          color = ArucadColors.danger;
          intensity = 1.0;
        case 'moderate':
          color = ArucadColors.warning;
          intensity = 0.6;
        case 'quiet':
          color = ArucadColors.success;
          intensity = 0.3;
        default:
          continue; // No real density data for this place yet.
      }
      zones.add(CampusPulseZone(
        center: GeoPoint(place.lat, place.lng),
        baseRadiusMeters: 7 + intensity * 8,
        color: color,
        intensity: intensity,
      ));
    }
    return zones;
  }
}
