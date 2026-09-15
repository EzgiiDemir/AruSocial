import 'dart:typed_data';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/features/widgets/campus_network_image.dart';
import 'package:arucad_campus_prototype/features/widgets/media_frame.dart';

/// Media on a feed post: a network image or in-memory bytes.
///
/// Video was removed from the product on 14 September 2026, along with the
/// labelled video tile and the playback screen this used to push. Historic
/// posts whose media was a clip render nothing rather than a broken frame —
/// there are no such rows in this database, and a tile offering playback
/// that cannot happen is worse than an absence.
class FeedPostMedia extends StatelessWidget {
  final String? imageUrl;
  final Uint8List? imageBytes;
  final String? mimeType;
  final double maxWidth;
  final double borderRadius;
  final double aspectRatio;

  const FeedPostMedia({
    super.key,
    this.imageUrl,
    this.imageBytes,
    this.mimeType,
    this.maxWidth = 236,
    this.borderRadius = 12,
    this.aspectRatio = MediaFrame.post,
  });

  @override
  Widget build(BuildContext context) {
    if (imageBytes != null) {
      return CompactFeedImage(
        maxWidth: maxWidth,
        borderRadius: borderRadius,
        aspectRatio: aspectRatio,
        child: Image.memory(imageBytes!, fit: BoxFit.cover),
      );
    }

    final url = imageUrl;
    if (url != null) {
      return CompactFeedImage(
        maxWidth: maxWidth,
        borderRadius: borderRadius,
        aspectRatio: aspectRatio,
        child: CampusNetworkImage(url, fit: BoxFit.cover),
      );
    }

    return const SizedBox.shrink();
  }
}
