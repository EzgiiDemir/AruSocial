import 'package:flutter/widgets.dart';

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

  const MediaItem({
    required this.id,
    this.url,
    this.dataUri = '',
    required this.fileName,
    required this.uploadedAt,
    required this.uploadedBy,
    this.usedIn = const [],
  });

  /// Network URL when the backend hosted the file; otherwise the mock
  /// data URI. Empty only if both sides were missing (corrupt row).
  String get displaySrc => (url != null && url!.isNotEmpty) ? url! : dataUri;

  bool get isRemote => url != null && url!.isNotEmpty && !url!.startsWith('data:');

  MediaItem copyWith({List<String>? usedIn, String? fileName, String? url, String? dataUri}) =>
      MediaItem(
        id: id,
        url: url ?? this.url,
        dataUri: dataUri ?? this.dataUri,
        fileName: fileName ?? this.fileName,
        uploadedAt: uploadedAt,
        uploadedBy: uploadedBy,
        usedIn: usedIn ?? this.usedIn,
      );

  factory MediaItem.fromJson(Map<String, dynamic> json) => MediaItem(
        id: json['id'] as String,
        url: json['url'] as String?,
        dataUri: json['dataUri'] as String? ?? '',
        fileName: json['fileName'] as String,
        uploadedAt: DateTime.parse(json['uploadedAt'] as String),
        uploadedBy: json['uploadedBy'] as String,
        usedIn: (json['usedIn'] as List<dynamic>?)?.cast<String>() ?? const [],
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        if (url != null) 'url': url,
        if (dataUri.isNotEmpty) 'dataUri': dataUri,
        'fileName': fileName,
        'uploadedAt': uploadedAt.toIso8601String(),
        'uploadedBy': uploadedBy,
        if (usedIn.isNotEmpty) 'usedIn': usedIn,
      };
}

Widget mediaPreview(String src, {BoxFit fit = BoxFit.cover}) {
  if (src.startsWith('data:')) {
    try {
      return Image.memory(Uri.parse(src).data!.contentAsBytes(), fit: fit);
    } catch (_) {
      return const SizedBox.shrink();
    }
  }
  return Image.network(src, fit: fit);
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
