part of '../admin_panel_screen.dart';

class _ApplicationsTab extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;
  const _ApplicationsTab({required this.repository, required this.adminName});
  @override
  State<_ApplicationsTab> createState() => _ApplicationsTabState();
}

class _ApplicationsTabState extends State<_ApplicationsTab> {
  late Future<List<ParticipationApplication>> _future;
  Timer? _poll;

  @override
  void initState() {
    super.initState();
    _reload();
    _poll = Timer.periodic(const Duration(seconds: 30), (_) {
      if (mounted) _reload();
    });
  }

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  void _reload() => setState(() { _future = widget.repository.getAdminApplications(); });

  Future<void> _decide(ParticipationApplication a, String action) async {
    if (action == 'approve') {
      await widget.repository.approveApplication(a.id);
    } else {
      final isReject = action == 'reject';
      final noteC = TextEditingController();
      final ok = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Text(isReject ? 'Başvuruyu reddet' : 'Revizyon iste'),
          content: TextField(
            controller: noteC,
            decoration: InputDecoration(
                labelText: isReject
                    ? 'Red sebebi (öğrenciye e-posta ile iletilir) *'
                    : 'Öğrenciden ne değiştirmesi istendiği (e-posta ile iletilir) *'),
            maxLines: 3,
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
            FilledButton(
                onPressed: () => Navigator.pop(ctx, true),
                child: Text(isReject ? 'Reddet' : 'Revizyon İste')),
          ],
        ),
      );
      if (ok != true) return;
      final note = noteC.text.trim();
      if (note.isEmpty) {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
            content: Text(isReject
                ? 'Reddetme sebebi zorunludur.'
                : 'Revizyon notu zorunludur.')));
        return;
      }
      if (isReject) {
        await widget.repository.rejectApplication(a.id, reviewNote: note);
      } else {
        await widget.repository.requestApplicationRevision(a.id, reviewNote: note);
      }
    }
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: action,
        targetType: 'application',
        targetLabel: '${a.targetType}:${a.targetId}');
    if (mounted) _reload();
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<List<ParticipationApplication>>(
      future: _future,
      builder: (context, snap) {
        if (snap.hasError) return _AdminLoadError(error: snap.error!);
        if (!snap.hasData) return const Center(child: CircularProgressIndicator());
        final items = snap.data!;
        return ListView(
          padding: const EdgeInsets.all(20),
          children: [
            const Text('Bekleyen başvurular',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
            const SizedBox(height: 12),
            if (items.isEmpty)
              const Text('Bekleyen başvuru yok.', style: TextStyle(color: ArucadColors.muted)),
            for (final a in items)
              Card(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(12, 10, 8, 10),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        title: Text(a.targetLabel ?? '${a.targetType} · ${a.targetId}',
                            style: const TextStyle(fontWeight: FontWeight.w700)),
                        subtitle: Text(
                            '${a.studentName ?? a.userId} · ${a.responsibleStaffName ?? 'sorumlu yok'}\n${a.statusLabel}'),
                        isThreeLine: true,
                        trailing: a.isDecidable
                            ? Row(mainAxisSize: MainAxisSize.min, children: [
                          IconButton(
                              icon: const Icon(Icons.check_circle_outline,
                                  color: ArucadColors.success),
                              tooltip: 'Onayla',
                              onPressed: () => _decide(a, 'approve')),
                          IconButton(
                              icon: const Icon(Icons.edit_note_outlined,
                                  color: ArucadColors.warning),
                              tooltip: 'Revizyon iste',
                              onPressed: () => _decide(a, 'revise')),
                          IconButton(
                              icon: const Icon(Icons.cancel_outlined,
                                  color: ArucadColors.danger),
                              tooltip: 'Reddet',
                              onPressed: () => _decide(a, 'reject')),
                        ])
                            : null,
                      ),
                      Padding(
                        padding: const EdgeInsets.only(bottom: 6),
                        child: EmailStatusRow(app: a),
                      ),
                      if (a.awaitingStudentDetail)
                        const Padding(
                          padding: EdgeInsets.only(bottom: 8),
                          child: Text(
                            'Öğrenci detay formunu henüz tamamlamadı — onay/red bu adımdan sonra açılır.',
                            style: TextStyle(color: ArucadColors.muted, fontSize: 12.5),
                          ),
                        ),
                      if (a.formPayload.isNotEmpty) ...[
                        const Divider(height: 8),
                        const Text('Ön başvuru',
                            style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12)),
                        for (final e in a.formPayload.entries)
                          if ('${e.value}'.trim().isNotEmpty)
                            Padding(
                              padding: const EdgeInsets.only(bottom: 4),
                              child: Text('${e.key}: ${e.value}',
                                  style: const TextStyle(
                                      color: ArucadColors.muted, fontSize: 12.5)),
                            ),
                      ],
                      if (a.detailPayload.isNotEmpty) ...[
                        const Divider(height: 8),
                        const Text('Detay form',
                            style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12)),
                        for (final e in a.detailPayload.entries)
                          if ('${e.value}'.trim().isNotEmpty)
                            Padding(
                              padding: const EdgeInsets.only(bottom: 4),
                              child: Text('${e.key}: ${e.value}',
                                  style: const TextStyle(
                                      color: ArucadColors.muted, fontSize: 12.5)),
                            ),
                      ],
                    ],
                  ),
                ),
              ),
          ],
        );
      },
    );
  }
}

