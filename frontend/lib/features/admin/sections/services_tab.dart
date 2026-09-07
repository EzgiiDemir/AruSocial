part of '../admin_panel_screen.dart';

// -------------------------------------------------------------- Services

class _ServicesTab extends StatefulWidget {
  final CampusRepository repository;
  final String uploaderName;
  const _ServicesTab({required this.repository, required this.uploaderName});
  @override
  State<_ServicesTab> createState() => _ServicesTabState();
}

class _ServicesTabState extends State<_ServicesTab> {
  late Future<List<CampusService>> _future;
  String _query = '';
  final Set<String> _selected = {};

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getServices();
  }

  void _reload() => setState(() { _future = widget.repository.getServices(); });

  Future<void> _deleteSelected() async {
    for (final id in _selected) {
      await widget.repository.deleteService(id);
    }
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.uploaderName,
        action: 'delete',
        targetType: 'service',
        targetLabel: '${_selected.length} hizmet (toplu)');
    setState(() => _selected.clear());
    _reload();
  }

  Future<void> _editService([CampusService? existing]) async {
    final strings = AdminLocale.of(context);
    final titleC = TextEditingController(text: existing?.title);
    var category = ContentCategories.campusFunction.contains(existing?.category)
        ? existing?.category
        : ContentCategories.campusFunction.first;
    final descC = TextEditingController(text: existing?.description);
    final contactC = TextEditingController(text: existing?.contact);
    final buildingC = TextEditingController(text: existing?.building);
    final floorC = TextEditingController(text: existing?.floor);
    final roomC = TextEditingController(text: existing?.room);
    final personC = TextEditingController(text: existing?.contactPerson);
    final hoursC = TextEditingController(text: existing?.hours);
    final topicsC = TextEditingController(text: existing?.topics.join(', '));
    var body = existing?.body ?? const <ContentBlock>[];

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? strings.t('admin_service_new') : strings.t('admin_service_edit')),
          content: _DialogShell(fields: [
            _DialogSection(strings.t('admin_section_basic_info')),
            TextField(controller: titleC, decoration: InputDecoration(labelText: strings.t('admin_field_title'))),
            DropdownButtonFormField<String>(
              initialValue: category,
              decoration: InputDecoration(labelText: strings.t('admin_field_category')),
              items: [
                for (final c in ContentCategories.campusFunction)
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
                      title: strings.t('admin_service_content_title'),
                      initialBlocks: body,
                      uploaderName: widget.uploaderName,
                      repository: widget.repository,
                      draftKey: existing == null ? null : 'service:${existing.id}');
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
                            repository: widget.repository, contentKey: 'service:${existing.id}');
                    if (restored != null) setDialogState(() => body = restored);
                  },
                  icon: const Icon(Icons.history_outlined, size: 16),
                  label: Text(strings.t('admin_history')),
                ),
            ]),
            TextField(controller: contactC, decoration: InputDecoration(labelText: strings.t('admin_field_email'))),
            TextField(
                controller: topicsC,
                decoration: InputDecoration(
                    labelText: strings.t('admin_service_topics'),
                    hintText: strings.t('admin_service_topics_hint'))),
            TextField(
                controller: hoursC,
                decoration: InputDecoration(
                    labelText: strings.t('admin_field_hours'), hintText: strings.t('admin_hours_hint'))),
            _DialogSection(strings.t('admin_section_location_optional')),
            TextField(controller: buildingC, decoration: InputDecoration(labelText: strings.t('admin_field_building'))),
            TextField(controller: floorC, decoration: InputDecoration(labelText: strings.t('admin_field_floor'))),
            TextField(controller: roomC, decoration: InputDecoration(labelText: strings.t('admin_field_room'))),
            TextField(
                controller: personC, decoration: InputDecoration(labelText: strings.t('admin_service_contact_person'))),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(strings.t('admin_cancel'))),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(strings.t('admin_save'))),
          ],
        ),
      ),
    );
    if (saved != true || titleC.text.trim().isEmpty) return;
    String? orNull(String s) => s.trim().isEmpty ? null : s.trim();
    final id = existing?.id ?? 'service-${slugify(titleC.text)}';
    await widget.repository.upsertService(CampusService(
      id: id,
      title: titleC.text.trim(),
      category: category ?? ContentCategories.campusFunction.first,
      description: descC.text.trim(),
      contact: contactC.text.trim(),
      building: orNull(buildingC.text),
      floor: orNull(floorC.text),
      room: orNull(roomC.text),
      contactPerson: orNull(personC.text),
      hours: orNull(hoursC.text),
      topics: topicsC.text.split(',').map((t) => t.trim()).where((t) => t.isNotEmpty).toList(),
      body: body,
    ));
    await widget.repository.recordRevision('service:$id', body, widget.uploaderName);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.uploaderName,
        action: existing == null ? 'create' : 'update',
        targetType: 'service',
        targetLabel: titleC.text.trim());
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    return Scaffold(
      floatingActionButton: FloatingActionButton(
          onPressed: () => _editService(), child: const Icon(Icons.add)),
      body: Column(children: [
        _AdminListToolbar(
          searchHint: strings.t('admin_search_services'),
          onQueryChanged: (v) => setState(() => _query = v),
          selectedCount: _selected.length,
          onCancelSelection: () => setState(_selected.clear),
          onDeleteSelected: _deleteSelected,
        ),
        Expanded(
          child: FutureBuilder<List<CampusService>>(
            future: _future,
            builder: (context, snap) {
              if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
              final services = snap.data!
                  .where((s) => s.title.toLowerCase().contains(_query.toLowerCase()))
                  .toList();
              return ListView.separated(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 90),
                itemCount: services.length,
                separatorBuilder: (_, __) => const SizedBox(height: 8),
                itemBuilder: (context, i) {
                  final s = services[i];
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
                      title: Text(s.title,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontWeight: FontWeight.w800)),
                      subtitle: Text('${s.category} · ${s.description}',
                          maxLines: 2, overflow: TextOverflow.ellipsis),
                      onTap: () =>
                          _selected.isEmpty ? _editService(s) : setState(() => isSelected
                              ? _selected.remove(s.id)
                              : _selected.add(s.id)),
                      onLongPress: () => setState(() => _selected.add(s.id)),
                      trailing: _selected.isNotEmpty
                          ? null
                          : IconButton(
                              icon: const Icon(Icons.delete_outline),
                              onPressed: () async {
                                await widget.repository.deleteService(s.id);
                                await AuditLogStore.logIfMock(widget.repository,
                                    actorName: widget.uploaderName,
                                    action: 'delete',
                                    targetType: 'service',
                                    targetLabel: s.title);
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
