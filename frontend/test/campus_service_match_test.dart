import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';

void main() {
  test('matchCampusService accepts catalog ids and service- prefixes', () {
    const services = [
      CampusService(
        id: 'service-student-affairs',
        title: 'Öğrenci İşleri',
        category: 'İdari',
        description: 'x',
        contact: 'a@b.c',
      ),
    ];
    expect(matchCampusService(services, 'student-affairs')?.id, 'service-student-affairs');
    expect(matchCampusService(services, 'service-student-affairs')?.id, 'service-student-affairs');
    expect(matchCampusService(services, 'pdr'), isNull);
  });

  test('catalogCampusService covers every campus-services id', () {
    const ids = [
      'academic-advising',
      'student-affairs',
      'pdr',
      'international',
      'dormitory',
      'it',
      'accessibility',
      'career',
      'lost-found',
    ];
    for (final id in ids) {
      expect(catalogCampusService(id), isNotNull, reason: id);
    }
  });

  test('mergeCampusService prefers catalog id and fills empty hours', () {
    const rest = CampusService(
      id: 'service-pdr',
      title: 'PDR',
      category: 'Wellbeing',
      description: 'x',
      contact: 'a@b.c',
    );
    final merged = mergeCampusService(rest, catalogCampusService('pdr'));
    expect(merged?.id, 'pdr');
    expect(merged?.hours, isNotNull);
    expect(merged?.building, isNotNull);
  });

  test('enrichCampusServices appends catalog rows missing from the API list', () {
    const api = [
      CampusService(
        id: 'pdr',
        title: 'PDR',
        category: 'Wellbeing',
        description: 'x',
        contact: 'a@b.c',
      ),
    ];
    final enriched = enrichCampusServices(api);
    expect(enriched.any((s) => s.id == 'pdr'), isTrue);
    expect(enriched.any((s) => s.id == 'career'), isTrue);
    expect(enriched.any((s) => s.id == 'student-affairs'), isTrue);
  });
}
