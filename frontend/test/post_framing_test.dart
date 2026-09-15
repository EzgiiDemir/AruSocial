import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/story_framing.dart';

/// Framing a post's photo, and reading back what was stored.
///
/// The value of these is almost entirely in the hostile cases. Framing is
/// written by one client and read by every other, including older and
/// newer builds, so `fromStyle` is the app's trust boundary: anything it
/// lets through goes straight into a layout calculation. A NaN scale or an
/// infinite offset does not throw — it produces an unrenderable frame on
/// somebody else's phone, which is far harder to diagnose than a crash.
void main() {
  group('reading stored framing', () {
    test('absent framing gives the default rather than throwing', () {
      expect(MediaFraming.fromStyle(null).isDefault, isTrue);
      expect(MediaFraming.fromStyle(const {}).isDefault, isTrue);
    });

    test('a legacy post with no framing keeps the old behaviour', () {
      // Fill was the only behaviour before framing existed, so that is what
      // a post written then was published as.
      expect(MediaFraming.fromStyle(null).fit, FrameFit.fill);
    });

    test('framing that is not a map is ignored', () {
      for (final junk in <Object>['nonsense', 42, <int>[1, 2, 3], true]) {
        expect(MediaFraming.fromStyle({'framing': junk}).isDefault, isTrue,
            reason: '$junk should not survive into a layout');
      }
    });

    test('NaN and infinity never reach the renderer', () {
      for (final bad in <double>[
        double.nan,
        double.infinity,
        double.negativeInfinity,
      ]) {
        final framing = MediaFraming.fromStyle({
          'framing': {'scale': bad, 'offsetX': bad, 'offsetY': bad},
        });

        expect(framing.scale.isFinite, isTrue);
        expect(framing.offsetX.isFinite, isTrue);
        expect(framing.offsetY.isFinite, isTrue);
        expect(framing.scale, greaterThanOrEqualTo(MediaFraming.minScale));
      }
    });

    test('out-of-range numbers are clamped, not rejected', () {
      final framing = MediaFraming.fromStyle({
        'framing': {'scale': 99.0, 'offsetX': -8.0, 'offsetY': 8.0},
      });

      expect(framing.scale, MediaFraming.maxScale);
      expect(framing.offsetX, -1.0);
      expect(framing.offsetY, 1.0);
    });

    test('an unknown fit falls back to fill', () {
      expect(
        MediaFraming.fromStyle({'framing': {'fit': 'sideways'}}).fit,
        FrameFit.fill,
      );
    });

    test('a round trip preserves what the author chose', () {
      const original = MediaFraming(
        fit: FrameFit.fit,
        scale: 2.5,
        offsetX: 0.25,
        offsetY: -0.4,
        backgroundColor: 0xFF14181F,
        aspect: PostAspect.square,
      );

      final restored = MediaFraming.fromStyle(original.toStyle(null));

      expect(restored.fit, original.fit);
      expect(restored.scale, original.scale);
      expect(restored.offsetX, original.offsetX);
      expect(restored.offsetY, original.offsetY);
      expect(restored.backgroundColor, original.backgroundColor);
      expect(restored.aspect, original.aspect);
    });

    test('framing is merged into an existing style rather than replacing it', () {
      const framing = MediaFraming(fit: FrameFit.fit, scale: 2);

      final style = framing.toStyle({'text': 'a caption already here'});

      expect(style['text'], 'a caption already here');
      expect(style['framing'], isNotNull);
    });
  });

  group('offsets cannot drag the photo off its frame', () {
    test('at scale 1 there is no slack at all', () {
      expect(MediaFraming.clampOffset(0.9, 1.0), 0.0);
      expect(MediaFraming.clampOffset(-0.9, 1.0), 0.0);
    });

    test('the further in it is pushed, the more it can move', () {
      final atTwo = MediaFraming.clampOffset(1.0, 2.0);
      final atFour = MediaFraming.clampOffset(1.0, 4.0);

      expect(atTwo, greaterThan(0));
      expect(atFour, greaterThan(atTwo));
    });
  });

  group('the frame a post is shown in', () {
    test('square and portrait ignore the photo, because that is the point', () {
      expect(PostAspect.square.ratio(1.91), 1.0);
      expect(PostAspect.portrait.ratio(1.91), 4 / 5);
    });

    test('original follows the photo', () {
      expect(PostAspect.original.ratio(1.5), 1.5);
    });

    test('original is bounded so one photo cannot make a card a mile tall', () {
      expect(PostAspect.original.ratio(0.01), greaterThanOrEqualTo(0.5));
      expect(PostAspect.original.ratio(50.0), lessThanOrEqualTo(2.0));
    });

    test('an unusable intrinsic ratio falls back rather than breaking layout', () {
      for (final bad in <double?>[null, 0, -1, double.nan, double.infinity]) {
        final ratio = PostAspect.original.ratio(bad);

        expect(ratio.isFinite, isTrue, reason: 'ratio for $bad must be drawable');
        expect(ratio, greaterThan(0));
      }
    });

    test('an unrecognised stored name falls back to portrait', () {
      expect(PostAspect.fromName('hexagonal'), PostAspect.portrait);
      expect(PostAspect.fromName(null), PostAspect.portrait);
    });
  });

  group('a post collapses its two media shapes into one list', () {
    FeedPost post({
      String? imageUrl,
      List<PostMedia> media = const [],
      Map<String, dynamic>? style,
      String? altText,
    }) {
      return FeedPost(
        id: 'p1',
        name: 'Author',
        text: 'text',
        meta: '',
        likes: 0,
        imageUrl: imageUrl,
        media: media,
        style: style,
        altText: altText,
      );
    }

    test('a legacy single-image post yields exactly one item', () {
      final items = post(imageUrl: 'https://example.test/a.jpg').allMedia;

      expect(items, hasLength(1));
      expect(items.single.imageUrl, 'https://example.test/a.jpg');
    });

    test('the synthesised item carries the post framing and alt text', () {
      final items = post(
        imageUrl: 'https://example.test/a.jpg',
        style: const {'framing': {'fit': 'fit'}},
        altText: 'A courtyard',
      ).allMedia;

      expect(items.single.altText, 'A courtyard');
      expect(MediaFraming.fromStyle(items.single.style).fit, FrameFit.fit);
    });

    test('a post with no picture at all yields nothing', () {
      expect(post().allMedia, isEmpty);
    });

    test('a carousel is used in preference to the single image', () {
      final items = post(
        imageUrl: 'https://example.test/first.jpg',
        media: const [
          PostMedia(id: 'm1', imageUrl: 'https://example.test/1.jpg'),
          PostMedia(id: 'm2', imageUrl: 'https://example.test/2.jpg'),
        ],
      ).allMedia;

      expect(items, hasLength(2));
      expect(items.first.imageUrl, 'https://example.test/1.jpg');
    });
  });

  group('decoding a post from the API', () {
    test('a response with no media field decodes as a single-image post', () {
      final post = FeedPost.fromJson({
        'id': 'p1',
        'name': 'Author',
        'text': 'hello',
        'meta': '',
        'likes': 0,
        'imageUrl': 'https://example.test/a.jpg',
      });

      expect(post.media, isEmpty);
      expect(post.allMedia, hasLength(1));
      expect(post.style, isNull);
      expect(post.altText, isNull);
    });

    test('carousel rows decode with their own framing and alt text', () {
      final post = FeedPost.fromJson({
        'id': 'p1',
        'name': 'Author',
        'text': 'hello',
        'meta': '',
        'likes': 0,
        'media': [
          {
            'id': 'm1',
            'imageUrl': 'https://example.test/1.jpg',
            'sortOrder': 0,
            'width': 1200,
            'height': 1500,
            'altText': 'The studio',
            'styleJson': {'framing': {'fit': 'fit', 'aspect': 'square'}},
          },
        ],
      });

      final item = post.media.single;

      expect(item.altText, 'The studio');
      expect(item.aspectRatio, closeTo(0.8, 0.0001));
      expect(MediaFraming.fromStyle(item.style).aspect, PostAspect.square);
    });

    test('a malformed media entry does not take the whole feed down', () {
      final post = FeedPost.fromJson({
        'id': 'p1',
        'name': 'Author',
        'text': 'hello',
        'meta': '',
        'likes': 0,
        'media': ['not a map', 42, null],
      });

      expect(post.media, isEmpty);
    });
  });
}
