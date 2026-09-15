import 'package:flutter/material.dart';


/// Display frames. 4:5 is the default a post is *shown* in; stories are
/// 9:16, which is the screen.
///
/// These are layout constants, not transformations. `cropBytesToAspect`
/// used to live here and centre-cropped a post's photo to 4:5 before the
/// author had seen it — permanently, discarding the rest of the picture.
/// Framing replaced it: see `MediaFraming` and `FramedImage`.
class MediaFrame {
  static const post = 4 / 5;
  static const story = 9 / 16;
}

class FramedMedia extends StatelessWidget {
  final double aspectRatio;
  final Widget child;
  final double borderRadius;

  const FramedMedia({
    super.key,
    required this.aspectRatio,
    required this.child,
    this.borderRadius = 14,
  });

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(borderRadius),
      child: AspectRatio(
        aspectRatio: aspectRatio,
        child: SizedBox.expand(child: child),
      ),
    );
  }
}

/// Feed / detail photos sit in a left-aligned 4:5 tile. Sized to read as a
/// photo inside the card without stretching to full card width/height.
class CompactFeedImage extends StatelessWidget {
  final Widget child;
  final double aspectRatio;
  final double maxWidth;
  final double borderRadius;

  const CompactFeedImage({
    super.key,
    required this.child,
    this.aspectRatio = MediaFrame.post,
    this.maxWidth = 236,
    this.borderRadius = 12,
  });

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final available = constraints.maxWidth.isFinite
            ? constraints.maxWidth
            : maxWidth;
        final width = maxWidth.clamp(0.0, available);
        final height = width / aspectRatio;
        return Align(
          alignment: Alignment.centerLeft,
          child: SizedBox(
            width: width,
            height: height,
            child: FramedMedia(
              aspectRatio: aspectRatio,
              borderRadius: borderRadius,
              child: child,
            ),
          ),
        );
      },
    );
  }
}

/// Compact compose preview so a 9:16 / 4:5 frame cannot blow out a sheet.
class FramedMediaPreview extends StatelessWidget {
  final double aspectRatio;
  final Widget child;
  final VoidCallback? onClear;
  final VoidCallback? onAdjust;
  final double maxHeight;

  const FramedMediaPreview({
    super.key,
    required this.aspectRatio,
    required this.child,
    this.onClear,
    this.onAdjust,
    this.maxHeight = 176,
  });

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: Alignment.center,
      child: ConstrainedBox(
        constraints: BoxConstraints(maxHeight: maxHeight),
        child: AspectRatio(
          aspectRatio: aspectRatio,
          child: Stack(
            fit: StackFit.expand,
            children: [
            FramedMedia(aspectRatio: aspectRatio, child: child),
            if (onAdjust != null)
              Positioned(
                left: 6,
                bottom: 6,
                child: IconButton.filled(
                  tooltip: 'Kırp / konumlandır',
                  style: IconButton.styleFrom(
                    backgroundColor: Colors.black54,
                    minimumSize: const Size(36, 36),
                  ),
                  onPressed: onAdjust,
                  icon: const Icon(Icons.crop, size: 16, color: Colors.white),
                ),
              ),
            if (onClear != null)
              Positioned(
                right: 6,
                top: 6,
                child: IconButton.filled(
                  style: IconButton.styleFrom(
                    backgroundColor: Colors.black54,
                    minimumSize: const Size(32, 32),
                  ),
                  onPressed: onClear,
                  icon: const Icon(Icons.close, size: 16, color: Colors.white),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
