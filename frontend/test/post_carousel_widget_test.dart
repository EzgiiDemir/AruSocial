import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/features/social/post_media_carousel.dart';

/// The feed's picture area, under the conditions that actually break
/// layouts: a narrow phone, text scaled up for readability, and posts
/// whose stored data is wrong.
///
/// Flutter reports an overflow by painting a striped banner and recording
/// an exception, so `tester.takeException()` is what turns "it looked odd"
/// into a failing test.
void main() {
  FeedPost post({
    String? imageUrl = 'https://example.test/a.jpg',
    List<PostMedia> media = const [],
    Map<String, dynamic>? style,
    String? altText,
  }) {
    return FeedPost(
      id: 'p1',
      name: 'Author',
      text: 'caption',
      meta: '',
      likes: 0,
      imageUrl: imageUrl,
      media: media,
      style: style,
      altText: altText,
    );
  }

  /// Renders at a given width and text scale, and returns any layout
  /// exception Flutter recorded.
  Future<Object?> renderAt(
    WidgetTester tester,
    FeedPost value, {
    double width = 400,
    double textScale = 1.0,
  }) async {
    await tester.pumpWidget(
      MaterialApp(
        home: MediaQuery(
          data: MediaQueryData(textScaler: TextScaler.linear(textScale)),
          child: Scaffold(
            body: SizedBox(
              width: width,
              child: SingleChildScrollView(
                child: PostMediaCarousel(post: value),
              ),
            ),
          ),
        ),
      ),
    );
    await tester.pump();

    return tester.takeException();
  }

  testWidgets('a single-image post renders without a page indicator',
      (tester) async {
    expect(await renderAt(tester, post()), isNull);

    // One picture is not a carousel, so there is nothing to count.
    expect(find.text('1/1'), findsNothing);
  });

  testWidgets('a post with no picture renders nothing rather than an empty box',
      (tester) async {
    expect(await renderAt(tester, post(imageUrl: null)), isNull);
    expect(find.byType(PageView), findsNothing);
  });

  testWidgets('a carousel shows how many pictures there are', (tester) async {
    final value = post(media: const [
      PostMedia(id: 'm1', imageUrl: 'https://example.test/1.jpg'),
      PostMedia(id: 'm2', imageUrl: 'https://example.test/2.jpg'),
      PostMedia(id: 'm3', imageUrl: 'https://example.test/3.jpg'),
    ]);

    expect(await renderAt(tester, value), isNull);
    expect(find.text('1/3'), findsOneWidget);
  });

  /// The whole reason the ratio comes from the first item.
  testWidgets('every item is drawn at one height, whatever its own shape',
      (tester) async {
    final value = post(media: const [
      // Portrait first, then a wide landscape.
      PostMedia(id: 'm1', imageUrl: 'https://example.test/1.jpg', width: 1200, height: 1500),
      PostMedia(id: 'm2', imageUrl: 'https://example.test/2.jpg', width: 3000, height: 1000),
    ]);

    expect(await renderAt(tester, value), isNull);

    // One AspectRatio wraps the whole carousel rather than one per page,
    // which is what stops the card resizing as it is paged through.
    expect(find.byType(AspectRatio), findsOneWidget);
  });

  group('layouts that would otherwise break', () {
    testWidgets('no overflow on a narrow phone', (tester) async {
      tester.view.physicalSize = const Size(320, 640);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.resetPhysicalSize);

      final value = post(media: const [
        PostMedia(id: 'm1', imageUrl: 'https://example.test/1.jpg'),
        PostMedia(id: 'm2', imageUrl: 'https://example.test/2.jpg'),
      ]);

      expect(await renderAt(tester, value, width: 320), isNull);
    });

    testWidgets('no overflow at the largest accessibility text size',
        (tester) async {
      final value = post(media: const [
        PostMedia(id: 'm1', imageUrl: 'https://example.test/1.jpg'),
        PostMedia(id: 'm2', imageUrl: 'https://example.test/2.jpg'),
      ]);

      // 2.0 is past the top of the iOS and Android accessibility sliders.
      expect(await renderAt(tester, value, width: 320, textScale: 2.0), isNull);
    });

    testWidgets('a wildly wrong stored aspect ratio still draws', (tester) async {
      final value = post(media: const [
        PostMedia(
          id: 'm1',
          imageUrl: 'https://example.test/1.jpg',
          width: 60000,
          height: 1,
        ),
      ], style: const {'framing': {'aspect': 'original'}});

      expect(await renderAt(tester, value), isNull);
    });

    testWidgets('framing full of NaN does not take the card down',
        (tester) async {
      final value = post(style: {
        'framing': {
          'scale': double.nan,
          'offsetX': double.infinity,
          'offsetY': double.negativeInfinity,
        },
      });

      expect(await renderAt(tester, value), isNull);
    });

    testWidgets('malformed framing is ignored rather than fatal', (tester) async {
      expect(
        await renderAt(tester, post(style: const {'framing': 'not a map'})),
        isNull,
      );
    });
  });

  group('what a screen reader is told', () {
    testWidgets('a picture with alt text is announced with it', (tester) async {
      final handle = tester.ensureSemantics();

      await renderAt(tester, post(altText: 'The courtyard in spring'));

      expect(find.bySemanticsLabel('The courtyard in spring'), findsOneWidget);
      handle.dispose();
    });

    testWidgets('a carousel item also says where it is in the sequence',
        (tester) async {
      final handle = tester.ensureSemantics();

      await renderAt(
        tester,
        post(media: const [
          PostMedia(id: 'm1', imageUrl: 'https://example.test/1.jpg', altText: 'Studio'),
          PostMedia(id: 'm2', imageUrl: 'https://example.test/2.jpg', altText: 'Garden'),
        ]),
      );

      expect(find.bySemanticsLabel('Studio (1 / 2)'), findsOneWidget);
      handle.dispose();
    });

    /// An unlabelled image can only be announced as "graphic", which tells
    /// the listener nothing and costs them a stop.
    testWidgets('a picture with no alt text is not announced at all',
        (tester) async {
      final handle = tester.ensureSemantics();

      await renderAt(tester, post(altText: null));

      expect(find.bySemanticsLabel(RegExp(r'.+')), findsNothing);
      handle.dispose();
    });
  });
}
