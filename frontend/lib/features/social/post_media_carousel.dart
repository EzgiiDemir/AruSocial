import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/story_framing.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/social/framed_story_image.dart';

/// A post's pictures in the feed, framed the way their author framed them.
///
/// One picture or ten, this is the only widget the feed uses, so there is
/// no second rendering path to keep in step. It draws through
/// [FramedImage] — the same widget the composer previews with — which is
/// what makes "what you saw is what got posted" true rather than hoped
/// for.
///
/// Every item is drawn at one ratio, taken from the first picture. A
/// carousel whose items each took their own height would change the height
/// of the card as you paged through it, dragging the rest of the feed up
/// and down under your thumb.
class PostMediaCarousel extends StatefulWidget {
  const PostMediaCarousel({
    super.key,
    required this.post,
    this.borderRadius = 16,
  });

  final FeedPost post;
  final double borderRadius;

  @override
  State<PostMediaCarousel> createState() => _PostMediaCarouselState();
}

class _PostMediaCarouselState extends State<PostMediaCarousel> {
  late final PageController _controller = PageController();
  int _page = 0;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  /// The shape of the whole carousel, decided by its first picture.
  double get _ratio {
    final items = widget.post.allMedia;
    if (items.isEmpty) return MediaFraming.defaultPostRatio;

    final first = items.first;
    final framing = MediaFraming.fromStyle(first.style);

    return (framing.aspect ?? PostAspect.portrait).ratio(first.aspectRatio);
  }

  @override
  Widget build(BuildContext context) {
    final items = widget.post.allMedia;
    if (items.isEmpty) return const SizedBox.shrink();

    final single = items.length == 1;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(widget.borderRadius),
          child: AspectRatio(
            aspectRatio: _ratio,
            child: Stack(
              fit: StackFit.expand,
              children: [
                if (single)
                  _image(items.first, 0, 1)
                else
                  PageView.builder(
                    controller: _controller,
                    itemCount: items.length,
                    onPageChanged: (i) => setState(() => _page = i),
                    itemBuilder: (context, i) =>
                        _image(items[i], i, items.length),
                  ),
                if (!single)
                  Positioned(
                    right: 10,
                    top: 10,
                    child: _Counter(current: _page + 1, total: items.length),
                  ),
              ],
            ),
          ),
        ),
        if (!single) ...[
          const SizedBox(height: 8),
          _Dots(count: items.length, active: _page),
        ],
      ],
    );
  }

  Widget _image(PostMedia media, int index, int total) {
    final framing = MediaFraming.fromStyle(media.style);

    // The position is part of what a screen reader needs: "2 of 5" is the
    // difference between a picture and a picture in a sequence.
    final described = media.altText?.trim();
    final label = total > 1
        ? (described == null || described.isEmpty
            ? '${index + 1} / $total'
            : '$described (${index + 1} / $total)')
        : described;

    // Bytes win when present: an optimistic post is showing the photo the
    // author just picked, before any upload has finished.
    if (index == 0 && widget.post.imageBytes != null && media.imageUrl == null) {
      return FramedImage(
        framing: framing,
        bytes: widget.post.imageBytes,
        altText: label,
        fallbackBackground: Colors.black,
      );
    }

    return FramedImage(
      framing: framing,
      url: media.imageUrl,
      altText: label,
      fallbackBackground: Colors.black,
    );
  }
}

class _Counter extends StatelessWidget {
  const _Counter({required this.current, required this.total});

  final int current;
  final int total;

  @override
  Widget build(BuildContext context) {
    return ExcludeSemantics(
      // The image itself already announces "2 of 5"; repeating it here
      // would make a screen reader say it twice per picture.
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
        decoration: BoxDecoration(
          color: Colors.black54,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Text(
          '$current/$total',
          style: const TextStyle(
            color: Colors.white,
            fontSize: 11,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
    );
  }
}

class _Dots extends StatelessWidget {
  const _Dots({required this.count, required this.active});

  final int count;
  final int active;

  @override
  Widget build(BuildContext context) {
    // Honours the system's reduce-motion setting: the dot still moves, it
    // just stops sliding for anyone who finds that uncomfortable.
    final reduceMotion = MediaQuery.maybeDisableAnimationsOf(context) ?? false;

    return ExcludeSemantics(
      child: Center(
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            for (var i = 0; i < count; i++)
              AnimatedContainer(
                duration: reduceMotion
                    ? Duration.zero
                    : const Duration(milliseconds: 180),
                margin: const EdgeInsets.symmetric(horizontal: 3),
                width: i == active ? 18 : 6,
                height: 6,
                decoration: BoxDecoration(
                  color: i == active
                      ? ArucadColors.primary
                      : ArucadColors.muted.withValues(alpha: 0.35),
                  borderRadius: BorderRadius.circular(3),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
