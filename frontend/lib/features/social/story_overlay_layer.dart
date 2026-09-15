import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/models/story_overlay.dart';

/// Draws a story's text overlays over whatever is behind them.
///
/// Used by the editor and the viewer alike, so that what the author places
/// is what everyone else sees. Positions are fractions of the frame, which
/// is what makes that true across phones of different sizes — the same
/// reason the framing offsets are fractions.
class StoryOverlayLayer extends StatelessWidget {
  const StoryOverlayLayer({
    super.key,
    required this.overlays,
    this.showSafeArea = false,
  });

  final List<StoryOverlay> overlays;

  /// The band the story's own UI covers, drawn as a guide while composing.
  final bool showSafeArea;

  @override
  Widget build(BuildContext context) {
    if (overlays.isEmpty && !showSafeArea) return const SizedBox.shrink();

    return LayoutBuilder(
      builder: (context, constraints) {
        final width = constraints.maxWidth;
        final height = constraints.maxHeight;

        // The shorter side, so text is the same visual size whether the
        // frame is a tall phone or a squat tablet window.
        final basis = width < height ? width : height;

        return Stack(
          children: [
            if (showSafeArea) _SafeAreaGuide(width: width, height: height),
            for (final overlay in overlays)
              _positioned(overlay, width, height, basis),
          ],
        );
      },
    );
  }

  Widget _positioned(
      StoryOverlay overlay, double width, double height, double basis) {
    final fontSize = overlay.fontScale * basis;

    return Positioned(
      // The overlay's anchor is its centre, so a FractionalTranslation
      // pulls it back by half its own size — which is only known after it
      // has been laid out, hence doing it this way rather than with maths.
      left: overlay.x * width,
      top: overlay.y * height,
      child: FractionalTranslation(
        translation: const Offset(-0.5, -0.5),
        child: ConstrainedBox(
          // Never wider than the frame, so a long caption wraps instead of
          // running off the side.
          constraints: BoxConstraints(maxWidth: width * 0.9),
          child: _OverlayText(overlay: overlay, fontSize: fontSize),
        ),
      ),
    );
  }
}

class _OverlayText extends StatelessWidget {
  const _OverlayText({required this.overlay, required this.fontSize});

  final StoryOverlay overlay;
  final double fontSize;

  @override
  Widget build(BuildContext context) {
    final text = Text(
      overlay.text,
      textAlign: switch (overlay.align) {
        OverlayAlign.left => TextAlign.left,
        OverlayAlign.center => TextAlign.center,
        OverlayAlign.right => TextAlign.right,
      },
      style: TextStyle(
        color: Color(overlay.color),
        fontSize: fontSize,
        fontWeight: FontWeight.w800,
        height: 1.2,
        // A photo can be any colour at all underneath, so the text carries
        // its own contrast rather than hoping. This is what keeps white
        // text readable over a white sky.
        shadows: overlay.background == null
            ? const [
                Shadow(color: Colors.black54, blurRadius: 8),
                Shadow(color: Colors.black26, blurRadius: 2),
              ]
            : null,
      ),
    );

    if (overlay.background == null) return text;

    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: fontSize * 0.45,
        vertical: fontSize * 0.22,
      ),
      decoration: BoxDecoration(
        color: Color(overlay.background!),
        borderRadius: BorderRadius.circular(fontSize * 0.3),
      ),
      child: text,
    );
  }
}

/// Shows where the story's own controls will sit.
///
/// Composing is the only time this is visible. Without it the author
/// places a caption against a clean preview and then finds it sitting
/// under the progress bar, with no clue why.
class _SafeAreaGuide extends StatelessWidget {
  const _SafeAreaGuide({required this.width, required this.height});

  final double width;
  final double height;

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: Padding(
        padding: EdgeInsets.only(
          top: height * StoryOverlay.safeTop,
          bottom: height * StoryOverlay.safeBottom,
          left: width * StoryOverlay.safeSide,
          right: width * StoryOverlay.safeSide,
        ),
        child: DecoratedBox(
          decoration: BoxDecoration(
            border: Border.all(color: Colors.white38, width: 1),
            borderRadius: BorderRadius.circular(10),
          ),
        ),
      ),
    );
  }
}
