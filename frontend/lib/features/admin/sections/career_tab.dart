part of '../admin_panel_screen.dart';

// --------------------------------------------------------------- Career

/// Admin CRUD for career opportunities — wired to existing
/// `POST/DELETE /admin/career/opportunities*` APIs (no new routes).
class _CareerTab extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;
  const _CareerTab({required this.repository, required this.adminName});
  @override
  State<_CareerTab> createState() => _CareerTabState();
}

class _CareerTabState extends State<_CareerTab> {
  late Future<PageSlice<CareerOpportunity>> _future;

  @override
  void initState() {
    super.initState();
    _reload();
  }

  void _reload() {
    setState(() {
      _future = widget.repository.getAdminCareerOpportunitiesPage(page: 1, perPage: 50);
    });
  }

  Future<void> _edit([CareerOpportunity? existing]) async {
    final titleC = TextEditingController(text: existing?.title);
    final orgC = TextEditingController(text: existing?.organization);
    final deptC = TextEditingController(text: existing?.department);
    final kindC = TextEditingController(text: existing?.kind ?? 'internship');
    final urlC = TextEditingController(text: existing?.url);
    final descC = TextEditingController(text: existing?.description);
    final purposeC = TextEditingController(text: existing?.purpose);
    final skillsC = TextEditingController(text: existing?.skills);
    final expC = TextEditingController(text: existing?.experience);
    final eduC = TextEditingController(text: existing?.education);
    final workC = TextEditingController(text: existing?.workType);
    final locC = TextEditingController(text: existing?.location);
    final extraC = TextEditingController(text: existing?.extraInfo);
    var published = existing?.published ?? true;

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? 'Yeni fırsat' : 'Fırsatı düzenle'),
          content: SizedBox(
            width: 420,
            child:             _DialogShell(fields: [
              TextField(controller: titleC, decoration: const InputDecoration(labelText: 'İş adı')),
              TextField(controller: orgC, decoration: const InputDecoration(labelText: 'Kurum')),
              TextField(controller: kindC, decoration: const InputDecoration(labelText: 'Tür (job/internship/event/resource)')),
              TextField(controller: urlC, decoration: const InputDecoration(labelText: 'URL')),
              TextField(controller: descC, decoration: const InputDecoration(labelText: 'İş tanımı'), maxLines: 3),
              TextField(controller: deptC, decoration: const InputDecoration(labelText: 'Departman')),
              TextField(controller: purposeC, decoration: const InputDecoration(labelText: 'Pozisyon amacı'), maxLines: 2),
              TextField(controller: skillsC, decoration: const InputDecoration(labelText: 'Aranan yetkinlikler'), maxLines: 2),
              TextField(controller: expC, decoration: const InputDecoration(labelText: 'Aranan deneyim')),
              TextField(controller: eduC, decoration: const InputDecoration(labelText: 'Eğitim şartları')),
              TextField(controller: workC, decoration: const InputDecoration(labelText: 'Çalışma tipi')),
              TextField(controller: locC, decoration: const InputDecoration(labelText: 'Lokasyon')),
              TextField(controller: extraC, decoration: const InputDecoration(labelText: 'Ek bilgiler'), maxLines: 2),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Yayında'),
                value: published,
                onChanged: (v) => setDialogState(() => published = v),
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
    if (saved != true) return;
    final id = existing?.id ?? 'career-${DateTime.now().millisecondsSinceEpoch}';
    await widget.repository.upsertCareerOpportunity(CareerOpportunity(
      id: id,
      title: titleC.text.trim(),
      kind: kindC.text.trim().isEmpty ? 'internship' : kindC.text.trim(),
      organization: orgC.text.trim(),
      department: deptC.text.trim().isEmpty ? null : deptC.text.trim(),
      url: urlC.text.trim().isEmpty ? null : urlC.text.trim(),
      description: descC.text.trim().isEmpty ? null : descC.text.trim(),
      purpose: purposeC.text.trim().isEmpty ? null : purposeC.text.trim(),
      skills: skillsC.text.trim().isEmpty ? null : skillsC.text.trim(),
      experience: expC.text.trim().isEmpty ? null : expC.text.trim(),
      education: eduC.text.trim().isEmpty ? null : eduC.text.trim(),
      workType: workC.text.trim().isEmpty ? null : workC.text.trim(),
      location: locC.text.trim().isEmpty ? null : locC.text.trim(),
      extraInfo: extraC.text.trim().isEmpty ? null : extraC.text.trim(),
      published: published,
    ));
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: existing == null ? 'create' : 'update',
        targetType: 'career',
        targetLabel: titleC.text.trim());
    if (mounted) _reload();
  }

  Future<void> _delete(CareerOpportunity o) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Silinsin mi?'),
        content: Text(o.title),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Sil')),
        ],
      ),
    );
    if (ok != true) return;
    await widget.repository.deleteCareerOpportunity(o.id);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: 'delete',
        targetType: 'career',
        targetLabel: o.title);
    if (mounted) _reload();
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<PageSlice<CareerOpportunity>>(
      future: _future,
      builder: (context, snap) {
        if (snap.hasError) return _AdminLoadError(error: snap.error!);
        if (!snap.hasData) return const Center(child: CircularProgressIndicator());
        final items = snap.data!.items;
        return ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Row(children: [
              const Expanded(
                  child: Text('Kariyer fırsatları',
                      style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800))),
              FilledButton.icon(
                onPressed: () => _edit(),
                icon: const Icon(Icons.add),
                label: const Text('Ekle'),
              ),
            ]),
            const SizedBox(height: 12),
            if (items.isEmpty)
              const Text('Henüz fırsat yok.', style: TextStyle(color: ArucadColors.muted)),
            for (final o in items)
              Card(
                child: ListTile(
                  title: Text(o.title),
                  subtitle: Text('${o.kind} · ${o.organization}${o.published ? '' : ' · taslak'}'),
                  trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                    IconButton(icon: const Icon(Icons.edit_outlined), onPressed: () => _edit(o)),
                    IconButton(icon: const Icon(Icons.delete_outline), onPressed: () => _delete(o)),
                  ]),
                ),
              ),
          ],
        );
      },
    );
  }
}

