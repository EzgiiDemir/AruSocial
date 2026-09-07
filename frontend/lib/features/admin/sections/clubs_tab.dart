part of '../admin_panel_screen.dart';

// ----------------------------------------------------------------- Clubs

class _ClubsTab extends StatefulWidget {
  final CampusRepository repository;
  final String uploaderName;
  const _ClubsTab({required this.repository, required this.uploaderName});
  @override
  State<_ClubsTab> createState() => _ClubsTabState();
}

class _ClubsTabState extends State<_ClubsTab> {
  late Future<List<CampusClub>> _future;
  String _query = '';
  final Set<String> _selected = {};

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getClubs();
  }

  void _reload() => setState(() { _future = widget.repository.getClubs(); });

  Future<void> _deleteSelected() async {
    for (final id in _selected) {
      await widget.repository.deleteClub(id);
    }
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.uploaderName,
        action: 'delete',
        targetType: 'club',
        targetLabel: '${_selected.length} kulüp (toplu)');
    setState(() => _selected.clear());
    _reload();
  }

  Future<void> _editClub([CampusClub? existing]) async {
    final strings = AdminLocale.of(context);
    final nameC = TextEditingController(text: existing?.name);
    var category = ContentCategories.activity.contains(existing?.category)
        ? existing?.category
        : null;
    final descC = TextEditingController(text: existing?.description);
    var body = existing?.body ?? const <ContentBlock>[];

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? strings.t('admin_club_new') : strings.t('admin_club_edit')),
          content: _DialogShell(fields: [
            TextField(
                controller: nameC, decoration: InputDecoration(labelText: strings.t('admin_club_name'))),
            DropdownButtonFormField<String>(
              initialValue: category,
              decoration: InputDecoration(labelText: strings.t('admin_field_category')),
              items: [
                for (final c in ContentCategories.activity)
                  DropdownMenuItem(value: c, child: Text(c)),
              ],
              onChanged: (v) => setDialogState(() => category = v),
            ),
            TextField(
                controller: descC,
                decoration: InputDecoration(labelText: strings.t('admin_field_description_short')),
                maxLines: 2),
            Wrap(spacing: 8, runSpacing: 8, children: [
              OutlinedButton.icon(
                onPressed: () async {
                  final result = await openBlockEditor(ctx,
                      title: strings.t('admin_club_content_title'),
                      initialBlocks: body,
                      uploaderName: widget.uploaderName,
                      repository: widget.repository,
                      draftKey: existing == null ? null : 'club:${existing.id}');
                  setDialogState(() => body = result);
                },
                icon: const Icon(Icons.view_agenda_outlined, size: 16),
                label: Text(
                    '${strings.t('admin_edit_content')} (${body.length} ${strings.t('admin_blocks_suffix')})'),
              ),
              if (existing != null)
                OutlinedButton.icon(
                  onPressed: () async {
                    final restored =
                        await openRevisionHistory(ctx,
                            repository: widget.repository, contentKey: 'club:${existing.id}');
                    if (restored != null) setDialogState(() => body = restored);
                  },
                  icon: const Icon(Icons.history_outlined, size: 16),
                  label: Text(strings.t('admin_history')),
                ),
            ]),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(strings.t('admin_cancel'))),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(strings.t('admin_save'))),
          ],
        ),
      ),
    );
    if (saved != true || nameC.text.trim().isEmpty) return;
    final id = existing?.id ?? 'club-${slugify(nameC.text)}';
    await widget.repository.upsertClub(CampusClub(
      id: id,
      name: nameC.text.trim(),
      category: category ?? ContentCategories.activity.first,
      description: descC.text.trim(),
      body: body,
    ));
    await widget.repository.recordRevision('club:$id', body, widget.uploaderName);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.uploaderName,
        action: existing == null ? 'create' : 'update',
        targetType: 'club',
        targetLabel: nameC.text.trim());
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    return Scaffold(
      floatingActionButton: FloatingActionButton(
          onPressed: () => _editClub(), child: const Icon(Icons.add)),
      body: Column(children: [
        _AdminListToolbar(
          searchHint: strings.t('admin_search_clubs'),
          onQueryChanged: (v) => setState(() => _query = v),
          selectedCount: _selected.length,
          onCancelSelection: () => setState(_selected.clear),
          onDeleteSelected: _deleteSelected,
        ),
        Expanded(
          child: FutureBuilder<List<CampusClub>>(
            future: _future,
            builder: (context, snap) {
              if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
              final clubs = snap.data!
                  .where((c) => c.name.toLowerCase().contains(_query.toLowerCase()))
                  .toList();
              return ListView.separated(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 90),
                itemCount: clubs.length,
                separatorBuilder: (_, __) => const SizedBox(height: 8),
                itemBuilder: (context, i) {
                  final c = clubs[i];
                  final isSelected = _selected.contains(c.id);
                  return Card(
                    child: ListTile(
                      leading: _selected.isEmpty
                          ? null
                          : Checkbox(
                              value: isSelected,
                              onChanged: (_) => setState(() =>
                                  isSelected ? _selected.remove(c.id) : _selected.add(c.id)),
                            ),
                      title: Text(c.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontWeight: FontWeight.w800)),
                      subtitle: Text('${c.category} · ${c.description}',
                          maxLines: 2, overflow: TextOverflow.ellipsis),
                      onTap: () =>
                          _selected.isEmpty ? _editClub(c) : setState(() => isSelected
                              ? _selected.remove(c.id)
                              : _selected.add(c.id)),
                      onLongPress: () => setState(() => _selected.add(c.id)),
                      trailing: _selected.isNotEmpty
                          ? null
                          : IconButton(
                              icon: const Icon(Icons.delete_outline),
                              onPressed: () async {
                                await widget.repository.deleteClub(c.id);
                                await AuditLogStore.logIfMock(widget.repository,
                                    actorName: widget.uploaderName,
                                    action: 'delete',
                                    targetType: 'club',
                                    targetLabel: c.name);
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
  }
}
