part of '../admin_panel_screen.dart';

// ---------------------------------------------------------------- Pages

class _PagesTab extends StatefulWidget {
  final CampusRepository repository;
  final String uploaderName;
  const _PagesTab({required this.repository, required this.uploaderName});
  @override
  State<_PagesTab> createState() => _PagesTabState();
}

class _PagesTabState extends State<_PagesTab> {
  late Future<List<AdminPage>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getPages();
  }

  void _reload() => setState(() { _future = widget.repository.getPages(); });

  Future<void> _editPage([AdminPage? existing]) async {
    final strings = AdminLocale.of(context);
    final titleC = TextEditingController(text: existing?.title);
    final slugC = TextEditingController(text: existing?.slug);
    var blocks = existing?.blocks ?? const <ContentBlock>[];
    var status = existing?.status ?? AdminPageStatus.draft;

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? strings.t('admin_page_new') : strings.t('admin_page_edit')),
          content: _DialogShell(fields: [
            TextField(
              controller: titleC,
              decoration: InputDecoration(labelText: strings.t('admin_field_title')),
              onChanged: (v) {
                if (existing == null) {
                  slugC.text = slugify(v);
                }
              },
            ),
            TextField(controller: slugC, decoration: InputDecoration(labelText: strings.t('admin_page_slug'))),
            Wrap(spacing: 8, runSpacing: 8, children: [
              OutlinedButton.icon(
                onPressed: () async {
                  final result = await openBlockEditor(ctx,
                      title: strings.t('admin_page_content_title'),
                      initialBlocks: blocks,
                      uploaderName: widget.uploaderName,
                      repository: widget.repository,
                      draftKey: existing == null ? null : 'page:${existing.id}');
                  setDialogState(() => blocks = result);
                },
                icon: const Icon(Icons.view_agenda_outlined, size: 16),
                label: Text(
                    '${strings.t('admin_edit_content')} (${blocks.length} ${strings.t('admin_blocks_suffix')})'),
              ),
              if (existing != null)
                OutlinedButton.icon(
                  onPressed: () async {
                    final restored =
                        await openRevisionHistory(ctx,
                            repository: widget.repository, contentKey: 'page:${existing.id}');
                    if (restored != null) setDialogState(() => blocks = restored);
                  },
                  icon: const Icon(Icons.history_outlined, size: 16),
                  label: Text(strings.t('admin_history')),
                ),
            ]),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(strings.t('admin_page_publish_switch')),
              value: status == AdminPageStatus.published,
              onChanged: (v) => setDialogState(
                  () => status = v ? AdminPageStatus.published : AdminPageStatus.draft),
            ),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(strings.t('admin_cancel'))),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(strings.t('admin_save'))),
          ],
        ),
      ),
    );
    if (saved != true || titleC.text.trim().isEmpty) return;
    final id = existing?.id ?? 'page-${DateTime.now().millisecondsSinceEpoch}';
    await widget.repository.upsertPage(AdminPage(
      id: id,
      title: titleC.text.trim(),
      slug: slugC.text.trim().isEmpty ? slugify(titleC.text) : slugC.text.trim(),
      blocks: blocks,
      status: status,
      updatedAt: DateTime.now(),
      updatedBy: widget.uploaderName,
    ));
    await widget.repository.recordRevision('page:$id', blocks, widget.uploaderName);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.uploaderName,
        action: existing == null ? 'create' : 'update',
        targetType: 'page',
        targetLabel: titleC.text.trim());
    _reload();
  }

  Future<void> _delete(AdminPage page) async {
    await widget.repository.deletePage(page.id);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.uploaderName,
        action: 'delete',
        targetType: 'page',
        targetLabel: page.title);
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 2,
      child: Column(children: [
        const TabBar(
          labelColor: ArucadColors.navy,
          tabs: [
            Tab(text: 'Sayfalar'),
            Tab(text: 'Resmi paylaşımlar'),
          ],
        ),
        Expanded(
          child: TabBarView(children: [
            _PagesListBody(
              future: _future,
              onEdit: _editPage,
              onDelete: _delete,
            ),
            _OfficialFeedTab(repository: widget.repository),
          ]),
        ),
      ]),
    );
  }
}

