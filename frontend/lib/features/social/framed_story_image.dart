import 'dart:typed_data';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/models/story_framing.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_network_image.dart';

/// A story photo, drawn the way its author framed it.
///
/// Used by both the editor and the viewer, deliberately: "what you see is
/// what gets posted" only holds if the two are the same widget. Two
/// renderers that agree today are two renderers that disagree after the
/// next change, and the person who finds out is the student whose story
/// came out different from the preview.
class FramedStoryImage extends StatelessWidget {
  const FramedStoryImage({
    super.key,
    required this.framing,
    this.bytes,
    this.url,
    this.fallbackBackground = Colors.black,
  });

  final StoryFraming framing;

  /// Local bytes (composing, or an optimistic post) or a remote URL.
  final Uint8List? bytes;
  final String? url;

  final Color fallbackBackground;

  Color get _background => framing.backgroundColor != null
      ? Color(framing.backgroundColor!)
      : fallbackBackground;

  @override
  Widget build(BuildContext context) {
    final image = bytes != null
        ? Image.memory(
            bytes!,
            fit: _boxFit,
            // The default is low on some platforms, and a story is the one
            // place in the app where the image *is* the content.
            filterQuality: FilterQuality.high,
          )
        : url != null
            ? CampusNetworkImage(url!, fit: _boxFit)
            : const SizedBox.shrink();

    return ColoredBox(
      color: _background,
      child: ClipRect(
        child: LayoutBuilder(
          builder: (context, constraints) {
            // Offsets are fractions of the frame, so a story composed on
            // one phone lands in the same place on another.
            final dx = StoryFraming.clampOffset(framing.offsetX, framing.scale) *
                constraints.maxWidth;
            final dy = StoryFraming.clampOffset(framing.offsetY, framing.scale) *
                constraints.maxHeight;

            return Transform.translate(
              offset: Offset(dx, dy),
              child: Transform.scale(
                scale: framing.scale,
                child: SizedBox.expand(child: image),
              ),
            );
          },
        ),
      ),
    );
  }

  BoxFit get _boxFit =>
      framing.fit == FrameFit.fit ? BoxFit.contain : BoxFit.cover;
}
