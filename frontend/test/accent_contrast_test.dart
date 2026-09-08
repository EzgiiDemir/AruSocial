import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// Badges and buttons must be readable, and "looks fine to me" is not a
/// measurement.
///
/// The brand yellow is the case that made this necessary: white on it is
/// 2.81:1 — far under the 4.5:1 minimum, and worse than black. So the fix
/// could not be "use white text", which would have made it less readable
/// while looking like an improvement. The fill is darkened instead, until
/// white genuinely passes.
void main() {
  const accents = <String, Color>{
    'yellow': ArucadColors.yellow,
    'red': ArucadColors.red,
    'green': ArucadColors.campusGreen,
    'orange': ArucadColors.orange,
    'primary': ArucadColors.primary,
    'lavender': ArucadColors.lavender,
  };

  test('white text passes AA on every accent fill', () {
    final failures = <String>[];

    accents.forEach((name, color) {
      final fill = accentFill(color);
      final ratio = contrastRatio(fill, Colors.white);
      if (ratio < 4.5) {
        failures.add('$name: ${ratio.toStringAsFixed(2)}:1');
      }
    });

    expect(failures, isEmpty,
        reason: 'Illegible white-on-fill: ${failures.join(', ')}');
  });

  test('onAccent never returns a colour that fails on its own fill', () {
    final failures = <String>[];

    accents.forEach((name, color) {
      final fill = accentFill(color);
      final ratio = contrastRatio(fill, onAccent(fill));
      if (ratio < 4.5) {
        failures.add('$name: ${ratio.toStringAsFixed(2)}:1');
      }
    });

    expect(failures, isEmpty, reason: failures.join(', '));
  });

  test('darkening keeps the hue recognisable', () {
    // A yellow chip must still read as yellow, not as brown-black.
    final before = HSLColor.fromColor(ArucadColors.yellow);
    final after = HSLColor.fromColor(accentFill(ArucadColors.yellow));

    expect((after.hue - before.hue).abs(), lessThan(1.0));
    expect(after.lightness, lessThan(before.lightness));
    expect(after.lightness, greaterThan(0.12),
        reason: 'Darkened past the point of being a colour at all.');
  });

  test('a colour that is already dark enough is left alone', () {
    // Primary navy already carries white text; darkening it would be a
    // pointless visual change.
    expect(accentFill(ArucadColors.primary), ArucadColors.primary);
  });

  test('onAccent falls back to ink when a raw light colour is passed', () {
    // Callers that pass an undarkened light colour must not silently get
    // unreadable white text.
    expect(onAccent(ArucadColors.yellow), ArucadColors.ink);
    expect(onAccent(Colors.white), ArucadColors.ink);
  });
}
