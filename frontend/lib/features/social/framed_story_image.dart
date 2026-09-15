import 'dart:typed_data';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/models/story_framing.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_network_image.dart';

/// A photo, drawn the way its author framed it.
///
/// Used by every surface that shows one — story editor, story viewer,
/// compose preview, feed, post detail — deliberately: "what you see is
/// what gets posted" only holds if they are all the same widget. Two
/// renderers that agree today are two renderers that disagree after the
/// next change, and the person who finds out is the student whose post
/// came out different from the preview.
class FramedImage extends StatelessWidget {
  const FramedImage({
    super.key,
    required this.framing,
    this.bytes,
    this.url,
    this.fallbackBackground = Colors.black,
    this.altText,
  });

  final MediaFraming framing;

  /// Local bytes (composing, or an optimistic post) or a remote URL.
  final Uint8List? bytes;
  final String? url;

  final Color fallbackBackground;

  /// What the picture shows, for anyone who cannot see it. Null means the
  /// author did not say, and the image is then hidden from the screen
  /// reader rather than announced as an unlabelled graphic.
  final String? altText;

  Color get _background => framing.backgroundColor != null
      ? Color(framing.backgroundColor!)
      : fallbackBackground;

  @override
  Widget build(BuildContext context) {
    final image = bytes != null
        ? Image.memory(
            bytes!,
            fit: _boxFit,
            // The default is low on some platforms, and this is the one
            // place in the app where the image *is* the content.
            filterQuality: FilterQuality.high,
          )
        : url != null
            ? CampusNetworkImage(url!, fit: _boxFit)
            : const SizedBox.shrink();

    final framed = ColoredBox(
      color: _background,
      child: ClipRect(
        child: LayoutBuilder(
          builder: (context, constraints) {
            // Offsets are fractions of the frame, so a photo composed on
            // one phone lands in the same place on another.
            final dx = MediaFraming.clampOffset(framing.offsetX, framing.scale) *
                constraints.maxWidth;
            final dy = MediaFraming.clampOffset(framing.offsetY, framing.scale) *
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

    final label = altText?.trim();
    if (label == null || label.isEmpty) {
      // An unlabelled image is noise to a screen reader: it can only say
      // "graphic", which tells the listener nothing and costs them a stop.
      return ExcludeSemantics(child: framed);
    }

    return Semantics(image: true, label: label, child: framed);
  }

  BoxFit get _boxFit =>
      framing.fit == FrameFit.fit ? BoxFit.contain : BoxFit.cover;
}

/// The old name. Stories were here first and read perfectly well as
/// `FramedStoryImage`; nothing is gained by churning them.
typedef FramedStoryImage = FramedImage;
