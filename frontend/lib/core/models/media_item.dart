/// A real uploaded file — in Rest mode, [url] is a genuine server-hosted
/// URL (`MediaController::store()`, real multipart upload to real disk
/// storage); in Mock mode there's no backend to host anything, so
/// [dataUri] carries the file inline as base64 instead (same on-device-only
/// honesty pattern as every other Mock-mode store). Exactly one of the two
/// is set, never both.
class MediaItem {
  final String id;
  final String? url;
  final String? dataUri;
  final String fileName;
  final String? mimeType;
  final String type; // 'image' | 'video' | 'document'
  final int? sizeBytes;
  final DateTime uploadedAt;
  final String uploadedBy;

  /// Best-effort "where is this used" tracking — free-text refs like
  /// `event:123` or `place-cover:atelier`, appended by whichever screen
  /// actually attaches this media to something.
  final List<String> usedIn;

  const MediaItem({
    required this.id,
    this.url,
    this.dataUri,
    required this.fileName,
    this.mimeType,
    this.type = 'image',
    this.sizeBytes,
    required this.uploadedAt,
    required this.uploadedBy,
    this.usedIn = const [],
  });

  MediaItem copyWith({List<String>? usedIn, String? fileName}) => MediaItem(
        id: id,
        url: url,
        dataUri: dataUri,
        fileName: fileName ?? this.fileName,
        mimeType: mimeType,
        type: type,
        sizeBytes: sizeBytes,
        uploadedAt: uploadedAt,
        uploadedBy: uploadedBy,
        usedIn: usedIn ?? this.usedIn,
      );

  factory MediaItem.fromJson(Map<String, dynamic> json) => MediaItem(
        id: json['id'] as String,
        url: json['url'] as String?,
        dataUri: json['dataUri'] as String?,
        fileName: json['fileName'] as String,
        mimeType: json['mimeType'] as String?,
        type: json['type'] as String? ?? 'image',
        sizeBytes: json['sizeBytes'] as int?,
        uploadedAt: DateTime.parse(json['uploadedAt'] as String),
        uploadedBy: json['uploadedBy'] as String,
        usedIn: (json['usedIn'] as List<dynamic>?)?.cast<String>() ?? const [],
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        if (url != null) 'url': url,
        if (dataUri != null) 'dataUri': dataUri,
        'fileName': fileName,
        if (mimeType != null) 'mimeType': mimeType,
        'type': type,
        if (sizeBytes != null) 'sizeBytes': sizeBytes,
        'uploadedAt': uploadedAt.toIso8601String(),
        'uploadedBy': uploadedBy,
        if (usedIn.isNotEmpty) 'usedIn': usedIn,
      };
}
