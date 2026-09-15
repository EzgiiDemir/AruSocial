import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/models/story_framing.dart';

/// How a photo sits in a story frame.
///
/// A story is roughly 9:19.5 and almost no photo is that shape, so
/// something has to give. The app used to always answer "crop to fill,
/// centred", which on a portrait photo throws away most of the top and
/// bottom — the reason a posted story came out far closer than the picture
/// it was made from. This is the author's answer instead, and it travels in
/// the story's existing `style_json`.
void main() {
  group('round trip', () {
    test('the default writes nothing, so old stories stay untouched', () {
      const framing = StoryFraming();

      expect(framing.isDefault, isTrue);
      expect(framing.toStyle(null).containsKey('framing'), isFalse);
    });

    test('a choice survives being written and read back', () {
      const framing = StoryFraming(
        fit: FrameFit.fit,
        scale: 1.8,
        offsetX: 0.2,
        offsetY: -0.1,
        backgroundColor: 0xFF1B4A9C,
      );

      final restored = StoryFraming.fromStyle(framing.toStyle(null));

      expect(restored.fit, FrameFit.fit);
      expect(restored.scale, closeTo(1.8, 0.001));
      expect(restored.offsetX, closeTo(0.2, 0.001));
      expect(restored.offsetY, closeTo(-0.1, 0.001));
      expect(restored.backgroundColor, 0xFF1B4A9C);
    });

    /// The same map already carries text styling and tagged people for
    /// text stories. Framing has to join it, not replace it.
    test('existing style entries are kept', () {
      const framing = StoryFraming(fit: FrameFit.fit);

      final style = framing.toStyle({
        'taggedPeople': ['ayse'],
        'font': 'serif',
      });

      expect(style['taggedPeople'], ['ayse']);
      expect(style['font'], 'serif');
      expect(style['framing'], isNotNull);
    });

    test('clearing back to the default removes the entry', () {
      final style = const StoryFraming(fit: FrameFit.fit)
          .toStyle({'taggedPeople': <String>[]});
      expect(style.containsKey('framing'), isTrue);

      final cleared = const StoryFraming().toStyle(style);
      expect(cleared.containsKey('framing'), isFalse);
      expect(cleared.containsKey('taggedPeople'), isTrue);
    });
  });

  /// A story is published once and read for 24 hours by clients that may be
  /// older or newer than the one that wrote it. A story that will not render
  /// is worse than one framed the old way.
  group('reading anything', () {
    test('a story with no framing falls back to fill', () {
      expect(StoryFraming.fromStyle(null).fit, FrameFit.fill);
      expect(StoryFraming.fromStyle({}).fit, FrameFit.fill);
      expect(StoryFraming.fromStyle({'framing': 'nonsense'}).fit, FrameFit.fill);
    });

    test('rubbish values give the default rather than throwing', () {
      final framing = StoryFraming.fromStyle({
        'framing': {
          'fit': 42,
          'scale': 'huge',
          'offsetX': null,
          'bg': 'blue',
        }
      });

      expect(framing.fit, FrameFit.fill);
      expect(framing.scale, 1.0);
      expect(framing.offsetX, 0.0);
      expect(framing.backgroundColor, isNull);
    });

    test('infinity and NaN do not reach the renderer', () {
      final framing = StoryFraming.fromStyle({
        'framing': {'scale': double.infinity, 'offsetX': double.nan},
      });

      expect(framing.scale.isFinite, isTrue);
      expect(framing.offsetX.isFinite, isTrue);
    });

    /// A hand-crafted payload must not be able to shrink a story to nothing
    /// or blow it up until it is one pixel of someone's jumper.
    test('out-of-range values are clamped', () {
      final framing = StoryFraming.fromStyle({
        'framing': {'scale': 900.0, 'offsetX': -12.0, 'offsetY': 7.0},
      });

      expect(framing.scale, StoryFraming.maxScale);
      expect(framing.offsetX, -1.0);
      expect(framing.offsetY, 1.0);
    });
  });

  group('offset clamping', () {
    /// At scale 1 the photo exactly meets the frame, so there is nothing
    /// spare to push around. Allowing it would drag the picture off the
    /// frame and leave a band of background, which reads as a bug.
    test('nothing can be pushed at scale 1', () {
      expect(StoryFraming.clampOffset(0.5, 1.0), 0.0);
      expect(StoryFraming.clampOffset(-0.5, 1.0), 0.0);
    });

    test('the further in, the more there is to move', () {
      final atTwo = StoryFraming.clampOffset(1.0, 2.0);
      final atFour = StoryFraming.clampOffset(1.0, 4.0);

      expect(atTwo, greaterThan(0.0));
      expect(atFour, greaterThan(atTwo));
    });

    test('a value inside the slack is left alone', () {
      expect(StoryFraming.clampOffset(0.1, 4.0), closeTo(0.1, 0.001));
    });
  });

  group('copyWith', () {
    test('clearing the background is distinguishable from not setting it', () {
      const framing = StoryFraming(backgroundColor: 0xFF000000);

      expect(framing.copyWith().backgroundColor, 0xFF000000);
      expect(framing.copyWith(clearBackground: true).backgroundColor, isNull);
    });

    test('scale is clamped on the way in', () {
      expect(const StoryFraming().copyWith(scale: 99).scale,
          StoryFraming.maxScale);
      expect(const StoryFraming().copyWith(scale: 0.1).scale,
          StoryFraming.minScale);
    });
  });

  test('the background palette is short and fully opaque', () {
    expect(StoryFraming.backgrounds, isNotEmpty);
    expect(StoryFraming.backgrounds.length, lessThanOrEqualTo(10),
        reason: 'A colour wheel invites time spent on a decision that '
            'does not matter much.');

    for (final colour in StoryFraming.backgrounds) {
      expect(colour >> 24 & 0xFF, 0xFF,
          reason: 'A translucent story background would show the black '
              'behind it and look like a rendering fault.');
    }
  });
}
