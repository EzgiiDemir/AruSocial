import 'dart:async';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/models/content_block.dart';
import 'package:arucad_campus_prototype/core/models/media_item.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/draft_store.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/admin/media/media_library_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/block_renderer.dart';

/// Shows this content item's real save history — picking a revision hands
/// back its block snapshot so the caller can restore it into the (still
/// unsaved) edit form; nothing is written until the admin presses Kaydet,
/// same as any other field in that dialog.
Future<List<ContentBlock>?> openRevisionHistory(
  BuildContext context, {
  required CampusRepository repository,
  required String contentKey,
}) async {
  final revisions = await repository.getRevisions(contentKey);
  if (!context.mounted) return null;
  if (revisions.isEmpty) {
    ScaffoldMessenger.of(context)
        .showSnackBar(const SnackBar(content: Text('Bu içerik için henüz kayıtlı bir sürüm yok.')));
    return null;
  }
  return showModalBottomSheet<List<ContentBlock>>(
    context: context,
    isScrollControlled: true,
    builder: (ctx) => DraggableScrollableSheet(
      initialChildSize: 0.7,
      maxChildSize: 0.95,
      expand: false,
      builder: (ctx, scrollController) => Column(children: [
        const Padding(
          padding: EdgeInsets.fromLTRB(20, 16, 20, 8),
          child: Align(
            alignment: Alignment.centerLeft,
            child: Text('Sürüm Geçmişi',
                style: TextStyle(fontWeight: FontWeight.w900, fontSize: 17)),
          ),
        ),
        Expanded(
          child: ListView.separated(
            controller: scrollController,
            padding: const EdgeInsets.symmetric(horizontal: 16),
            itemCount: revisions.length,
            separatorBuilder: (_, __) => const Divider(height: 1),
            itemBuilder: (context, i) {
              final r = revisions[i];
              return ListTile(
                leading: const Icon(Icons.history_outlined, color: ArucadColors.primary),
                title: Text(
                    '${r.savedAt.day}.${r.savedAt.month}.${r.savedAt.year} '
                    '${r.savedAt.hour.toString().padLeft(2, '0')}:${r.savedAt.minute.toString().padLeft(2, '0')} · ${r.editorName}',
                    style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
                subtitle: Text('${r.snapshot.length} blok'),
                trailing: TextButton(
                  onPressed: () async {
                    final confirm = await showDialog<bool>(
                      context: context,
                      builder: (dctx) => AlertDialog(
                        title: const Text('Bu sürümü geri yükle?'),
                        content: SingleChildScrollView(child: BlockRenderer(blocks: r.snapshot)),
                        actions: [
                          TextButton(
                              onPressed: () => Navigator.pop(dctx, false),
                              child: const Text('Vazgeç')),
                          FilledButton(
                              onPressed: () => Navigator.pop(dctx, true),
                              child: const Text('Geri Yükle')),
                        ],
                      ),
                    );
                    if (confirm == true && context.mounted) {
                      Navigator.of(context).pop(r.snapshot);
                    }
                  },
                  child: const Text('Görüntüle / Geri Yükle'),
                ),
              );
            },
          ),
        ),
      ]),
    ),
  );
}

/// Pushes a full-screen block editor and returns the edited block list —
/// used from the small `AlertDialog`-based edit forms (Events/Clubs/
/// Services), where a full Gutenberg-style editor doesn't fit inline.
///
/// [draftKey] (e.g. `event:123`) turns on real autosave for this session —
/// left null for not-yet-created content, which has no stable id to key a
/// draft against.
Future<List<ContentBlock>> openBlockEditor(
  BuildContext context, {
  required String title,
  required List<ContentBlock> initialBlocks,
  required String uploaderName,
  required CampusRepository repository,
  String? draftKey,
}) async {
  final result = await Navigator.of(context).push<List<ContentBlock>>(
    MaterialPageRoute(
      builder: (_) => _BlockEditorPage(
        title: title,
        initialBlocks: initialBlocks,
        uploaderName: uploaderName,
        repository: repository,
        draftKey: draftKey,
      ),
    ),
  );
  return result ?? initialBlocks;
}

class _BlockEditorPage extends StatefulWidget {
  final String title;
  final List<ContentBlock> initialBlocks;
  final String uploaderName;
  final CampusRepository repository;
  final String? draftKey;

  const _BlockEditorPage({
    required this.title,
    required this.initialBlocks,
    required this.uploaderName,
    required this.repository,
    this.draftKey,
  });

  @override
  State<_BlockEditorPage> createState() => _BlockEditorPageState();
}

enum _SaveState { idle, saving, saved }

class _BlockEditorPageState extends State<_BlockEditorPage> {
  late List<ContentBlock> _blocks;
  Timer? _debounce;
  _SaveState _saveState = _SaveState.idle;

  @override
  void initState() {
    super.initState();
    _blocks = widget.initialBlocks;
    if (widget.draftKey != null) _checkForDraft();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  Future<void> _checkForDraft() async {
    final draft = await DraftStore.load(widget.draftKey!);
    if (draft == null || !mounted) return;
    final restore = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Kaydedilmemiş taslak bulundu'),
        content: Text(
            '${draft.savedAt.hour.toString().padLeft(2, '0')}:${draft.savedAt.minute.toString().padLeft(2, '0')} '
            'tarihinde otomatik kaydedilmiş bir taslak var. Geri yüklensin mi?'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false), child: const Text('Yoksay')),
          FilledButton(
              onPressed: () => Navigator.pop(ctx, true), child: const Text('Geri Yükle')),
        ],
      ),
    );
    if (restore == true && mounted) {
      setState(() => _blocks = draft.blocks);
    }
  }

  void _onChanged(List<ContentBlock> blocks) {
    _blocks = blocks;
    if (widget.draftKey == null) return;
    _debounce?.cancel();
    setState(() => _saveState = _SaveState.saving);
    _debounce = Timer(const Duration(seconds: 2), () async {
      await DraftStore.save(widget.draftKey!, _blocks);
      if (mounted) setState(() => _saveState = _SaveState.saved);
    });
  }

  Future<void> _save() async {
    if (widget.draftKey != null) await DraftStore.clear(widget.draftKey!);
    if (mounted) Navigator.of(context).pop(_blocks);
  }

  String get _saveLabel => switch (_saveState) {
        _SaveState.idle => '',
        _SaveState.saving => 'Kaydediliyor…',
        _SaveState.saved => 'Taslak kaydedildi',
      };

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.title),
        bottom: widget.draftKey == null || _saveState == _SaveState.idle
            ? null
            : PreferredSize(
                preferredSize: const Size.fromHeight(20),
                child: Padding(
                  padding: const EdgeInsets.only(bottom: 6),
                  child: Text(_saveLabel,
                      style: const TextStyle(color: Colors.white70, fontSize: 11)),
                ),
              ),
        actions: [
          TextButton(
            onPressed: _save,
            child: const Text('Kaydet', style: TextStyle(color: Colors.white)),
          ),
        ],
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: ContentBlockEditor(
          initialBlocks: _blocks,
          uploaderName: widget.uploaderName,
          repository: widget.repository,
          onChanged: _onChanged,
        ),
      ),
    );
  }
}

