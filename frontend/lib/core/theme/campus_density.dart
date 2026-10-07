import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// Live crowd bands. Same three colours on Home pulse, Explore cards,
/// and the map glow — never lilac, never inverted traffic lights.
enum CampusCrowd { busy, moderate, quiet, unknown }

CampusCrowd campusCrowdLevel(String density) {
  final raw = density.toLowerCase();
  if (raw.contains('moderate') || raw.contains('orta')) {
    return CampusCrowd.moderate;
  }
  if (raw.contains('busy') ||
      raw.contains('high') ||
      raw.contains('yoğun') ||
      raw.contains('yogun')) {
    return CampusCrowd.busy;
  }
  if (raw.contains('quiet') || raw.contains('sakin') || raw.contains('low')) {
    return CampusCrowd.quiet;
  }
  return CampusCrowd.unknown;
}

/// 🔴 busy · 🟡 moderate · 🟢 calm
(Color, String) campusDensityInfo(CampusPlace? place) {
  switch (campusCrowdLevel(place?.density ?? '')) {
    case CampusCrowd.busy:
      return (ArucadColors.danger, 'Yoğun');
    case CampusCrowd.moderate:
      return (ArucadColors.yellow, 'Orta yoğunluk');
    case CampusCrowd.quiet:
      return (ArucadColors.campusGreen, 'Sakin');
    case CampusCrowd.unknown:
      return (ArucadColors.slate, 'Bilinmiyor');
  }
}

/// Yellow needs dark type; red/green stay on their own hue.
Color densityForeground(Color fill) =>
    fill.computeLuminance() > 0.55 ? ArucadColors.ink : fill;

/// Actual recent check-ins; a density label is not a measured head count.
int campusPresenceCount(CampusPlace place) =>
    place.recentCheckins < 0 ? 0 : place.recentCheckins;

/// The three crowd bands applied to a raw head count, matching the
/// thresholds the backend uses to derive a place's density string
/// (`App\Services\PlacePresence::densityFor`). One rule, so a live count
/// and a density label can never disagree about what "busy" means.
CampusCrowd campusCrowdForCount(int people) {
  if (people >= 5) return CampusCrowd.busy;
  if (people >= 2) return CampusCrowd.moderate;
  if (people >= 1) return CampusCrowd.quiet;
  return CampusCrowd.unknown;
}

Color crowdColorForCount(int people) => switch (campusCrowdForCount(people)) {
      CampusCrowd.busy => ArucadColors.danger,
      CampusCrowd.moderate => ArucadColors.yellow,
      CampusCrowd.quiet => ArucadColors.campusGreen,
      CampusCrowd.unknown => ArucadColors.slate,
    };