class _PagesListBody extends StatelessWidget {
  final Future<List<AdminPage>> future;
  final Future<void> Function([AdminPage?]) onEdit;
  final Future<void> Function(AdminPage) onDelete;

  const _PagesListBody({
    required this.future,
    required this.onEdit,
    required this.onDelete,
  });

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    return Scaffold(
      floatingActionButton: FloatingActionButton(
          onPressed: () => onEdit(), child: const Icon(Icons.add)),
      body: FutureBuilder<List<AdminPage>>(
        future: future,
        builder: (context, snap) {
          if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
          final pages = snap.data!;
          if (pages.isEmpty) {
            return Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Text(
                  strings.t('admin_pages_empty'),
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: ArucadColors.muted),
                ),
              ),
            );
          }
          return ListView.separated(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 90),
            itemCount: pages.length,
            separatorBuilder: (_, __) => const SizedBox(height: 8),
            itemBuilder: (context, i) {
              final p = pages[i];
              return Card(
                child: ListTile(
                  title: Text(p.title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontWeight: FontWeight.w800)),
                  subtitle: Text(
                      '/${p.slug} · ${p.blocks.length} blok · '
                      '${p.status == AdminPageStatus.published ? strings.t('admin_status_published') : strings.t('admin_status_draft')}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis),
                  onTap: () => onEdit(p),
                  trailing: IconButton(
                    icon: const Icon(Icons.delete_outline),
                    onPressed: () => onDelete(p),
                  ),
                ),
              );
            },
          );
        },
      ),
    );
  }
}

class _OfficialFeedTab extends StatefulWidget {
  final CampusRepository repository;
  const _OfficialFeedTab({required this.repository});
  @override
  State<_OfficialFeedTab> createState() => _OfficialFeedTabState();
}

class _OfficialFeedTabState extends State<_OfficialFeedTab> {
  late Future<PageSlice<FeedPost>> _future;
  final _textC = TextEditingController();
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _reload();
  }

  @override
  void dispose() {
    _textC.dispose();
    super.dispose();
  }

  void _reload() => setState(() {
        _future = widget.repository.getFeedPage(page: 1, perPage: 50);
      });

  Future<void> _publish() async {
    final text = _textC.text.trim();
    if (text.isEmpty) return;
    setState(() => _saving = true);
    try {
      await widget.repository.createOfficialPost(text);
      _textC.clear();
      if (mounted) _reload();
    } on ApiClientException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _pin(FeedPost post) async {
    try {
      if (post.isPinned) {
        await widget.repository.unpinPost(post.id);
      } else {
        await widget.repository.pinPost(post.id);
      }
      if (mounted) _reload();
    } on ApiClientException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<PageSlice<FeedPost>>(
      future: _future,
      builder: (context, snap) {
        final official = snap.data?.items.where((p) => p.official).toList() ?? const <FeedPost>[];
        return ListView(
          padding: const EdgeInsets.all(20),
          children: [
            const Text('Resmi duyuru oluştur',
                style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
            const SizedBox(height: 8),
            TextField(
              controller: _textC,
              maxLines: 3,
              decoration: const InputDecoration(hintText: 'Kampüs duyurusu…'),
            ),
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerRight,
              child: FilledButton(
                onPressed: _saving ? null : _publish,
                child: Text(_saving ? 'Yayımlanıyor…' : 'Oluştur'),
              ),
            ),
            const SizedBox(height: 16),
            if (snap.hasError) _AdminLoadError(error: snap.error!),
            if (!snap.hasData && !snap.hasError)
              const LinearProgressIndicator(),
            if (official.isEmpty && snap.hasData)
              const Text('Henüz resmi paylaşım yok.',
                  style: TextStyle(color: ArucadColors.muted)),
            for (final p in official)
              Card(
                child: ListTile(
                  leading: Icon(p.isPinned ? Icons.push_pin : Icons.campaign_outlined,
                      color: ArucadColors.primary),
                  title: Text(p.text, maxLines: 3, overflow: TextOverflow.ellipsis),
                  subtitle: Text(p.isPinned ? 'Sabitlendi · ${p.meta}' : p.meta),
                  trailing: TextButton(
                    onPressed: () => _pin(p),
                    child: Text(p.isPinned ? 'Sabitlemeyi Kaldır' : 'Pinle'),
                  ),
                ),
              ),
          ],
        );
      },
    );
  }
}