const _blockTypeLabels = {
  BlockType.heading: ('Başlık', Icons.title),
  BlockType.paragraph: ('Metin', Icons.notes),
  BlockType.image: ('Görsel', Icons.image_outlined),
  BlockType.gallery: ('Galeri', Icons.photo_library_outlined),
  BlockType.list: ('Liste', Icons.format_list_bulleted),
  BlockType.quote: ('Alıntı', Icons.format_quote_outlined),
  BlockType.button: ('Buton', Icons.smart_button_outlined),
  BlockType.divider: ('Ayırıcı', Icons.horizontal_rule),
};

ContentBlock _defaultBlockFor(BlockType type) => switch (type) {
      BlockType.heading => ContentBlock(type: type, props: {'text': '', 'level': 2}),
      BlockType.paragraph => ContentBlock(type: type, props: {'text': ''}),
      BlockType.image => ContentBlock(type: type, props: {}),
      BlockType.gallery => ContentBlock(type: type, props: {'items': <Map>[]}),
      BlockType.list => ContentBlock(type: type, props: {'items': <String>[''], 'ordered': false}),
      BlockType.quote => ContentBlock(type: type, props: {'text': ''}),
      BlockType.button => ContentBlock(type: type, props: {'label': '', 'url': ''}),
      BlockType.divider => ContentBlock(type: type, props: {}),
    };

