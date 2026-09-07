import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/network/media_url.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// One library file. Rest responses carry a fetchable [url]; Mock mode
/// still carries an in-memory [dataUri]. Callers should use [displaySrc]
/// so the same widgets work in both modes.
class MediaItem {
  final String id;
  final String? url;
  final String dataUri;
  final String fileName;
  final DateTime uploadedAt;
  final String uploadedBy;

  /// Best-effort "where is this used" tracking — free-text refs like
  /// `place-cover:atelier`. Not a foreign key.
  final List<String> usedIn;

  /// MIME from the server (or inferred in mock). Empty when unknown.
  final String mimeType;

  /// `pending` | `approved` | `rejected` — matches backend media_items.
  final String moderationStatus;

  const MediaItem({
    required this.id,
    this.url,
    this.dataUri = '',
    required this.fileName,
    required this.uploadedAt,
    required this.uploadedBy,
    this.usedIn = const [],
    this.mimeType = '',
    this.moderationStatus = 'approved',
  });

  /// Network URL when the backend hosted the file; otherwise the mock
  /// data URI. Empty only if both sides were missing (corrupt row).
  String get displaySrc => (url != null && url!.isNotEmpty) ? url! : dataUri;

  bool get isRemote => url != null && url!.isNotEmpty && !url!.startsWith('data:');

  bool get isVideo {
    final m = mimeType.toLowerCase();
    if (m.startsWith('video/')) return true;
    final lower = fileName.toLowerCase();
    return lower.endsWith('.mp4') ||
        lower.endsWith('.webm') ||
        lower.endsWith('.mov') ||
        lower.endsWith('.qt');
  }

  MediaItem copyWith({
    List<String>? usedIn,
    String? fileName,
    String? url,
    String? dataUri,
    String? mimeType,
    String? moderationStatus,
  }) =>
      MediaItem(
        id: id,
        url: url ?? this.url,
        dataUri: dataUri ?? this.dataUri,
        fileName: fileName ?? this.fileName,
        uploadedAt: uploadedAt,
        uploadedBy: uploadedBy,
        usedIn: usedIn ?? this.usedIn,
        mimeType: mimeType ?? this.mimeType,
        moderationStatus: moderationStatus ?? this.moderationStatus,
      );

  factory MediaItem.fromJson(Map<String, dynamic> json) => MediaItem(
        id: json['id'] as String,
        url: MediaUrl.resolve(json['url'] as String?),
        dataUri: json['dataUri'] as String? ?? '',
        fileName: json['fileName'] as String,
        uploadedAt: DateTime.parse(json['uploadedAt'] as String),
        uploadedBy: json['uploadedBy'] as String,
        usedIn: (json['usedIn'] as List<dynamic>?)?.cast<String>() ?? const [],
        mimeType: json['mimeType'] as String? ?? '',
        moderationStatus: json['moderationStatus'] as String? ?? 'approved',
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        if (url != null) 'url': url,
        if (dataUri.isNotEmpty) 'dataUri': dataUri,
        'fileName': fileName,
        'uploadedAt': uploadedAt.toIso8601String(),
        'uploadedBy': uploadedBy,
        if (usedIn.isNotEmpty) 'usedIn': usedIn,
        if (mimeType.isNotEmpty) 'mimeType': mimeType,
        'moderationStatus': moderationStatus,
      };
}

/// [cacheWidth] bounds the decoded bitmap size (in physical pixels) instead
/// of decoding the source photo at full resolution — every caller here
/// renders into a thumbnail-sized tile or card, so decoding a 4000px camera
/// upload at full size on every scroll/rebuild was pure wasted CPU. 800px
/// comfortably covers the largest caller (a full-width content-block card
/// image) with headroom for high-DPI screens.
Widget mediaPreview(String src, {BoxFit fit = BoxFit.cover, int cacheWidth = 800}) {
  if (src.startsWith('data:')) {
    try {
      return Image.memory(Uri.parse(src).data!.contentAsBytes(),
          fit: fit, cacheWidth: cacheWidth);
    } catch (_) {
      return const SizedBox.shrink();
    }
  }
  return Image.network(
    MediaUrl.resolve(src) ?? src,
    fit: fit,
    cacheWidth: cacheWidth,
    errorBuilder: (_, __, ___) => const ColoredBox(
      color: ArucadColors.mist,
      child: Center(child: Icon(Icons.broken_image_outlined, color: ArucadColors.muted)),
    ),
  );
}

/// Image/gallery block props may hold a backend [url] (REST) or a mock
/// [dataUri]. Prefer the URL so REST never treats base64 as canonical.
String? mediaSrcFromProps(Map<String, dynamic> props) {
  final url = props['url'] as String?;
  if (url != null && url.isNotEmpty) return url;
  final dataUri = props['dataUri'] as String?;
  if (dataUri != null && dataUri.isNotEmpty) return dataUri;
  return null;
}

Map<String, dynamic> mediaRefFromItem(MediaItem item) => {
      'mediaId': item.id,
      if (item.url != null && item.url!.isNotEmpty) 'url': item.url,
      if (item.dataUri.isNotEmpty) 'dataUri': item.dataUri,
    };
