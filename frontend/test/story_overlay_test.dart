import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/models/story_overlay.dart';

/// Text placed on top of a story.
///
/// Positions are fractions of the frame rather than pixels, which is the
/// only way a caption placed on one phone lands in the same place on
/// another. That makes `fromMap` the trust boundary: whatever it returns
/// is multiplied by a real frame size and turned into a layout, so a NaN
/// or an infinity that gets through does not throw — it takes out the
/// frame on somebody else's device.
void main() {
  group('reading overlays back', () {
    test('a story with no overlays gives an empty list', () {
      expect(StoryOverlay.listFromStyle(null), isEmpty);
      expect(StoryOverlay.listFromStyle(const {}), isEmpty);
    });

    test('a legacy story with framing but no overlays is unaffected', () {
      final overlays = StoryOverlay.listFromStyle({
        'framing': {'fit': 'fit', 'scale': 2},
      });

      expect(overlays, isEmpty);
    });

    test('overlays that are not a list are ignored', () {
      for (final junk in <Object>['text', 42, <String, int>{'a': 1}]) {
        expect(StoryOverlay.listFromStyle({'overlays': junk}), isEmpty);
      }
    });

    test('entries that are not maps are dropped, not rendered', () {
      final overlays = StoryOverlay.listFromStyle({
        'overlays': ['a string', 42, null, <int>[]],
      });

      expect(overlays, isEmpty);
    });

    test('an overlay with no text is dropped', () {
      expect(StoryOverlay.fromMap({'x': 0.5, 'y': 0.5}), isNull);
      expect(StoryOverlay.fromMap({'text': ''}), isNull);
      expect(StoryOverlay.fromMap({'text': '   '}), isNull);
      expect(StoryOverlay.fromMap({'text': 42}), isNull);
    });

    test('one bad entry does not discard the good ones', () {
      final overlays = StoryOverlay.listFromStyle({
        'overlays': [
          {'text': 'keep me'},
          'junk',
          {'text': 'me too'},
        ],
      });

      expect(overlays.map((o) => o.text), ['keep me', 'me too']);
    });

    test('NaN and infinity never reach a layout calculation', () {
      for (final bad in <double>[
        double.nan,
        double.infinity,
        double.negativeInfinity,
      ]) {
        final overlay = StoryOverlay.fromMap({
          'text': 'hello',
          'x': bad,
          'y': bad,
          'size': bad,
        })!;

        expect(overlay.x.isFinite, isTrue);
        expect(overlay.y.isFinite, isTrue);
        expect(overlay.fontScale.isFinite, isTrue);
        expect(overlay.fontScale, greaterThanOrEqualTo(StoryOverlay.minFontScale));
      }
    });

    test('positions outside the frame are clamped into it', () {
      final overlay = StoryOverlay.fromMap({
        'text': 'hello',
        'x': 40.0,
        'y': -40.0,
      })!;

      expect(overlay.x, 1.0);
      expect(overlay.y, 0.0);
    });

    test('font scale is bounded at both ends', () {
      expect(
        StoryOverlay.fromMap({'text': 'a', 'size': 99.0})!.fontScale,
        StoryOverlay.maxFontScale,
      );
      expect(
        StoryOverlay.fromMap({'text': 'a', 'size': 0.0001})!.fontScale,
        StoryOverlay.minFontScale,
      );
    });

    test('an absurdly long caption is truncated rather than laid out', () {
      final overlay = StoryOverlay.fromMap({'text': 'x' * 10000})!;

      expect(overlay.text.length, 500);
    });

    test('more overlays than the cap are not all kept', () {
      final overlays = StoryOverlay.listFromStyle({
        'overlays': List.generate(100, (i) => {'text': 'caption $i'}),
      });

      expect(overlays, hasLength(StoryOverlay.maxPerStory));
    });

    test('an unrecognised alignment falls back to centre', () {
      expect(OverlayAlign.fromName('diagonal'), OverlayAlign.center);
      expect(OverlayAlign.fromName(null), OverlayAlign.center);
    });
  });

  group('writing overlays', () {
    test('a round trip preserves what the author placed', () {
      const original = StoryOverlay(
        text: 'Kütüphanede',
        x: 0.3,
        y: 0.7,
        fontScale: 0.11,
        color: 0xFFE8B04B,
        align: OverlayAlign.left,
        background: 0xFF000000,
      );

      final restored =
          StoryOverlay.listFromStyle(StoryOverlay.listToStyle(null, [original]))
              .single;

      expect(restored.text, original.text);
      expect(restored.x, original.x);
      expect(restored.y, original.y);
      expect(restored.fontScale, original.fontScale);
      expect(restored.color, original.color);
      expect(restored.align, original.align);
      expect(restored.background, original.background);
    });

    test('overlays are merged into the style rather than replacing it', () {
      final style = StoryOverlay.listToStyle(
        {'framing': {'fit': 'fit'}},
        const [StoryOverlay(text: 'hello')],
      );

      expect(style['framing'], isNotNull, reason: 'framing must survive');
      expect(style['overlays'], hasLength(1));
    });

    test('an empty list removes the key instead of writing an empty one', () {
      final style = StoryOverlay.listToStyle(
        {'overlays': [{'text': 'old'}], 'framing': {'fit': 'fit'}},
        const [],
      );

      expect(style.containsKey('overlays'), isFalse);
      expect(style['framing'], isNotNull);
    });
  });

  group('staying clear of the story UI', () {
    test('an overlay under the progress bar is pushed down', () {
      const overlay = StoryOverlay(text: 'hi', y: 0.0);

      expect(overlay.clampedToSafeArea().y, StoryOverlay.safeTop);
    });

    test('an overlay under the reply box is pushed up', () {
      const overlay = StoryOverlay(text: 'hi', y: 1.0);

      expect(
        overlay.clampedToSafeArea().y,
        closeTo(1 - StoryOverlay.safeBottom, 0.0001),
      );
    });

    test('one already in the safe band is left where the author put it', () {
      const overlay = StoryOverlay(text: 'hi', x: 0.5, y: 0.5);
      final clamped = overlay.clampedToSafeArea();

      expect(clamped.x, 0.5);
      expect(clamped.y, 0.5);
    });
  });
}
