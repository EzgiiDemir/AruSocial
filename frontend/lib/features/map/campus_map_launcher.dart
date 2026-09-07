import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/features/home/campus_live_map.dart';

/// Single entry into the campus map hub — Home / Explore / directory all
/// deep-link here instead of embedding separate MapLibre surfaces.
Future<void> openCampusMapHub(
  BuildContext context, {
  required List<CampusPlace> places,
  required List<CampusEvent> events,
  required CampusRepository repository,
  required MapProvider mapProvider,
  required AnalyticsTracker analyticsTracker,
  required VoidCallback onOpenGalatea,
  CampusVisibility initialVisibility = CampusVisibility.friends,
  String? focusPlaceId,
}) {
  return Navigator.of(context).push<void>(MaterialPageRoute(
    builder: (_) => CampusMapFullScreen(
      places: places,
      events: events,
      repository: repository,
      mapProvider: mapProvider,
      analyticsTracker: analyticsTracker,
      onOpenGalatea: onOpenGalatea,
      initialVisibility: initialVisibility,
      focusPlaceId: focusPlaceId,
    ),
  ));
}
