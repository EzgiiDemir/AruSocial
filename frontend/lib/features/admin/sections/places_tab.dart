part of '../admin_panel_screen.dart';

// ---------------------------------------------------------------- Places

class _PlacesTab extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;
  const _PlacesTab({required this.repository, required this.adminName});
  @override
  State<_PlacesTab> createState() => _PlacesTabState();
}

class _PlacesTabState extends State<_PlacesTab> {
  late Future<List<CampusPlace>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getPlaces();
  }

  void _reload() => setState(() { _future = widget.repository.getPlaces(); });

  Future<void> _edit([CampusPlace? existing]) async {
    final nameC = TextEditingController(text: existing?.name);
    final latC = TextEditingController(text: existing?.lat.toString() ?? '');
    final lngC = TextEditingController(text: existing?.lng.toString() ?? '');
    final descC = TextEditingController(text: existing?.description);
    final streetC = TextEditingController(text: existing?.street);
    final distanceC = TextEditingController(text: existing?.distance);
    final tourUrlC = TextEditingController(text: existing?.tourUrl);
    final tourTargetC = TextEditingController(text: existing?.tourTarget);
    final coverUrlC = TextEditingController(text: existing?.coverUrl);
    var category = ContentCategories.campusFunction.contains(existing?.category)
        ? existing?.category
        : ContentCategories.campusFunction.first;
    var accessible = existing?.accessible ?? true;

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? 'Yeni mekân' : 'Mekânı düzenle'),
          content: SizedBox(
            width: 420,
            child: _DialogShell(fields: [
              TextField(controller: nameC, decoration: const InputDecoration(labelText: 'Ad')),
              DropdownButtonFormField<String>(
                initialValue: category,
                decoration: const InputDecoration(labelText: 'Kategori'),
                items: [
                  for (final c in ContentCategories.campusFunction)
                    DropdownMenuItem(value: c, child: Text(c)),
                ],
                onChanged: (v) => setDialogState(() => category = v),
              ),
              Row(children: [
                Expanded(
                    child: TextField(
                        controller: latC,
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        decoration: const InputDecoration(labelText: 'Enlem (lat)'))),
                const SizedBox(width: 8),
                Expanded(
                    child: TextField(
                        controller: lngC,
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        decoration: const InputDecoration(labelText: 'Boylam (lng)'))),
              ]),
              TextField(
                  controller: descC,
                  decoration: const InputDecoration(labelText: 'Açıklama'),
                  maxLines: 2),
              TextField(controller: streetC, decoration: const InputDecoration(labelText: 'Sokak')),
              TextField(
                  controller: distanceC,
                  decoration: const InputDecoration(labelText: 'Mesafe (ör. "3 min")')),
              TextField(
                  controller: tourUrlC,
                  decoration: const InputDecoration(labelText: '360° tur linki (opsiyonel)')),
              TextField(
                  controller: tourTargetC,
                  decoration: const InputDecoration(labelText: '360° bina / sahne hedefi (opsiyonel)')),
              TextField(
                  controller: coverUrlC,
                  decoration: const InputDecoration(labelText: 'Kapak görseli URL (opsiyonel)')),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Erişilebilir'),
                value: accessible,
                onChanged: (v) => setDialogState(() => accessible = v),
              ),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Kaydet')),
          ],
        ),
      ),
    );
    if (saved != true || nameC.text.trim().isEmpty) return;
    final lat = double.tryParse(latC.text.trim());
    final lng = double.tryParse(lngC.text.trim());
    if (lat == null || lng == null) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(const SnackBar(content: Text('Enlem/boylam geçerli bir sayı olmalı.')));
      return;
    }
    final id = existing?.id ?? 'place-${slugify(nameC.text)}';
    await widget.repository.upsertPlace(CampusPlace(
      id: id,
      name: nameC.text.trim(),
      tourTarget: tourTargetC.text.trim().isEmpty ? null : tourTargetC.text.trim(),
      category: category ?? ContentCategories.campusFunction.first,
      lat: lat,
      lng: lng,
      description: descC.text.trim(),
      distance: distanceC.text.trim(),
      density: existing?.density ?? 'quiet',
      street: streetC.text.trim(),
      tourUrl: tourUrlC.text.trim().isEmpty ? null : tourUrlC.text.trim(),
      accessible: accessible,
      photos: existing?.photos ?? 0,
      rating: existing?.rating ?? 0,
      coverUrl: coverUrlC.text.trim().isEmpty ? existing?.coverUrl : coverUrlC.text.trim(),
    ));
    final cover = coverUrlC.text.trim();
    if (cover.isNotEmpty && cover != existing?.coverUrl) {
      await widget.repository.setPlaceCover(id, cover);
    }
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: existing == null ? 'create' : 'update',
        targetType: 'place',
        targetLabel: nameC.text.trim());
    if (mounted) _reload();
  }

  Future<void> _delete(CampusPlace place) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Mekân silinsin mi?'),
        content: Text(place.name),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Sil')),
        ],
      ),
    );
    if (ok != true) return;
    await widget.repository.deletePlace(place.id);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName, action: 'delete', targetType: 'place', targetLabel: place.name);
    if (mounted) _reload();
  }

  Future<void> _manageWorkshop(CampusPlace place) async {
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (_) =>
          _WorkshopManageSheet(repository: widget.repository, place: place),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _edit(),
        icon: const Icon(Icons.add),
        label: const Text('Mekân Ekle'),
      ),
      body: FutureBuilder<List<CampusPlace>>(
        future: _future,
        builder: (context, snap) {
          if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
          final items = snap.data!;
          return ListView(
            padding: const EdgeInsets.fromLTRB(20, 20, 20, 90),
            children: [
              const Text('Mekânlar', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
              const SizedBox(height: 12),
              if (items.isEmpty)
                const Text('Henüz mekân yok.', style: TextStyle(color: ArucadColors.muted)),
              for (final p in items)
                Card(
                  child: ListTile(
                    title: Text(p.name, style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text('${p.category} · ${p.street}'),
                    trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                      IconButton(
                          tooltip: 'Atölye durumu / panosu',
                          icon: const Icon(Icons.handyman_outlined),
                          onPressed: () => _manageWorkshop(p)),
                      IconButton(icon: const Icon(Icons.edit_outlined), onPressed: () => _edit(p)),
                      IconButton(icon: const Icon(Icons.delete_outline), onPressed: () => _delete(p)),
                    ]),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }
}

// ------------------------------------------------- Workshop equipment/board

/// Admin/trainer-facing management for a single place's real workshop
/// equipment availability and collaboration-board posts — the backend
/// replacement for what used to be a hardcoded, identical-everywhere const
/// in the Flutter map sheet.
class _WorkshopManageSheet extends StatefulWidget {
  final CampusRepository repository;
  final CampusPlace place;
  const _WorkshopManageSheet({required this.repository, required this.place});

  @override
  State<_WorkshopManageSheet> createState() => _WorkshopManageSheetState();
}

class _WorkshopManageSheetState extends State<_WorkshopManageSheet> {
  late Future<WorkshopInfo> _future;

  @override
  void initState() {
    super.initState();
    _load();
  }

  void _load() {
    _future = widget.repository.getWorkshopInfo(widget.place.id);
  }

  Future<void> _addEquipment() async {
    final nameC = TextEditingController();
    var available = true;
    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: const Text('Ekipman ekle'),
          content: _DialogShell(fields: [
            TextField(
                controller: nameC,
                decoration: const InputDecoration(labelText: 'Ekipman adı')),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: const Text('Müsait'),
              value: available,
              onChanged: (v) => setDialogState(() => available = v),
            ),
          ]),
          actions: [
            TextButton(
                onPressed: () => Navigator.pop(ctx, false),
                child: const Text('Vazgeç')),
            FilledButton(
                onPressed: () => Navigator.pop(ctx, true),
                child: const Text('Kaydet')),
          ],
        ),
      ),
    );
    if (saved != true || nameC.text.trim().isEmpty) return;
    await widget.repository.upsertWorkshopEquipment(widget.place.id,
        name: nameC.text.trim(), available: available);
    if (mounted) setState(_load);
  }

  Future<void> _toggleAvailable(WorkshopEquipmentItem item) async {
    await widget.repository.upsertWorkshopEquipment(widget.place.id,
        id: item.id, name: item.name, available: !item.available);
    if (mounted) setState(_load);
  }

  Future<void> _deleteEquipment(WorkshopEquipmentItem item) async {
    await widget.repository.deleteWorkshopEquipment(widget.place.id, item.id);
    if (mounted) setState(_load);
  }

  Future<void> _deletePost(CampusCollaborationPost post) async {
    await widget.repository.deleteCollaborationPost(widget.place.id, post.id);
    if (mounted) setState(_load);
  }

  @override
  Widget build(BuildContext context) {
    return DraggableScrollableSheet(
      initialChildSize: 0.75,
      minChildSize: 0.4,
      maxChildSize: 0.95,
      expand: false,
      builder: (context, scrollController) => FutureBuilder<WorkshopInfo>(
        future: _future,
        builder: (context, snap) {
          if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) {
            return const Center(child: CircularProgressIndicator());
          }
          final info = snap.data!;
          return ListView(
            controller: scrollController,
            padding: const EdgeInsets.fromLTRB(20, 20, 20, 32),
            children: [
              Text('${widget.place.name} · Atölye',
                  style: const TextStyle(
                      fontSize: 18, fontWeight: FontWeight.w800)),
              const SizedBox(height: 16),
              Row(children: [
                const Expanded(
                    child: Text('Ekipman Durumu',
                        style: TextStyle(fontWeight: FontWeight.w800))),
                TextButton.icon(
                  onPressed: _addEquipment,
                  icon: const Icon(Icons.add, size: 18),
                  label: const Text('Ekle'),
                ),
              ]),
              if (info.equipment.isEmpty)
                const Text('Henüz ekipman eklenmedi.',
                    style: TextStyle(color: ArucadColors.muted)),
              for (final eq in info.equipment)
                Card(
                  child: ListTile(
                    title: Text(eq.name),
                    leading: Icon(Icons.circle,
                        size: 12,
                        color: eq.available
                            ? ArucadColors.success
                            : ArucadColors.danger),
                    trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                      Switch(
                          value: eq.available,
                          onChanged: (_) => _toggleAvailable(eq)),
                      IconButton(
                          icon: const Icon(Icons.delete_outline),
                          onPressed: () => _deleteEquipment(eq)),
                    ]),
                  ),
                ),
              const SizedBox(height: 20),
              const Text('İş Birliği Panosu',
                  style: TextStyle(fontWeight: FontWeight.w800)),
              const SizedBox(height: 8),
              if (info.posts.isEmpty)
                const Text('Henüz ilan yok.',
                    style: TextStyle(color: ArucadColors.muted))
              else
                for (final post in info.posts)
                  Card(
                    child: ListTile(
                      title: Text(post.text),
                      subtitle: Text(post.authorName ?? 'Öğrenci'),
                      trailing: IconButton(
                          tooltip: 'İlanı kaldır',
                          icon: const Icon(Icons.delete_outline),
                          onPressed: () => _deletePost(post)),
                    ),
                  ),
            ],
          );
        },
      ),
    );
  }
}