class _CareerOfficeHub extends StatelessWidget {
  final CampusRepository repository;
  final String adminName;
  const _CareerOfficeHub({required this.repository, required this.adminName});

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 3,
      child: Column(children: [
        const TabBar(
          labelColor: ArucadColors.navy,
          tabs: [
            Tab(text: 'İlanlar'),
            Tab(text: 'Başvurular'),
            Tab(text: 'Danışmanlık'),
          ],
        ),
        Expanded(
          child: TabBarView(children: [
            _CareerTab(repository: repository, adminName: adminName),
            _CareerApplicationsTab(repository: repository),
            _ConsultationsAdminTab(repository: repository, adminName: adminName),
          ]),
        ),
      ]),
    );
  }
}

class _CareerApplicationsTab extends StatefulWidget {
  final CampusRepository repository;
  const _CareerApplicationsTab({required this.repository});
  @override
  State<_CareerApplicationsTab> createState() => _CareerApplicationsTabState();
}

class _CareerApplicationsTabState extends State<_CareerApplicationsTab> {
  late Future<List<CareerApplication>> _future;
  String _q = '';

  @override
  void initState() {
    super.initState();
    _reload();
  }

  void _reload() => setState(() {
        _future = widget.repository.getAdminCareerApplications(q: _q.isEmpty ? null : _q);
      });

  Future<void> _setStatus(CareerApplication a, String status) async {
    try {
      await widget.repository.updateCareerApplication(a.id, status: status);
      if (mounted) _reload();
    } on ApiClientException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<List<CareerApplication>>(
      future: _future,
      builder: (context, snap) {
        if (snap.hasError) return _AdminLoadError(error: snap.error!);
        if (!snap.hasData) return const Center(child: CircularProgressIndicator());
        final items = snap.data!;
        return ListView(
          padding: const EdgeInsets.all(20),
          children: [
            TextField(
              decoration: const InputDecoration(
                  prefixIcon: Icon(Icons.search), hintText: 'Ara (öğrenci / ilan)'),
              onChanged: (v) {
                _q = v;
                _reload();
              },
            ),
            const SizedBox(height: 12),
            if (items.isEmpty)
              const Text('Başvuru yok.', style: TextStyle(color: ArucadColors.muted)),
            for (final a in items)
              Card(
                child: ListTile(
                  title: Text(a.opportunityTitle ?? a.opportunityId,
                      style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text('${a.userName ?? a.userId} · ${a.status}'),
                  trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                    if (a.hasCv)
                      IconButton(
                        tooltip: 'CV',
                        icon: const Icon(Icons.picture_as_pdf_outlined),
                            onPressed: () async {
                          try {
                            final bytes = await widget.repository
                                .downloadCareerApplicationCv(a.id);
                            if (!context.mounted) return;
                            await openDocumentBytes(
                              bytes: bytes,
                              fileName: a.cvFileName ?? 'cv.pdf',
                            );
                          } catch (e) {
                            if (!context.mounted) return;
                            ScaffoldMessenger.of(context).showSnackBar(
                                SnackBar(content: Text('CV açılamadı: $e')));
                          }
                        },
                      ),
                    PopupMenuButton<String>(
                      onSelected: (v) => _setStatus(a, v),
                      itemBuilder: (_) => const [
                        PopupMenuItem(value: 'pending', child: Text('Beklemede')),
                        PopupMenuItem(value: 'reviewed', child: Text('İncelendi')),
                        PopupMenuItem(value: 'shortlisted', child: Text('Kısa liste')),
                        PopupMenuItem(value: 'rejected', child: Text('Red')),
                        PopupMenuItem(value: 'accepted', child: Text('Kabul')),
                      ],
                    ),
                  ]),
                ),
              ),
          ],
        );
      },
    );
  }
}

class _ConsultationsAdminTab extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;
  const _ConsultationsAdminTab({required this.repository, required this.adminName});
  @override
  State<_ConsultationsAdminTab> createState() => _ConsultationsAdminTabState();
}

