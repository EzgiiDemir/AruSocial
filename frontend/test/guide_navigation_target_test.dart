import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/ask_arucad_store.dart';
import 'package:arucad_campus_prototype/features/guide/guide_context.dart';

/// Navigation must go to the building the student asked about.
void main() {
  CampusPlace place(String name) => CampusPlace(
        id: name.toLowerCase(),
        name: name,
        category: 'Academic',
        lat: 35.3373,
        lng: 33.3213,
        description: '',
        distance: '',
        density: '',
        street: '',
        tourUrl: '',
        accessible: false,
        photos: 0,
        rating: 0,
      );

  final ctx = GuideContext(
    places: [
      place('Titan'),
      place('Rodin'),
      place('Meditation'),
      place('Iris (Atelier Building)'),
    ],
    events: const [],
    clubs: const [],
    sports: const [],
    services: const [],
    foodVenues: const [],
  );

  group('answer fallback', () {
    test('takes the first building named, not the longest', () {
      // The old rule preferred the longest match and could pick Rodin here.
      final target = ctx.firstMentionedPlace("Titan'da, Rodin'in yanında.");

      expect(target?.name, 'Titan');
    });

    test('still resolves a single mention', () {
      expect(
        ctx
            .firstMentionedPlace(
                'Arkeoloji bölüm başkanlığı Titan binasındadır.')
            ?.name,
        'Titan',
      );
    });

    test('resolves an alias used in the answer', () {
      expect(ctx.firstMentionedPlace('Kütüphane binasındadır.')?.name,
          'Meditation');
    });

    test('returns null when no building is named', () {
      expect(ctx.firstMentionedPlace('Bu bilgiyi bulamadım.'), isNull);
    });
  });

  group('question matching is unchanged', () {
    test('a question still prefers the most specific name', () {
      expect(ctx.matchPlace('Iris (Atelier Building) nerede?')?.name,
          'Iris (Atelier Building)');
    });

    test('an alias in the question resolves to its building', () {
      expect(ctx.matchPlace('kütüphane nerede?')?.name, 'Meditation');
    });
  });

  test('structured AICAD place keeps its exact 360 action target', () {
    final place = AskPlaceComponent.fromJson(const {
      'id': 'main-entrance',
      'name': 'Ana kampüs girişi',
      'category': 'Entrance',
      'latitude': 35.337,
      'longitude': 33.321,
      'tourUrl': 'https://360.arucad.edu.tr/Main/index.htm?media-name=ENTRY',
      'tourTarget': 'ENTRY',
    });

    expect(place.tourUrl, contains('media-name=ENTRY'));
    expect(place.tourTarget, 'ENTRY');
    expect(place.toJson()['tourTarget'], 'ENTRY');
  });
}
