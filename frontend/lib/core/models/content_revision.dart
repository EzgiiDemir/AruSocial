import 'content_block.dart';

class ContentRevision {
  final String id;
  final DateTime savedAt;
  final String editorName;
  final List<ContentBlock> snapshot;

  const ContentRevision({
    required this.id,
    required this.savedAt,
    required this.editorName,
    required this.snapshot,
  });

  factory ContentRevision.fromJson(Map<String, dynamic> json) => ContentRevision(
        id: json['id'] as String,
        savedAt: DateTime.parse(json['savedAt'] as String),
        editorName: json['editorName'] as String,
        snapshot: blocksFromJson(json['snapshot']),
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'savedAt': savedAt.toIso8601String(),
        'editorName': editorName,
        'snapshot': blocksToJson(snapshot),
      };
}
