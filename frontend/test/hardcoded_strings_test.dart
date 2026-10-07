import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// Stops new hardcoded user-facing text from being added.
///
/// Translations are managed from the Admin panel now, which only works for
/// text that goes through `t('key')`. A literal `Text('Kaydet')` compiled
/// into the widget tree cannot be translated, cannot be fixed without a
/// store release, and is invisible to the panel's missing-translation
/// warning — it looks complete while being permanently Turkish.
///
/// This is a **ratchet, not a clean sweep.** There are already 391 of them,
/// and rewriting all 395 today would be a large, risky change unrelated to
/// whatever else is in flight. So the count is recorded: it may fall, it
/// may not rise. New screens are translated from the start, and the debt
/// gets paid down when someone is already in the file.
///
/// When you fix some, run the test — it tells you the new number to put in
/// [baseline].
void main() {
  /// Known hardcoded literals as of 15 September 2026.
  ///
  /// Fell from 395 when the post composer and framing editor were written
  /// against the translation table from the start, and the old crop sheet
  /// they replaced was deleted.
  ///
  /// Lower this when you fix some. Never raise it.
  const baseline = 360;

  /// `Text('four or more characters')`, single or double quoted.
  ///
  /// Four is the shortest thing worth translating — it skips 'px', ':', '—'
  /// and the like without needing a list of exceptions.
  final pattern = RegExp(r"""Text\(\s*(?:'[^']{4,}'|"[^"]{4,}")\s*[,)]""");

  /// A literal that came from the translation table is not hardcoded.
  bool isTranslated(String line) =>
      line.contains('.t(') ||
      line.contains('strings.') ||
      line.contains('AppLocale');

  List<String> scan() {
    final root = Directory('lib');
    final found = <String>[];

    for (final entity in root.listSync(recursive: true)) {
      if (entity is! File || !entity.path.endsWith('.dart')) continue;

      // The translation table itself is nothing but literals, by design.
      if (entity.path.replaceAll(r'\', '/').contains('core/l10n/')) continue;

      final lines = entity.readAsLinesSync();
      for (var i = 0; i < lines.length; i++) {
        final line = lines[i];
        if (isTranslated(line)) continue;
        if (pattern.hasMatch(line)) {
          found.add('${entity.path}:${i + 1}  ${line.trim()}');
        }
      }
    }

    return found;
  }

  test('no new hardcoded user-facing strings', () {
    final found = scan();

    if (found.length > baseline) {
      final extra = found.length - baseline;
      fail(
        'Hardcoded user-facing strings rose from $baseline to ${found.length} '
        '(+$extra).\n\n'
        'Use t(\'some_key\') and add the key in the Admin panel, so the text '
        'can be translated and corrected without an app release.\n\n'
        'Some of what was found:\n${found.take(15).join('\n')}',
      );
    }

    expect(
      found.length,
      lessThanOrEqualTo(baseline),
      reason: 'Hardcoded strings must never increase.',
    );

    // Nudge, not a failure: if the count dropped, the baseline is stale.
    if (found.length < baseline) {
      // ignore: avoid_print
      print(
        'Hardcoded strings are down to ${found.length} (baseline $baseline). '
        'Lower the baseline in this test to lock the improvement in.',
      );
    }
  });

  /// The panel can only manage keys the app actually asks for, so the
  /// lookup helper must keep existing under the name the codebase uses.
  test('the translation lookup is still called t()', () {
    final strings = File('lib/core/l10n/app_strings.dart').readAsStringSync();

    // Matched on the name and first parameter, not the whole signature:
    // `t()` gained an optional args map for placeholders, and asserting
    // the exact text turned a compatible addition into a failure.
    expect(strings.contains('String t(String key'), isTrue,
        reason: 'AppStrings.t() is the contract the whole app and the '
            'Admin panel are built around.');
  });
}
