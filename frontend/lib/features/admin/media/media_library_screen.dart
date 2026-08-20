import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import 'package:arucad_campus_prototype/core/models/media_item.dart';
import 'package:arucad_campus_prototype/core/services/media_library_store.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// Opens the Media Library as a picker (used by the block editor's Image
/// block) — returns the item the admin picked, whether newly uploaded or
/// already in the library. Returns null if the sheet was dismissed.
Future<MediaItem?> pickMediaItem(BuildContext context, {required String uploaderName}) {
  return showModalBottomSheet<MediaItem>(
    context: context,
    isScrollControlled: true,
    builder: (ctx) => _MediaPickerSheet(uploaderName: uploaderName, multiple: false),
  );
}

/// Same as [pickMediaItem] but allows selecting several images at once —
/// used by the Gallery block.
Future<List<MediaItem>> pickMultipleMediaItems(BuildContext context,
    {required String uploaderName}) async {
  final result = await showModalBottomSheet<List<MediaItem>>(
    context: context,
    isScrollControlled: true,
    builder: (ctx) => _MediaPickerSheet(uploaderName: uploaderName, multiple: true),
  );
  return result ?? const [];
}

class _MediaPickerSheet extends StatefulWidget {
  final String uploaderName;
  final bool multiple;
  const _MediaPickerSheet({required this.uploaderName, required this.multiple});

  @override
  State<_MediaPickerSheet> createState() => _MediaPickerSheetState();
}

class _MediaPickerSheetState extends State<_MediaPickerSheet> {
  late Future<List<MediaItem>> _future;
  final Set<String> _selected = {};
  bool _uploading = false;

  @override
  void initState() {
    super.initState();
    _future = MediaLibraryStore.items();
  }

  void _reload() => setState(() => _future = MediaLibraryStore.items());

  Future<void> _upload() async {
    setState(() => _uploading = true);
    try {
      final picker = ImagePicker();
      final picked = widget.multiple
          ? await picker.pickMultiImage(imageQuality: 80)
          : [await picker.pickImage(source: ImageSource.gallery, imageQuality: 80)]
              .whereType<XFile>()
              .toList();
      for (final file in picked) {
        final bytes = await file.readAsBytes();
        await MediaLibraryStore.upload(bytes,
            fileName: file.name, uploadedBy: widget.uploaderName);
      }
    } finally {
      if (mounted) {
        setState(() => _uploading = false);
        _reload();
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return DraggableScrollableSheet(
      initialChildSize: 0.75,
      minChildSize: 0.4,
      maxChildSize: 0.95,
      expand: false,
      builder: (context, scrollController) => Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
          child: Row(children: [
            Expanded(
                child: Text(widget.multiple ? 'Görsel(ler) Seç' : 'Görsel Seç',
                    style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 17))),
            TextButton.icon(
              onPressed: _uploading ? null : _upload,
              icon: _uploading
                  ? const SizedBox(
                      width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Icons.upload_outlined, size: 18),
              label: const Text('Yükle'),
            ),
          ]),
        ),
        Expanded(
          child: FutureBuilder<List<MediaItem>>(
            future: _future,
            builder: (context, snap) {
              if (!snap.hasData) return const Center(child: CircularProgressIndicator());
              final items = snap.data!;
              if (items.isEmpty) {
                return const Center(
                  child: Text('Medya kütüphanesi boş — "Yükle" ile ilk görseli ekle.',
                      style: TextStyle(color: ArucadColors.muted)),
                );
              }
              return GridView.builder(
                controller: scrollController,
                padding: const EdgeInsets.all(16),
                gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                    crossAxisCount: 3, crossAxisSpacing: 8, mainAxisSpacing: 8),
                itemCount: items.length,
                itemBuilder: (context, i) {
                  final item = items[i];
                  final isSelected = _selected.contains(item.id);
                  return GestureDetector(
                    onTap: () {
                      if (!widget.multiple) {
                        Navigator.of(context).pop(item);
                        return;
                      }
                      setState(() {
                        if (isSelected) {
                          _selected.remove(item.id);
                        } else {
                          _selected.add(item.id);
                        }
                      });
                    },
                    child: Stack(fit: StackFit.expand, children: [
                      ClipRRect(
                        borderRadius: BorderRadius.circular(10),
                        child: _MediaThumb(item: item),
                      ),
                      if (isSelected)
                        Container(
                          decoration: BoxDecoration(
                            border: Border.all(color: ArucadColors.primary, width: 3),
                            borderRadius: BorderRadius.circular(10),
                          ),
                          alignment: Alignment.topRight,
                          child: const Padding(
                            padding: EdgeInsets.all(4),
                            child: CircleAvatar(
                                radius: 10,
                                backgroundColor: ArucadColors.primary,
                                child: Icon(Icons.check, size: 13, color: Colors.white)),
                          ),
                        ),
                    ]),
                  );
                },
              );
            },
          ),
        ),
        if (widget.multiple)
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 20),
            child: SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: _selected.isEmpty
                    ? null
                    : () async {
                        final all = await _future;
                        if (!context.mounted) return;
                        Navigator.of(context)
                            .pop(all.where((m) => _selected.contains(m.id)).toList());
                      },
                child: Text('Seçileni Ekle (${_selected.length})'),
              ),
            ),
          ),
      ]),
    );
  }
}