/// Real WordPress-Gutenberg-style block editor: add/reorder/duplicate/
/// delete blocks, edit each inline. No rich inline text formatting (bold/
/// italic/etc.) — there's no rich-text editing package in this project, and
/// faking formatting buttons that don't actually format anything would be
/// exactly the "decorative UI" the brief forbids. Plain, real text editing
/// per block instead.
class ContentBlockEditor extends StatefulWidget {
  final List<ContentBlock> initialBlocks;
  final ValueChanged<List<ContentBlock>> onChanged;
  final String uploaderName;
  final CampusRepository repository;

  const ContentBlockEditor({
    super.key,
    required this.initialBlocks,
    required this.onChanged,
    required this.uploaderName,
    required this.repository,
  });

  @override
  State<ContentBlockEditor> createState() => ContentBlockEditorState();
}

class ContentBlockEditorState extends State<ContentBlockEditor> {
  late List<ContentBlock> blocks;

  @override
  void initState() {
    super.initState();
    blocks = [...widget.initialBlocks];
  }

  void _emit() => widget.onChanged(blocks);

  Future<void> _addBlock() async {
    final type = await showModalBottomSheet<BlockType>(
      context: context,
      builder: (ctx) => SafeArea(
        child: GridView.count(
          shrinkWrap: true,
          crossAxisCount: 4,
          padding: const EdgeInsets.all(16),
          children: [
            for (final entry in _blockTypeLabels.entries)
              InkWell(
                onTap: () => Navigator.of(ctx).pop(entry.key),
                borderRadius: BorderRadius.circular(12),
                child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                  Icon(entry.value.$2, color: ArucadColors.primary),
                  const SizedBox(height: 6),
                  Text(entry.value.$1, style: const TextStyle(fontSize: 11)),
                ]),
              ),
          ],
        ),
      ),
    );
    if (type == null) return;
    setState(() => blocks.add(_defaultBlockFor(type)));
    _emit();
  }

  void _update(int i, ContentBlock updated) {
    setState(() => blocks[i] = updated);
    _emit();
  }

  void _delete(int i) {
    setState(() => blocks.removeAt(i));
    _emit();
  }

  void _duplicate(int i) {
    setState(() =>
        blocks.insert(i + 1, ContentBlock(type: blocks[i].type, props: Map.of(blocks[i].props))));
    _emit();
  }

  void _reorder(int oldIndex, int newIndex) {
    setState(() {
      if (newIndex > oldIndex) newIndex -= 1;
      final item = blocks.removeAt(oldIndex);
      blocks.insert(newIndex, item);
    });
    _emit();
  }

  @override
  Widget build(BuildContext context) {
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      if (blocks.isEmpty)
        const Padding(
          padding: EdgeInsets.symmetric(vertical: 24),
          child: Center(
            child: Text('Henüz blok yok. Aşağıdaki "Blok Ekle" ile başla.',
                style: TextStyle(color: ArucadColors.muted)),
          ),
        )
      else
        ReorderableListView.builder(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          buildDefaultDragHandles: false,
          itemCount: blocks.length,
          onReorder: _reorder,
          itemBuilder: (context, i) => _BlockCard(
            key: ValueKey(blocks[i].id),
            index: i,
            block: blocks[i],
            uploaderName: widget.uploaderName,
            repository: widget.repository,
            onChanged: (b) => _update(i, b),
            onDelete: () => _delete(i),
            onDuplicate: () => _duplicate(i),
          ),
        ),
      const SizedBox(height: 8),
      OutlinedButton.icon(
        onPressed: _addBlock,
        icon: const Icon(Icons.add),
        label: const Text('Blok Ekle'),
      ),
    ]);
  }
}

