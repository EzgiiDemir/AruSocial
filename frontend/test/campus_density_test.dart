import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/core/theme/campus_density.dart';
import 'package:arucad_campus_prototype/features/map/heatmap_adapter.dart';

CampusPlace _place(String id, String density) => CampusPlace(
      id: id,
      name: id,
      category: 'Test',
      lat: 35.337,
      lng: 33.321,
      description: '',
      distance: '',
      density: density,
      street: '',
      tourUrl: null,
      accessible: true,
      photos: 0,
      rating: 0,
    );

void main() {
  test('crowd colours are red / yellow / campus green, never lilac', () {
    expect(campusDensityInfo(_place('a', 'busy')).$1, ArucadColors.danger);
    expect(campusDensityInfo(_place('a', 'High activity')).$2, 'Yoğun');
    expect(campusDensityInfo(_place('b', 'moderate')).$1, ArucadColors.yellow);
    expect(campusDensityInfo(_place('b', 'Orta')).$2, 'Orta yoğunluk');
    expect(campusDensityInfo(_place('c', 'quiet')).$1, ArucadColors.campusGreen);
    expect(campusDensityInfo(_place('c', 'Sakin')).$2, 'Sakin');
  });

  test('presence count never invents activity from a density label', () {
    expect(campusPresenceCount(_place('busy', 'busy')), 0);
    expect(campusPresenceCount(_place('quiet', 'quiet')), 0);
    expect(campusPresenceCount(_place('real', 'busy').copyWith(recentCheckins: 7)), 7);
    expect(campusCrowdLevel('Orta yoğunluk'), CampusCrowd.moderate);
  });

  test('map glows only for places with measured recent activity', () {
    final zones = HeatmapAdapter.pulseZones([
      _place('quiet', 'quiet'),
      _place('stale', 'busy'),
      _place('real', 'moderate').copyWith(recentCheckins: 3),
    ]);
    expect(zones, hasLength(1));
    expect(zones.single.color, ArucadColors.yellow);
    expect(zones.single.intensity, closeTo(0.6, 0.001));
  });

  test('the glow follows where people actually are, not who checked in', () {
    // "busy" here comes from three people physically at the place; the
    // check-in count for the same place is one. A glow built from the
    // check-in would have called it quiet green.
    final zones = HeatmapAdapter.pulseZones(
      [_place('gallery', 'quiet').copyWith(recentCheckins: 1)],
      live: {'gallery': 6},
    );

    expect(zones.single.color, ArucadColors.danger);
    expect(zones.single.intensity, 1.0);
  });

  test('a place outside the live snapshot still glows from its check-ins',
      () {
    final zones = HeatmapAdapter.pulseZones(
      [_place('library', 'moderate').copyWith(recentCheckins: 3)],
      live: const {'gallery': 6},
    );

    expect(zones.single.color, ArucadColors.yellow);
  });

  test('crowd bands from a head count match the backend thresholds', () {
    expect(campusCrowdForCount(0), CampusCrowd.unknown);
    expect(campusCrowdForCount(1), CampusCrowd.quiet);
    expect(campusCrowdForCount(2), CampusCrowd.moderate);
    expect(campusCrowdForCount(5), CampusCrowd.busy);
    expect(crowdColorForCount(5), ArucadColors.danger);
    expect(crowdColorForCount(1), ArucadColors.campusGreen);
  });
}