class _MediaThumb extends StatelessWidget {
  final MediaItem item;
  const _MediaThumb({required this.item});

  @override
  Widget build(BuildContext context) {
    try {
      return Image.memory(Uri.parse(item.dataUri).data!.contentAsBytes(), fit: BoxFit.cover);
    } catch (_) {
      return Container(
          color: ArucadColors.mist,
          child: const Icon(Icons.broken_image_outlined, color: ArucadColors.muted));
    }
  }
}

/// The full "Medya Kütüphanesi" admin tab — browse everything uploaded,
/// see where it's used, rename or delete it.
class MediaLibraryTab extends StatefulWidget {
  const MediaLibraryTab({super.key});

  @override
  State<MediaLibraryTab> createState() => _MediaLibraryTabState();
}

class _MediaLibraryTabState extends State<MediaLibraryTab> {
  late Future<List<MediaItem>> _future;

  @override
  void initState() {
    super.initState();
    _future = MediaLibraryStore.items();
  }

  void _reload() => setState(() => _future = MediaLibraryStore.items());

  Future<void> _upload() async {
    final picker = ImagePicker();
    final picked = await picker.pickMultiImage(imageQuality: 80);
    for (final file in picked) {
      final bytes = await file.readAsBytes();
      await MediaLibraryStore.upload(bytes, fileName: file.name, uploadedBy: 'Admin');
    }
    _reload();
  }

  Future<void> _rename(MediaItem item) async {
    final controller = TextEditingController(text: item.fileName);
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Dosya adını değiştir'),
        content: TextField(controller: controller),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Kaydet')),
        ],
      ),
    );
    if (ok != true || controller.text.trim().isEmpty) return;
    await MediaLibraryStore.rename(item.id, controller.text.trim());
    _reload();
  }

  Future<void> _delete(MediaItem item) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Görseli sil'),
        content: Text(item.usedIn.isEmpty
            ? '"${item.fileName}" silinsin mi?'
            : '"${item.fileName}" şu içeriklerde kullanılıyor: ${item.usedIn.join(', ')}. '
                'Yine de silinsin mi? O içeriklerdeki görsel görünmez olur.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Sil')),
        ],
      ),
    );
    if (ok != true) return;
    await MediaLibraryStore.delete(item.id);
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _upload,
        icon: const Icon(Icons.upload_outlined),
        label: const Text('Yükle'),
      ),
      body: FutureBuilder<List<MediaItem>>(
        future: _future,
        builder: (context, snap) {
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
          final items = snap.data!;
          if (items.isEmpty) {
            return const Center(
              child: Padding(
                padding: EdgeInsets.all(24),
                child: Text(
                  'Medya kütüphanesi boş. Sağ alttaki "Yükle" ile görsel ekle — buradan '
                  'yüklenen her görsel, blok editöründeki Görsel/Galeri bloklarında da seçilebilir.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: ArucadColors.muted),
                ),
              ),
            );
          }
          return GridView.builder(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 90),
            gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                crossAxisCount: 4, crossAxisSpacing: 10, mainAxisSpacing: 10, childAspectRatio: .85),
            itemCount: items.length,
            itemBuilder: (context, i) {
              final item = items[i];
              return Card(
                clipBehavior: Clip.antiAlias,
                child: Column(children: [
                  Expanded(child: _MediaThumb(item: item)),
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(item.fileName,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontSize: 10.5, fontWeight: FontWeight.w700)),
                      Text(
                          item.usedIn.isEmpty
                              ? 'Kullanılmıyor'
                              : '${item.usedIn.length} yerde kullanılıyor',
                          style: TextStyle(
                              fontSize: 9.5,
                              color: item.usedIn.isEmpty
                                  ? ArucadColors.muted
                                  : ArucadColors.success)),
                    ]),
                  ),
                  OverflowBar(alignment: MainAxisAlignment.end, children: [
                    IconButton(
                        iconSize: 16,
                        visualDensity: VisualDensity.compact,
                        onPressed: () => _rename(item),
                        icon: const Icon(Icons.edit_outlined)),
                    IconButton(
                        iconSize: 16,
                        visualDensity: VisualDensity.compact,
                        onPressed: () => _delete(item),
                        icon: const Icon(Icons.delete_outline)),
                  ]),
                ]),
              );
            },
          );
        },
      ),
    );
  }
}