class _StaffTab extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;
  const _StaffTab({required this.repository, required this.adminName});
  @override
  State<_StaffTab> createState() => _StaffTabState();
}

class _StaffTabState extends State<_StaffTab> {
  late Future<List<StaffProfile>> _future;
  late Future<List<AppointmentBooking>> _appointmentsFuture;
  String _q = '';
  String? _apptStatus;
  String _apptQuery = '';
  Timer? _apptPoll;
  ChatRealtimeService? _realtime;
  StreamSubscription<void>? _rtSub;

  @override
  void initState() {
    super.initState();
    _reload();
    _apptPoll = Timer.periodic(const Duration(seconds: 15), (_) {
      if (mounted) _reloadAppointments();
    });
    unawaited(_bindRealtime());
  }

  Future<void> _bindRealtime() async {
    try {
      final me = await widget.repository.getMe();
      if (!mounted) return;
      final rt = ChatRealtimeService.forRepository(widget.repository);
      _realtime = rt;
      _rtSub = rt.appointmentChanged.listen((_) {
        if (mounted) _reloadAppointments();
      });
      await rt.start(
        userId: me.id,
        userName: me.name,
        subscribeOpsAppointments: me.parsedRole.canManageAppointments,
      );
    } catch (_) {}
  }

  @override
  void dispose() {
    _apptPoll?.cancel();
    unawaited(_rtSub?.cancel() ?? Future.value());
    unawaited(_realtime?.dispose() ?? Future.value());
    super.dispose();
  }

  void _reloadStaff() =>
      setState(() { _future = widget.repository.getAdminStaff(q: _q.isEmpty ? null : _q); });

  void _reloadAppointments() =>
      setState(() {
        _appointmentsFuture = widget.repository.getAdminAppointments(
          status: _apptStatus,
          q: _apptQuery.isEmpty ? null : _apptQuery,
        );
      });

  void _reload() {
    _reloadStaff();
    _reloadAppointments();
  }

