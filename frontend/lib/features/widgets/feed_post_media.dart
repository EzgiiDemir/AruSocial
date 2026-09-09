import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:arucad_campus_prototype/features/widgets/video_playback_screen.dart';

import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_network_image.dart';
import 'package:arucad_campus_prototype/features/widgets/media_frame.dart';

bool looksLikeVideoUrl(String url) {
  final u = url.toLowerCase();
  return u.contains('/media/video/') ||
      u.endsWith('.mp4') ||
      u.endsWith('.webm') ||
      u.endsWith('.mov') ||
      u.contains('.mp4?') ||
      u.contains('.webm?') ||
      u.contains('.mov?');
}

bool looksLikeVideoName(String fileName) {
  final n = fileName.toLowerCase();
  return n.endsWith('.mp4') || n.endsWith('.webm') || n.endsWith('.mov');
}

/// Image posts use the network/memory frame; video posts are a labelled
/// tile until a transcoding player exists. Automated video moderation is
/// human-queue only.
class FeedPostMedia extends StatelessWidget {
  final String? imageUrl;
  final Uint8List? imageBytes;
  final String? mimeType;
  final bool bytesAreVideo;
  final double maxWidth;
  final double borderRadius;
  final double aspectRatio;

  const FeedPostMedia({
    super.key,
    this.imageUrl,
    this.imageBytes,
    this.mimeType,
    this.bytesAreVideo = false,
    this.maxWidth = 236,
    this.borderRadius = 12,
    this.aspectRatio = MediaFrame.post,
  });

  @override
  Widget build(BuildContext context) {
    final url = imageUrl;
    final video = bytesAreVideo ||
        (mimeType?.toLowerCase().startsWith('video/') ?? false) ||
        (url != null && looksLikeVideoUrl(url));
    if (video) {
      return CompactFeedImage(
        maxWidth: maxWidth,
        borderRadius: borderRadius,
        aspectRatio: aspectRatio,
        child: ColoredBox(
          color: ArucadColors.mist,
          child: Center(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.videocam_outlined,
                    color: ArucadColors.slate, size: 36),
                const SizedBox(height: 8),
                Text(
                  url == null ? 'Video paylaşılmaya hazır' : 'Video',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: ArucadColors.muted, fontSize: 12),
                ),
                if (url != null)
                  TextButton.icon(
                    icon: const Icon(Icons.play_arrow),
                    label: const Text('Oynat'),
                    onPressed: () => Navigator.of(context).push(
                        MaterialPageRoute(
                            builder: (_) => VideoPlaybackScreen(url: url))),
                  ),
              ],
            ),
          ),
        ),
      );
    }
    if (imageBytes != null) {
      return CompactFeedImage(
        maxWidth: maxWidth,
        borderRadius: borderRadius,
        aspectRatio: aspectRatio,
        child: Image.memory(imageBytes!, fit: BoxFit.cover),
      );
    }
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