class _ConsultationsAdminTabState extends State<_ConsultationsAdminTab> {
  late Future<PageSlice<ConsultationOffering>> _future;
  late Future<List<ConsultationApplication>> _appsFuture;

  @override
  void initState() {
    super.initState();
    _reload();
  }

  void _reload() => setState(() {
        _future = widget.repository.getAdminConsultationsPage(perPage: 50);
        _appsFuture = widget.repository.getAdminConsultationApplications();
      });

  Future<void> _edit([ConsultationOffering? existing]) async {
    final titleC = TextEditingController(text: existing?.title);
    final purposeC = TextEditingController(text: existing?.purpose);
    final audienceC = TextEditingController(text: existing?.audience);
    final contentC = TextEditingController(text: existing?.content);
    final outcomesC = TextEditingController(text: existing?.outcomes);
    final durationC = TextEditingController(text: existing?.duration);
    final formatC = TextEditingController(text: existing?.format);
    final reqC = TextEditingController(text: existing?.requirements);
    final counselorC = TextEditingController(text: existing?.counselorName);
    var published = existing?.published ?? true;
    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? 'Yeni danışmanlık' : 'Düzenle'),
          content: SizedBox(
            width: 420,
            child: _DialogShell(fields: [
              TextField(controller: titleC, decoration: const InputDecoration(labelText: 'Başlık')),
              TextField(controller: purposeC, decoration: const InputDecoration(labelText: 'Amaç'), maxLines: 2),
              TextField(controller: audienceC, decoration: const InputDecoration(labelText: 'Kimler için')),
              TextField(controller: contentC, decoration: const InputDecoration(labelText: 'İçerik'), maxLines: 2),
              TextField(controller: outcomesC, decoration: const InputDecoration(labelText: 'Kazanım')),
              TextField(controller: durationC, decoration: const InputDecoration(labelText: 'Süre')),
              TextField(controller: formatC, decoration: const InputDecoration(labelText: 'Uygulama şekli')),
              TextField(controller: reqC, decoration: const InputDecoration(labelText: 'Koşullar')),
              TextField(controller: counselorC, decoration: const InputDecoration(labelText: 'Danışman')),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Yayında'),
                value: published,
                onChanged: (v) => setDialogState(() => published = v),
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
    if (saved != true) return;
    await widget.repository.upsertConsultation(ConsultationOffering(
      id: existing?.id ?? 'consult-${DateTime.now().millisecondsSinceEpoch}',
      title: titleC.text.trim(),
      purpose: purposeC.text.trim(),
      audience: audienceC.text.trim(),
      content: contentC.text.trim(),
      outcomes: outcomesC.text.trim(),
      duration: durationC.text.trim(),
      format: formatC.text.trim(),
      requirements: reqC.text.trim(),
      counselorName: counselorC.text.trim(),
      published: published,
    ));
    if (mounted) _reload();
  }

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        Row(children: [
          const Expanded(
              child: Text('Danışmanlıklar',
                  style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800))),
          FilledButton.icon(
            onPressed: () => _edit(),
            icon: const Icon(Icons.add),
            label: const Text('Ekle'),
          ),
        ]),
        FutureBuilder<PageSlice<ConsultationOffering>>(
          future: _future,
          builder: (context, snap) {
            if (!snap.hasData) {
              return const Padding(
                padding: EdgeInsets.all(16), child: LinearProgressIndicator());
            }
            return Column(children: [
              for (final c in snap.data!.items)
                Card(
                  child: ListTile(
                    title: Text(c.title),
                    subtitle: Text(c.published ? 'yayında' : 'taslak'),
                    trailing: IconButton(
                        icon: const Icon(Icons.edit_outlined),
                        onPressed: () => _edit(c)),
                  ),
                ),
            ]);
          },
        ),
        const SizedBox(height: 16),
        const Text('Danışmanlık başvuruları',
            style: TextStyle(fontWeight: FontWeight.w800)),
        FutureBuilder<List<ConsultationApplication>>(
          future: _appsFuture,
          builder: (context, snap) {
            if (!snap.hasData) return const SizedBox.shrink();
            if (snap.data!.isEmpty) {
              return const Padding(
                padding: EdgeInsets.only(top: 8),
                child: Text('Başvuru yok.', style: TextStyle(color: ArucadColors.muted)),
              );
            }
            return Column(children: [
              for (final a in snap.data!)
                Card(
                  child: ListTile(
                    title: Text(a.consultationTitle ?? a.consultationId),
                    subtitle: Text('${a.userName ?? a.userId} · ${a.status}'),
                    trailing: PopupMenuButton<String>(
                      onSelected: (v) async {
                        await widget.repository
                            .updateConsultationApplication(a.id, status: v);
                        if (mounted) _reload();
                      },
                      itemBuilder: (_) => const [
                        PopupMenuItem(value: 'pending', child: Text('Beklemede')),
                        PopupMenuItem(value: 'reviewed', child: Text('İncelendi')),
                        PopupMenuItem(value: 'accepted', child: Text('Kabul')),
                        PopupMenuItem(value: 'rejected', child: Text('Red')),
                      ],
                    ),
                  ),
                ),
            ]);
          },
        ),
      ],
    );
  }
}