class _BlockCard extends StatelessWidget {
  final int index;
  final ContentBlock block;
  final String uploaderName;
  final CampusRepository repository;
  final ValueChanged<ContentBlock> onChanged;
  final VoidCallback onDelete;
  final VoidCallback onDuplicate;

  const _BlockCard({
    required super.key,
    required this.index,
    required this.block,
    required this.uploaderName,
    required this.repository,
    required this.onChanged,
    required this.onDelete,
    required this.onDuplicate,
  });

  @override
  Widget build(BuildContext context) => Card(
        margin: const EdgeInsets.only(bottom: 10),
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              ReorderableDragStartListener(
                index: index,
                child: const Padding(
                  padding: EdgeInsets.only(right: 6),
                  child: Icon(Icons.drag_indicator, size: 18, color: ArucadColors.muted),
                ),
              ),
              Icon(_blockTypeLabels[block.type]!.$2, size: 16, color: ArucadColors.primary),
              const SizedBox(width: 6),
              Text(_blockTypeLabels[block.type]!.$1,
                  style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 12)),
              const Spacer(),
              IconButton(
                  visualDensity: VisualDensity.compact,
                  iconSize: 18,
                  onPressed: onDuplicate,
                  icon: const Icon(Icons.copy_outlined)),
              IconButton(
                  visualDensity: VisualDensity.compact,
                  iconSize: 18,
                  onPressed: onDelete,
                  icon: const Icon(Icons.delete_outline)),
            ]),
            _BlockBody(
              block: block,
              uploaderName: uploaderName,
              repository: repository,
              onChanged: onChanged,
            ),
          ]),
        ),
      );
}

class _BlockBody extends StatelessWidget {
  final ContentBlock block;
  final String uploaderName;
  final CampusRepository repository;
  final ValueChanged<ContentBlock> onChanged;
  const _BlockBody({
    required this.block,
    required this.uploaderName,
    required this.repository,
    required this.onChanged,
  });