  Future<void> _updateAppointment(AppointmentBooking a, String action) async {
    try {
      if (action == 'note') {
        final noteC = TextEditingController(text: a.adminNotes);
        final ok = await showDialog<bool>(
          context: context,
          builder: (ctx) => AlertDialog(
            title: const Text('Yönetici notu'),
            content: TextField(controller: noteC, maxLines: 3),
            actions: [
              TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
              FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Kaydet')),
            ],
          ),
        );
        if (ok != true) return;
        await widget.repository.updateAdminAppointment(a.id, adminNotes: noteC.text.trim());
      } else {
        await widget.repository.updateAdminAppointment(a.id, status: action);
      }
      if (mounted) _reloadAppointments();
    } on ApiClientException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  Future<void> _edit([StaffProfile? existing]) async {
    final nameC = TextEditingController(text: existing?.name);
    final titleC = TextEditingController(text: existing?.title);
    final emailC = TextEditingController(text: existing?.email);

    var faculty = existing?.faculty;
    var department = existing?.department;
    var isDepartmentHead = existing?.isDepartmentHead ?? false;
    var active = existing?.active ?? true;

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) {
          final departmentOptions = faculty == null
              ? CampusTaxonomy.nonAcademicDepartments
              : CampusTaxonomy.faculties[faculty]!;
          return AlertDialog(
            title: Text(existing == null ? 'Yeni personel' : 'Personeli düzenle'),
            content: SizedBox(
              width: 420,
              child: _DialogShell(fields: [
                TextField(controller: nameC, decoration: const InputDecoration(labelText: 'Ad Soyad')),
                DropdownButtonFormField<String?>(
                  initialValue: faculty,
                  decoration: const InputDecoration(labelText: 'Fakülte (idari birimse boş bırak)'),
                  items: [
                    const DropdownMenuItem<String?>(value: null, child: Text('— İdari / Destek —')),
                    for (final f in CampusTaxonomy.faculties.keys)
                      DropdownMenuItem<String?>(value: f, child: Text(f)),
                  ],
                  onChanged: (v) => setDialogState(() {
                    faculty = v;
                    department = null;
                  }),
                ),
                DropdownButtonFormField<String?>(
                  initialValue: departmentOptions.contains(department) ? department : null,
                  decoration: const InputDecoration(labelText: 'Bölüm / Birim'),
                  items: [
                    const DropdownMenuItem<String?>(value: null, child: Text('— Seç —')),
                    for (final d in departmentOptions) DropdownMenuItem<String?>(value: d, child: Text(d)),
                  ],
                  onChanged: (v) => setDialogState(() => department = v),
                ),
                TextField(controller: titleC, decoration: const InputDecoration(labelText: 'Unvan')),
                TextField(controller: emailC, decoration: const InputDecoration(labelText: 'E-posta (biliniyorsa)')),
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  title: const Text('Bölüm başkanı'),
                  value: isDepartmentHead,
                  onChanged: (v) => setDialogState(() => isDepartmentHead = v),
                ),
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  title: const Text('Aktif'),
                  value: active,
                  onChanged: (v) => setDialogState(() => active = v),
                ),
                const Text('E-posta mevcut bir kullanıcı hesabıyla eşleşirse otomatik bağlanır. Panel erişimi için Kullanıcılar ve Roller bölümünden Trainer rolünü atayın.', style: TextStyle(color: ArucadColors.muted, fontSize: 12)),
              ]),
            ),
            actions: [
              TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
              FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Kaydet')),
            ],
          );
        },
      ),
    );
    if (saved != true || nameC.text.trim().isEmpty) return;
    await widget.repository.upsertStaffProfile(StaffProfile(
      id: existing?.id ?? 'staff-${DateTime.now().millisecondsSinceEpoch}',
      name: nameC.text.trim(),
      faculty: faculty,
      department: department,
      title: titleC.text.trim().isEmpty ? null : titleC.text.trim(),
      email: emailC.text.trim().isEmpty ? null : emailC.text.trim(),
      isDepartmentHead: isDepartmentHead,
      active: active,
      userId: emailC.text.trim().toLowerCase() == (existing?.email ?? '').toLowerCase() ? existing?.userId : null,
    ));
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: existing == null ? 'create' : 'update',
        targetType: 'staff',
        targetLabel: nameC.text.trim());
    if (mounted) _reload();
  }

  Future<void> _delete(StaffProfile s) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Personel silinsin mi?'),
        content: Text(s.name),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Sil')),
        ],
      ),
    );
    if (ok != true) return;
    await widget.repository.deleteStaffProfile(s.id);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName, action: 'delete', targetType: 'staff', targetLabel: s.name);
    if (mounted) _reload();
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<List<StaffProfile>>(
      future: _future,
      builder: (context, snap) {
        if (snap.hasError) return _AdminLoadError(error: snap.error!);
        if (!snap.hasData) return const Center(child: CircularProgressIndicator());
        final items = snap.data!;
        final heads = items.where((s) => s.isDepartmentHead).length;
        final withEmail = items.where((s) => (s.email ?? '').isNotEmpty).length;
        final byFaculty = <String, List<StaffProfile>>{};
        for (final s in items) {
          final key = s.faculty?.trim().isNotEmpty == true ? s.faculty! : 'İdari / Destek';
          byFaculty.putIfAbsent(key, () => []).add(s);
        }
        final faculties = byFaculty.keys.toList()..sort();
        return Scaffold(
          floatingActionButton: FloatingActionButton.extended(
            onPressed: () => _edit(),
            icon: const Icon(Icons.add),
            label: const Text('Personel Ekle'),
          ),
          body: ListView(
            padding: const EdgeInsets.fromLTRB(20, 20, 20, 90),
            children: [
              const Text('Akademik personel (CRM)',
                  style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
              const SizedBox(height: 6),
              Text(
                '${items.length} kayıt · $heads bölüm başkanı · $withEmail e-posta tanımlı '
                '(e-posta yoksa uydurulmaz)',
                style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5),
              ),
              const SizedBox(height: 8),
              FutureBuilder<List<AppointmentBooking>>(
                future: _appointmentsFuture,
                builder: (context, snap) {
                  if (snap.hasError) {
                    return const Padding(
                      padding: EdgeInsets.only(bottom: 12),
                      child: Text('Randevular yüklenemedi.',
                          style: TextStyle(color: ArucadColors.muted)),
                    );
                  }
                  final rows = snap.data;
                  if (rows == null) {
                    return const Padding(
                      padding: EdgeInsets.only(bottom: 12),
                      child: LinearProgressIndicator(),
                    );
                  }
                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('Randevular',
                          style: TextStyle(fontWeight: FontWeight.w800)),
                      const SizedBox(height: 6),
                      TextField(
                        decoration: const InputDecoration(
                          prefixIcon: Icon(Icons.search),
                          hintText: 'Öğrenci, konu veya sorumlu ara',
                        ),
                        onChanged: (v) {
                          _apptQuery = v;
                          _reloadAppointments();
                        },
                      ),
                      const SizedBox(height: 8),
                      Wrap(spacing: 8, children: [
                        for (final s in [null, 'pending', 'approved', 'rejected', 'cancelled', 'completed'])
                          ChoiceChip(
                            label: Text(s ?? 'Tümü'),
                            selected: _apptStatus == s,
                            onSelected: (_) {
                              _apptStatus = s;
                              _reloadAppointments();
                            },
                          ),
                      ]),
                      const SizedBox(height: 6),
                      if (rows.isEmpty)
                        const Padding(
                          padding: EdgeInsets.only(bottom: 16),
                          child: Text('Bu filtrede randevu yok.',
                              style: TextStyle(color: ArucadColors.muted, fontSize: 12.5)),
                        )
                      else
                        for (final a in rows)
                          Card(
                            child: ListTile(
                              title: Text(a.studentName ?? 'Öğrenci',
                                  style: const TextStyle(fontWeight: FontWeight.w700)),
                              subtitle: Text([
                                a.staffName ?? a.staffProfileId,
                                '${a.date} · ${a.startTime}-${a.endTime}',
                                a.status,
                                if (a.subject != null) a.subject!,
                              ].join(' · ')),
                              trailing: PopupMenuButton<String>(
                                onSelected: (v) => _updateAppointment(a, v),
                                itemBuilder: (_) => const [
                                  PopupMenuItem(value: 'approved', child: Text('Onayla')),
                                  PopupMenuItem(value: 'rejected', child: Text('Reddet')),
                                  PopupMenuItem(value: 'cancelled', child: Text('İptal')),
                                  PopupMenuItem(value: 'completed', child: Text('Tamamlandı')),
                                  PopupMenuItem(value: 'note', child: Text('Not ekle')),
                                ],
                              ),
                            ),
                          ),
                      const SizedBox(height: 16),
                    ],
                  );
                },
              ),
              TextField(
                decoration: const InputDecoration(
                    prefixIcon: Icon(Icons.search), hintText: 'Ara (ad / bölüm / unvan)'),
                onChanged: (v) {
                  _q = v;
                  _reloadStaff();
                },
              ),
              const SizedBox(height: 12),
              for (final faculty in faculties) ...[
                Padding(
                  padding: const EdgeInsets.only(top: 8, bottom: 4),
                  child: Text(faculty,
                      style: const TextStyle(
                          fontWeight: FontWeight.w800,
                          color: ArucadColors.primary)),
                ),
                for (final s in byFaculty[faculty]!)
                  Card(
                    child: ListTile(
                      title: Text(s.name, style: const TextStyle(fontWeight: FontWeight.w700)),
                      subtitle: Text([
                        if (s.department != null) s.department!,
                        if (s.title != null) s.title!,
                        if (s.isDepartmentHead) 'Bölüm Başkanı',
                        s.active ? 'aktif' : 'pasif',
                        s.email ?? 'e-posta yok',
                        s.userId != null ? 'hesap bağlı' : 'hesap bağlı değil',
                      ].join(' · ')),
                      isThreeLine: true,
                      trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                        IconButton(icon: const Icon(Icons.edit_outlined), onPressed: () => _edit(s)),
                        IconButton(icon: const Icon(Icons.delete_outline), onPressed: () => _delete(s)),
                      ]),
                    ),
                  ),
              ],
            ],
          ),
        );
      },
    );
  }
}
