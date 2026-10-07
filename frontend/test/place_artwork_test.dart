import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/config/poi_config.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

/// Every campus place that has artwork must actually get it.
///
/// This exists because of a near miss. Bracing a run of single-statement
/// `if`s dropped the `return` from seventeen of them, so the function kept
/// falling through and every one of those places silently lost its
/// illustration. Nothing failed: the widget tree still built, the screens
/// still rendered, and the whole suite stayed green — the only symptom
/// would have been a campus map that quietly went blank in production.
///
/// A mapping that is only verified by looking at it is not verified.
void main() {
  /// Names taken from the real place list, one per artwork the function
  /// claims to know about.
  const named = <String>[
    'Ana Kampüs Girişi',
    'Rodin',
    'Falling Man',
    'Titan',
    'Eve',
    'Daniele',
    'Eternal Spring',
    'Meditation',
    'Minotaur',
    'Eternal Idol',
    'The Kiss',
    'Carpentry Studio',
    'ARUCAD Dormitory',
    'ARUCAD Art Space',
    'ARUCAD Workshops',
  ];

  test('every named place returns its artwork', () {
    final missing = <String>[];

    for (final name in named) {
      if (placeLineArt(name) == null) missing.add(name);
    }

    expect(
      missing,
      isEmpty,
      reason: '${missing.length} place(s) fall through and render nothing: '
          '${missing.join(', ')}',
    );
  });

  test('a place with no artwork returns null rather than throwing', () {
    expect(placeLineArt('Somewhere Nobody Drew'), isNull);
    expect(placeLineArt(''), isNull);
  });

  test('matching ignores case', () {
    expect(placeLineArt('RODIN'), isNotNull);
    expect(placeLineArt('rodin'), isNotNull);
  });

  /// The artwork is what tells two places apart on the map, so two names
  /// resolving to the same file is a bug even though nothing crashes.
  test('different places do not share one illustration', () {
    final assets = <String, String>{};

    for (final name in named) {
      final art = placeLineArt(name);
      if (art == null) continue;

      final existing = assets[art.asset];
      expect(
        existing,
        isNull,
        reason: '"$name" and "$existing" both render ${art.asset}.',
      );
      assets[art.asset] = name;
    }
  });

  /// Every place in the catalogue must get a real photo file. The path is
  /// derived from the line-art asset name, so a photo that was never added
  /// (or renamed) would only surface as a grey box at runtime.
  test('every catalogue place resolves a bundled photo that exists', () {
    final missing = <String>[];

    for (final poi in pois) {
      final photo = placePhoto(poi.name);
      if (photo == null || !File(photo).existsSync()) {
        missing.add('${poi.name} -> ${photo ?? 'no match'}');
      }
    }

    expect(
      missing,
      isEmpty,
      reason: '${missing.length} place(s) have no usable photo: '
          '${missing.join(', ')}',
    );
  });

  test('different places do not share one photo', () {
    final photos = <String, String>{};

    for (final poi in pois) {
      final photo = placePhoto(poi.name);
      if (photo == null) continue;

      final existing = photos[photo];
      expect(existing, isNull,
          reason: '"${poi.name}" and "$existing" both render $photo.');
      photos[photo] = poi.name;
    }
  });

  test('a place with no artwork has no photo either', () {
    expect(placePhoto('Somewhere Nobody Drew'), isNull);
  });
}
