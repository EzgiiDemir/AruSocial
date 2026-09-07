import 'content_block.dart';

enum AdminPageStatus { draft, published }

/// A real, generic "Page" content type — WordPress's "Pages" equivalent
/// (Hakkımızda, SSS, Gizlilik Politikası, …), authored entirely with the
/// block editor rather than tied to Event/Club/Service's fixed fields.
class AdminPage {
  final String id;
  final String title;
  final String slug;
  final List<ContentBlock> blocks;
  final AdminPageStatus status;
  final DateTime updatedAt;
  final String updatedBy;

  const AdminPage({
    required this.id,
    required this.title,
    required this.slug,
    this.blocks = const [],
    this.status = AdminPageStatus.draft,
    required this.updatedAt,
    required this.updatedBy,
  });

  factory AdminPage.fromJson(Map<String, dynamic> json) => AdminPage(
        id: json['id'] as String,
        title: json['title'] as String,
        slug: json['slug'] as String,
        blocks: blocksFromJson(json['blocks']),
        status: AdminPageStatus.values.firstWhere((v) => v.name == json['status'],
            orElse: () => AdminPageStatus.draft),
        updatedAt: DateTime.parse(json['updatedAt'] as String),
        updatedBy: json['updatedBy'] as String,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'title': title,
        'slug': slug,
        'blocks': blocksToJson(blocks),
        'status': status.name,
        'updatedAt': updatedAt.toIso8601String(),
        'updatedBy': updatedBy,
      };
}