  @override
  Widget build(BuildContext context) {
    switch (block.type) {
      case BlockType.heading:
        return Row(children: [
          Expanded(
            child: TextFormField(
              initialValue: block.props['text'] as String? ?? '',
              decoration: const InputDecoration(hintText: 'Başlık metni'),
              onChanged: (v) => onChanged(block.copyWith(props: {...block.props, 'text': v})),
            ),
          ),
          const SizedBox(width: 8),
          DropdownButton<int>(
            value: block.props['level'] as int? ?? 2,
            items: const [1, 2, 3]
                .map((l) => DropdownMenuItem(value: l, child: Text('H$l')))
                .toList(),
            onChanged: (v) => onChanged(block.copyWith(props: {...block.props, 'level': v})),
          ),
        ]);
      case BlockType.paragraph:
      case BlockType.quote:
        return TextFormField(
          initialValue: block.props['text'] as String? ?? '',
          maxLines: block.type == BlockType.quote ? 2 : 4,
          decoration: InputDecoration(
              hintText: block.type == BlockType.quote ? 'Alıntı metni' : 'Metin'),
          onChanged: (v) => onChanged(block.copyWith(props: {...block.props, 'text': v})),
        );
      case BlockType.divider:
        return const Divider(height: 24);
      case BlockType.button:
        return Column(children: [
          TextFormField(
            initialValue: block.props['label'] as String? ?? '',
            decoration: const InputDecoration(hintText: 'Buton yazısı'),
            onChanged: (v) => onChanged(block.copyWith(props: {...block.props, 'label': v})),
          ),
          const SizedBox(height: 6),
          TextFormField(
            initialValue: block.props['url'] as String? ?? '',
            decoration: const InputDecoration(hintText: 'Bağlantı (https://…)'),
            onChanged: (v) => onChanged(block.copyWith(props: {...block.props, 'url': v})),
          ),
        ]);
      case BlockType.list:
        final items = ((block.props['items'] as List?)?.cast<String>() ?? const ['']).toList();
        final ordered = block.props['ordered'] as bool? ?? false;
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SwitchListTile(
            dense: true,
            contentPadding: EdgeInsets.zero,
            title: const Text('Numaralı liste', style: TextStyle(fontSize: 12.5)),
            value: ordered,
            onChanged: (v) => onChanged(block.copyWith(props: {...block.props, 'ordered': v})),
          ),
          for (var i = 0; i < items.length; i++)
            Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: Row(children: [
                Text(ordered ? '${i + 1}.' : '•', style: const TextStyle(color: ArucadColors.muted)),
                const SizedBox(width: 8),
                Expanded(
                  child: TextFormField(
                    initialValue: items[i],
                    decoration: const InputDecoration(hintText: 'Madde'),
                    onChanged: (v) {
                      final next = [...items];
                      next[i] = v;
                      onChanged(block.copyWith(props: {...block.props, 'items': next}));
                    },
                  ),
                ),
                IconButton(
                  visualDensity: VisualDensity.compact,
                  icon: const Icon(Icons.close, size: 16),
                  onPressed: () {
                    final next = [...items]..removeAt(i);
                    onChanged(block.copyWith(props: {...block.props, 'items': next}));
                  },
                ),
              ]),
            ),
          TextButton.icon(
            onPressed: () => onChanged(
                block.copyWith(props: {...block.props, 'items': [...items, '']})),
            icon: const Icon(Icons.add, size: 16),
            label: const Text('Madde ekle', style: TextStyle(fontSize: 12.5)),
          ),
        ]);
      case BlockType.image:
        final src = mediaSrcFromProps(block.props);
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          if (src != null)
            ClipRRect(
              borderRadius: BorderRadius.circular(10),
              child: AspectRatio(
                aspectRatio: 16 / 9,
                child: mediaPreview(src),
              ),
            ),
          const SizedBox(height: 6),
          OutlinedButton.icon(
            onPressed: () async {
              final item = await pickMediaItem(context,
                  repository: repository, uploaderName: uploaderName);
              if (item == null) return;
              onChanged(block.copyWith(
                  props: {...block.props, ...mediaRefFromItem(item)}));
            },
            icon: const Icon(Icons.photo_library_outlined, size: 16),
            label: Text(src == null ? 'Medya Kütüphanesinden Seç' : 'Değiştir'),
          ),
          const SizedBox(height: 6),
          TextFormField(
            initialValue: block.props['alt'] as String? ?? '',
            decoration: const InputDecoration(hintText: 'Alt metin (erişilebilirlik)'),
            onChanged: (v) => onChanged(block.copyWith(props: {...block.props, 'alt': v})),
          ),
        ]);
      case BlockType.gallery:
        final rawItems = (block.props['items'] as List?) ?? const [];
        final items = rawItems.map((e) => Map<String, dynamic>.from(e as Map)).toList();
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          if (items.isNotEmpty)
            SizedBox(
              height: 80,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                itemCount: items.length,
                separatorBuilder: (_, __) => const SizedBox(width: 6),
                itemBuilder: (context, i) {
                  final src = mediaSrcFromProps(items[i]);
                  if (src == null) return const SizedBox.shrink();
                  return ClipRRect(
                    borderRadius: BorderRadius.circular(8),
                    child: SizedBox(width: 80, child: mediaPreview(src)),
                  );
                },
              ),
            ),
          const SizedBox(height: 6),
          OutlinedButton.icon(
            onPressed: () async {
              final picked = await pickMultipleMediaItems(context,
                  repository: repository, uploaderName: uploaderName);
              if (picked.isEmpty) return;
              final next = [
                ...items,
                for (final m in picked) mediaRefFromItem(m),
              ];
              onChanged(block.copyWith(props: {...block.props, 'items': next}));
            },
            icon: const Icon(Icons.add_photo_alternate_outlined, size: 16),
            label: const Text('Görsel Ekle'),
          ),
        ]);
    }
  }
}
