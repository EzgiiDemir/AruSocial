import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:arucad_campus_prototype/core/models/content_block.dart';
import 'package:arucad_campus_prototype/core/models/media_item.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// Read-only render of a block list on a real detail screen (Event/Club/
/// Service) — the student-facing counterpart to `ContentBlockEditor`.
class BlockRenderer extends StatelessWidget {
  final List<ContentBlock> blocks;
  const BlockRenderer({super.key, required this.blocks});

  @override
  Widget build(BuildContext context) {
    if (blocks.isEmpty) return const SizedBox.shrink();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [for (final block in blocks) _renderBlock(block)],
    );
  }

  Widget _renderBlock(ContentBlock block) {
    switch (block.type) {
      case BlockType.heading:
        final level = block.props['level'] as int? ?? 2;
        final size = switch (level) { 1 => 22.0, 2 => 18.0, _ => 15.0 };
        return Padding(
          padding: const EdgeInsets.only(top: 14, bottom: 6),
          child: Text(block.props['text'] as String? ?? '',
              style: TextStyle(fontWeight: FontWeight.w900, fontSize: size)),
        );
      case BlockType.paragraph:
        return Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: Text(block.props['text'] as String? ?? '',
              style: const TextStyle(fontSize: 15, height: 1.4)),
        );
      case BlockType.quote:
        return Container(
          margin: const EdgeInsets.only(bottom: 10),
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          decoration: BoxDecoration(
            border: Border(left: BorderSide(color: ArucadColors.primary, width: 3)),
            color: ArucadColors.mist,
          ),
          child: Text(block.props['text'] as String? ?? '',
              style: const TextStyle(fontStyle: FontStyle.italic, fontSize: 14.5)),
        );
      case BlockType.divider:
        return const Divider(height: 24);
      case BlockType.button:
        final label = block.props['label'] as String? ?? '';
        final url = block.props['url'] as String? ?? '';
        if (label.isEmpty) return const SizedBox.shrink();
        return Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: OutlinedButton(
            onPressed: url.isEmpty
                ? null
                : () => launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication),
            child: Text(label),
          ),
        );
      case BlockType.list:
        final items = (block.props['items'] as List?)?.cast<String>() ?? const [];
        final ordered = block.props['ordered'] as bool? ?? false;
        return Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              for (var i = 0; i < items.length; i++)
                if (items[i].isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 4),
                    child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(ordered ? '${i + 1}.' : '•',
                          style: const TextStyle(fontWeight: FontWeight.w700)),
                      const SizedBox(width: 8),
                      Expanded(child: Text(items[i], style: const TextStyle(fontSize: 14.5))),
                    ]),
                  ),
            ],
          ),
        );
      case BlockType.image:
        final src = mediaSrcFromProps(block.props);
        if (src == null) return const SizedBox.shrink();
        return Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: ClipRRect(
            borderRadius: BorderRadius.circular(14),
            child: AspectRatio(
              aspectRatio: 16 / 9,
              child: mediaPreview(src),
            ),
          ),
        );
      case BlockType.gallery:
        final rawItems = (block.props['items'] as List?) ?? const [];
        if (rawItems.isEmpty) return const SizedBox.shrink();
        return Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: SizedBox(
            height: 110,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              itemCount: rawItems.length,
              separatorBuilder: (_, __) => const SizedBox(width: 8),
              itemBuilder: (context, i) {
                final src = mediaSrcFromProps(
                    Map<String, dynamic>.from(rawItems[i] as Map));
                if (src == null) return const SizedBox.shrink();
                return ClipRRect(
                  borderRadius: BorderRadius.circular(10),
                  child: SizedBox(width: 110, child: mediaPreview(src)),
                );
              },
            ),
          ),
        );
    }
  }
}
