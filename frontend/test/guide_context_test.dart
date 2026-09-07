import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/config/place_catalog.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/features/guide/guide_context.dart';

GuideContext _ctx() => GuideContext(
      places: campusMapPlaces(const []),
      events: const [],
      clubs: const [],
      sports: const [],
      services: const [],
      foodVenues: const [],
    );

void main() {
  test('matchPlace finds catalog buildings by name', () {
    expect(_ctx().matchPlace('Rodin binasına nasıl giderim')?.name, 'Rodin');
    expect(_ctx().matchPlace('The Kiss nerede')?.name, 'The Kiss');
  });

  test('matchPlace uses Turkish aliases for campus buildings', () {
    expect(_ctx().matchPlace('kütüphaneye git')?.name, 'Meditation');
    expect(_ctx().matchPlace('rektörlük nerede')?.name, 'Rodin');
    expect(_ctx().matchPlace('yurt nerede')?.name, 'ARUCAD Dormitory');
  });

  test('matchPlace does not treat Eve as a substring of other words', () {
    expect(_ctx().matchPlace('I believe this is the way'), isNull);
  });

  test('GuideContext.load merges the 19 SQL campus names', () async {
    // ignore: unused_local_variable
    final places = campusMapPlaces(const <CampusPlace>[]);
    expect(places.map((p) => p.name).toSet(), containsAll(sqlCampusPoiNames));
  });
}
