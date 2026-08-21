class MediaItem {
  final String id;
  final String dataUri;
  final String fileName;
  final DateTime uploadedAt;
  final String uploadedBy;

  /// Best-effort "where is this used" tracking — free-text refs like
  /// `event:123` or `place-cover:atelier`, appended by whichever screen
  /// actually attaches this media to something. Not a real foreign-key
  /// constraint (there's no database), just an honest breadcrumb trail.
  final List<String> usedIn;

  const MediaItem({
    required this.id,
    required this.dataUri,
    required this.fileName,
    required this.uploadedAt,
    required this.uploadedBy,
    this.usedIn = const [],
  });

  MediaItem copyWith({List<String>? usedIn, String? fileName}) => MediaItem(
        id: id,
        dataUri: dataUri,
        fileName: fileName ?? this.fileName,
        uploadedAt: uploadedAt,
        uploadedBy: uploadedBy,
        usedIn: usedIn ?? this.usedIn,
      );

  factory MediaItem.fromJson(Map<String, dynamic> json) => MediaItem(
        id: json['id'] as String,
        dataUri: json['dataUri'] as String,
        fileName: json['fileName'] as String,
        uploadedAt: DateTime.parse(json['uploadedAt'] as String),
        uploadedBy: json['uploadedBy'] as String,
        usedIn: (json['usedIn'] as List<dynamic>?)?.cast<String>() ?? const [],
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'dataUri': dataUri,
        'fileName': fileName,
        'uploadedAt': uploadedAt.toIso8601String(),
        'uploadedBy': uploadedBy,
        if (usedIn.isNotEmpty) 'usedIn': usedIn,
      };
}
