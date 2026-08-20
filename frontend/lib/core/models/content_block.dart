/// A single block in a WordPress-Gutenberg-style content editor. Kept as a
/// generic `{type, props}` pair (matching the structure the product brief
/// asked for) rather than a subclass per type, so new block types don't need
/// a schema/migration change — just a new `BlockType` case and a renderer.
///
/// Deliberately NOT included (yet): Video/Embed/HTML/Table/Form/Map blocks.
/// Each needs real infrastructure this repo doesn't have — a CDN/transcoder
/// for video, a server-side oEmbed proxy for Embed, and HTML is a real XSS
/// risk to render un-sandboxed inside a mobile app. Adding them as decorative
/// non-functional buttons would violate the "no fake features" rule, so
/// they're left out until that infrastructure exists rather than faked.
enum BlockType { heading, paragraph, image, list, quote, divider, button, gallery }

class ContentBlock {
  final String id;
  final BlockType type;
  final Map<String, dynamic> props;

  ContentBlock({String? id, required this.type, this.props = const {}})
      : id = id ?? '${type.name}-${DateTime.now().microsecondsSinceEpoch}';

  ContentBlock copyWith({Map<String, dynamic>? props}) =>
      ContentBlock(id: id, type: type, props: props ?? this.props);

  factory ContentBlock.fromJson(Map<String, dynamic> json) => ContentBlock(
        id: json['id'] as String,
        type: BlockType.values.byName(json['type'] as String),
        props: Map<String, dynamic>.from(json['props'] as Map? ?? const {}),
      );

  Map<String, dynamic> toJson() => {'id': id, 'type': type.name, 'props': props};
}

List<ContentBlock> blocksFromJson(dynamic raw) {
  if (raw is! List) return const [];
  return raw.map((e) => ContentBlock.fromJson(e as Map<String, dynamic>)).toList();
}

List<Map<String, dynamic>> blocksToJson(List<ContentBlock> blocks) =>
    blocks.map((b) => b.toJson()).toList();

/// Flattens blocks down to plain text — used anywhere that only ever wanted
/// a short string (Ask ARUCAD's context, search matching), not a rich
/// render. Images/dividers/buttons contribute nothing textual.
String blocksToPlainText(List<ContentBlock> blocks) => blocks
    .map((b) => switch (b.type) {
          BlockType.heading || BlockType.paragraph || BlockType.quote =>
            b.props['text'] as String? ?? '',
          BlockType.list => ((b.props['items'] as List?)?.cast<String>() ?? const [])
              .join(', '),
          BlockType.button => b.props['label'] as String? ?? '',
          BlockType.image || BlockType.divider || BlockType.gallery => '',
        })
    .where((s) => s.isNotEmpty)
    .join(' ');
