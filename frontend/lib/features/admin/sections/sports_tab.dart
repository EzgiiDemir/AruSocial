part of '../admin_panel_screen.dart';

// ---------------------------------------------------------------- Sports

class _SportsTab extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;
  const _SportsTab({required this.repository, required this.adminName});
  @override
  State<_SportsTab> createState() => _SportsTabState();
}

class _SportsTabState extends State<_SportsTab> {
  late Future<List<CampusSport>> _future;
  String _query = '';
  final Set<String> _selected = {};

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getSports();
  }

  void _reload() => setState(() { _future = widget.repository.getSports(); });

  Future<void> _deleteSelected() async {
    for (final id in _selected) {
      await widget.repository.deleteSport(id);
    }
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: 'delete',
        targetType: 'sport',
        targetLabel: '${_selected.length} spor (toplu)');
    setState(() => _selected.clear());
    _reload();
  }

  Future<void> _editSport([CampusSport? existing]) async {
    final strings = AdminLocale.of(context);
    final nameC = TextEditingController(text: existing?.name);
    final facilityC = TextEditingController(text: existing?.facility);
    final contactC = TextEditingController(text: existing?.contact);

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(existing == null ? strings.t('admin_sport_new') : strings.t('admin_sport_edit')),
        content: _DialogShell(fields: [
          TextField(controller: nameC, decoration: InputDecoration(labelText: strings.t('admin_field_name'))),
          TextField(controller: facilityC, decoration: InputDecoration(labelText: strings.t('admin_sport_facility'))),
          TextField(
              controller: contactC,
              decoration: InputDecoration(labelText: strings.t('admin_sport_contact_email'))),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(strings.t('admin_cancel'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(strings.t('admin_save'))),
        ],
      ),
    );
    if (saved != true || nameC.text.trim().isEmpty) return;
    await widget.repository.upsertSport(CampusSport(
      id: existing?.id ?? 'sport-${slugify(nameC.text)}',
      name: nameC.text.trim(),
      facility: facilityC.text.trim(),
      contact: contactC.text.trim().isEmpty ? null : contactC.text.trim(),
    ));
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: existing == null ? 'create' : 'update',
        targetType: 'sport',
        targetLabel: nameC.text.trim());
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    return Scaffold(
      floatingActionButton: FloatingActionButton(
          onPressed: () => _editSport(), child: const Icon(Icons.add)),
      body: Column(children: [
        _AdminListToolbar(
          searchHint: strings.t('admin_search_sports'),
          onQueryChanged: (v) => setState(() => _query = v),
          selectedCount: _selected.length,
          onCancelSelection: () => setState(_selected.clear),
          onDeleteSelected: _deleteSelected,
        ),
        Expanded(
          child: FutureBuilder<List<CampusSport>>(
            future: _future,
            builder: (context, snap) {
              if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
              final sports = snap.data!
                  .where((s) => s.name.toLowerCase().contains(_query.toLowerCase()))
                  .toList();
              return ListView.separated(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 90),
                itemCount: sports.length,
                separatorBuilder: (_, __) => const SizedBox(height: 8),
                itemBuilder: (context, i) {
                  final s = sports[i];
                  final isSelected = _selected.contains(s.id);
                  return Card(
                    child: ListTile(
                      leading: _selected.isEmpty
                          ? null
                          : Checkbox(
                              value: isSelected,
                              onChanged: (_) => setState(() =>
                                  isSelected ? _selected.remove(s.id) : _selected.add(s.id)),
                            ),
                      title: Text(s.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontWeight: FontWeight.w800)),
                      subtitle: Text(s.facility, maxLines: 1, overflow: TextOverflow.ellipsis),
                      onTap: () =>
                          _selected.isEmpty ? _editSport(s) : setState(() => isSelected
                              ? _selected.remove(s.id)
                              : _selected.add(s.id)),
                      onLongPress: () => setState(() => _selected.add(s.id)),
                      trailing: _selected.isNotEmpty
                          ? null
                          : IconButton(
                              icon: const Icon(Icons.delete_outline),
                              onPressed: () async {
                                await widget.repository.deleteSport(s.id);
                                await AuditLogStore.logIfMock(widget.repository,
                                    actorName: widget.adminName,
                                    action: 'delete',
                                    targetType: 'sport',
                                    targetLabel: s.name);
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
