part of '../admin_panel_screen.dart';

// --------------------------------------------------------- Bina Dizini

/// The Building → Floor → Room → Person layer: who/what is actually behind
/// a given door. Starts empty on every install — see `DirectoryEntry`'s
/// doc comment for why nothing is seeded here.
class _DirectoryTab extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;
  const _DirectoryTab({required this.repository, required this.adminName});
  @override
  State<_DirectoryTab> createState() => _DirectoryTabState();
}

class _DirectoryTabState extends State<_DirectoryTab> {
  late Future<List<DirectoryEntry>> _future;
  late Future<List<CampusService>> _servicesFuture;
  String _query = '';
  final Set<String> _selected = {};

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getDirectoryEntries();
    _servicesFuture = widget.repository.getServices();
  }

  void _reload() => setState(() { _future = widget.repository.getDirectoryEntries(); });

  Future<void> _deleteSelected() async {
    for (final id in _selected) {
      await widget.repository.deleteDirectoryEntry(id);
    }
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: 'delete',
        targetType: 'directory_entry',
        targetLabel: '${_selected.length} kayıt (toplu)');
    setState(() => _selected.clear());
    _reload();
  }

  Future<void> _editEntry(List<CampusService> services, [DirectoryEntry? existing]) async {
    final strings = AdminLocale.of(context);
    final buildingC = TextEditingController(text: existing?.building);
    final floorC = TextEditingController(text: existing?.floor);
    final roomC = TextEditingController(text: existing?.room);
    final nameC = TextEditingController(text: existing?.occupantName);
    final roleC = TextEditingController(text: existing?.occupantRole);
    final tourUrlC = TextEditingController(text: existing?.tourUrl);
    final tourTargetC = TextEditingController(text: existing?.tourTarget);
    String? relatedServiceId = existing?.relatedServiceId;

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? strings.t('admin_dir_new') : strings.t('admin_dir_edit')),
          content: _DialogShell(fields: [
            _DialogSection(strings.t('admin_section_location')),
            TextField(controller: buildingC, decoration: InputDecoration(labelText: strings.t('admin_field_building'))),
            TextField(controller: floorC, decoration: InputDecoration(labelText: strings.t('admin_field_floor'))),
            TextField(controller: roomC, decoration: InputDecoration(labelText: strings.t('admin_field_room'))),
            TextField(controller: tourUrlC, decoration: const InputDecoration(
                labelText: '360° tur bağlantısı', hintText: 'https://…')),
            TextField(controller: tourTargetC, decoration: const InputDecoration(
                labelText: '360° sahne / oda hedefi (isteğe bağlı)')),
            _DialogSection(strings.t('admin_section_occupant')),
            TextField(controller: nameC, decoration: InputDecoration(labelText: strings.t('admin_dir_occupant_name'))),
            TextField(controller: roleC, decoration: InputDecoration(labelText: strings.t('admin_dir_occupant_role'))),
            DropdownButtonFormField<String?>(
              initialValue: relatedServiceId,
              decoration: InputDecoration(labelText: strings.t('admin_dir_related_service')),
              items: [
                DropdownMenuItem<String?>(value: null, child: Text(strings.t('admin_none'))),
                ...services.map((s) => DropdownMenuItem<String?>(value: s.id, child: Text(s.title))),
              ],
              onChanged: (v) => setDialogState(() => relatedServiceId = v),
            ),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(strings.t('admin_cancel'))),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(strings.t('admin_save'))),
          ],
        ),
      ),
    );
    if (saved != true || buildingC.text.trim().isEmpty || nameC.text.trim().isEmpty) return;
    String? orNull(String s) => s.trim().isEmpty ? null : s.trim();
    await widget.repository.upsertDirectoryEntry(DirectoryEntry(
      id: existing?.id ?? 'dir-${DateTime.now().millisecondsSinceEpoch}',
      building: buildingC.text.trim(),
      floor: orNull(floorC.text),
      room: orNull(roomC.text),
      occupantName: nameC.text.trim(),
      occupantRole: orNull(roleC.text),
      relatedServiceId: relatedServiceId,
      tourUrl: orNull(tourUrlC.text),
      tourTarget: orNull(tourTargetC.text),
    ));
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: existing == null ? 'create' : 'update',
        targetType: 'directory_entry',
        targetLabel: '${nameC.text.trim()} (${buildingC.text.trim()})');
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    return FutureBuilder<List<CampusService>>(
      future: _servicesFuture,
      builder: (context, serviceSnap) {
        final services = serviceSnap.data ?? const [];
        return Scaffold(
          floatingActionButton: FloatingActionButton(
              onPressed: () => _editEntry(services), child: const Icon(Icons.add)),
          body: Column(children: [
            _AdminListToolbar(
              searchHint: strings.t('admin_search_directory'),
              onQueryChanged: (v) => setState(() => _query = v),
              selectedCount: _selected.length,
              onCancelSelection: () => setState(_selected.clear),
              onDeleteSelected: _deleteSelected,
            ),
            Expanded(
              child: FutureBuilder<List<DirectoryEntry>>(
                future: _future,
                builder: (context, snap) {
                  if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
                  final entries = snap.data!
                      .where((e) =>
                          '${e.building} ${e.floor ?? ''} ${e.room ?? ''} ${e.occupantName}'
                              .toLowerCase().contains(_query.toLowerCase()))
                      .toList();
                  if (entries.isEmpty) {
                    return Center(
                      child: Padding(
                        padding: const EdgeInsets.all(32),
                        child: Text(
                          AdminLocale.of(context).t('admin_directory_empty'),
                          textAlign: TextAlign.center,
                          style: const TextStyle(color: ArucadColors.muted),
                        ),
                      ),
                    );
                  }
                  return ListView.separated(
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 90),
                    itemCount: entries.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 8),
                    itemBuilder: (context, i) {
                      final e = entries[i];
                      final where =
                          [e.building, e.floor, e.room].whereType<String>().join(', ');
                      final isSelected = _selected.contains(e.id);
                      return Card(
                        child: ListTile(
                          leading: _selected.isEmpty
                              ? null
                              : Checkbox(
                                  value: isSelected,
                                  onChanged: (_) => setState(() => isSelected
                                      ? _selected.remove(e.id)
                                      : _selected.add(e.id)),
                                ),
                          title: Text(e.occupantName,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(fontWeight: FontWeight.w800)),
                          subtitle: Text(
                              [where, if (e.occupantRole != null) e.occupantRole!]
                                  .join(' · '),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis),
                          onTap: () => _selected.isEmpty
                              ? _editEntry(services, e)
                              : setState(() => isSelected
                                  ? _selected.remove(e.id)
                                  : _selected.add(e.id)),
                          onLongPress: () => setState(() => _selected.add(e.id)),
                          trailing: _selected.isNotEmpty
                              ? null
                              : IconButton(
                                  icon: const Icon(Icons.delete_outline),
                                  onPressed: () async {
                                    await widget.repository.deleteDirectoryEntry(e.id);
                                    await AuditLogStore.logIfMock(widget.repository,
                                        actorName: widget.adminName,
                                        action: 'delete',
                                        targetType: 'directory_entry',
                                        targetLabel: e.occupantName);
                                    _reload();
                                  },
                                ),
                        ),
                      );
                    },
                  );
                },
              ),
            ),
          ]),
        );
      },
    );
  }
}
