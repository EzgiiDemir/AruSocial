part of '../admin_panel_screen.dart';

// ---------------------------------------------------------------- Events

class _EventsTab extends StatefulWidget {
  final CampusRepository repository;
  final String uploaderName;
  const _EventsTab({required this.repository, required this.uploaderName});

  @override
  State<_EventsTab> createState() => _EventsTabState();
}

class _EventsTabState extends State<_EventsTab> {
  late Future<List<CampusEvent>> _future;
  String _query = '';
  final Set<String> _selected = {};

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getEvents(includeUnpublished: true);
  }

  Future<void> _deleteSelected() async {
    for (final id in _selected) {
      await widget.repository.deleteEvent(id);
    }
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.uploaderName,
        action: 'delete',
        targetType: 'event',
        targetLabel: '${_selected.length} etkinlik (toplu)');
    setState(() => _selected.clear());
    _reload();
  }

  void _reload() =>
      setState(() { _future = widget.repository.getEvents(includeUnpublished: true); });

  DateTime? _parseDate(String text) {
    final trimmed = text.trim();
    if (trimmed.isEmpty) return null;
    return DateTime.tryParse(trimmed);
  }

  Future<void> _editEvent([CampusEvent? existing]) async {
    final strings = AdminLocale.of(context);
    final places = await widget.repository.getPlaces();
    final academicYears = await widget.repository.getAcademicYears();
    if (!mounted) return;
    final titleC = TextEditingController(text: existing?.title);
    final timeC = TextEditingController(text: existing?.time ?? '14:00');
    final placeC = TextEditingController(text: existing?.placeName);
    var category = ContentCategories.activity.contains(existing?.category)
        ? existing?.category
        : ContentCategories.activity.first;
    final attendeesC = TextEditingController(text: '${existing?.attendees ?? 0}');
    final xpC = TextEditingController(text: '${existing?.xp ?? 50}');
    final audienceC = TextEditingController(text: existing?.audience ?? 'Tümü');
    final organizerC = TextEditingController(text: existing?.organizer);
    final organizerEmailC = TextEditingController(text: existing?.organizerEmail);
    final descriptionC = TextEditingController(text: existing?.description);
    final publishC =
        TextEditingController(text: existing?.publishAt?.toIso8601String().substring(0, 10) ?? '');
    final expiresC =
        TextEditingController(text: existing?.expiresAt?.toIso8601String().substring(0, 10) ?? '');
    var draft = existing?.draft ?? false;
    var body = existing?.body ?? const <ContentBlock>[];
    String? placeId = existing?.placeId;
    String? academicYearId = existing?.academicYearId;
    DateTime? eventDate = existing?.eventDate;
    var booked = const <PlaceBooking>[];

    // Real "boş/dolu" mekân müsaitliği (docs/EKSIKLER.md §4): what's
    // already booked at the chosen place on the chosen date, so the admin
    // sees a real conflict before hitting Kaydet, not just after a
    // rejected save.
    Future<void> refreshAvailability(void Function(void Function()) setDialogState) async {
      final pid = placeId;
      final date = eventDate;
      if (pid == null || date == null) {
        setDialogState(() => booked = const []);
        return;
      }
      final result = await widget.repository.getPlaceAvailability(pid, date);
      setDialogState(() => booked = result.where((b) => b.eventId != existing?.id).toList());
    }

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? strings.t('admin_event_new') : strings.t('admin_event_edit')),
          content: _DialogShell(fields: [
            _DialogSection(strings.t('admin_section_basic_info')),
            TextField(controller: titleC, decoration: InputDecoration(labelText: strings.t('admin_field_title'))),
            TextField(
                controller: timeC,
                decoration: InputDecoration(labelText: strings.t('admin_event_time')),
                onChanged: (_) => refreshAvailability(setDialogState)),
            InkWell(
              onTap: () async {
                final now = DateTime.now();
                final picked = await showDatePicker(
                  context: ctx,
                  initialDate: eventDate ?? now,
                  firstDate: now.subtract(const Duration(days: 365)),
                  lastDate: now.add(const Duration(days: 730)),
                );
                if (picked == null) return;
                setDialogState(() => eventDate = picked);
                await refreshAvailability(setDialogState);
              },
              child: InputDecorator(
                decoration: const InputDecoration(labelText: 'Tarih (müsaitlik kontrolü için)'),
                child: Text(eventDate == null
                    ? 'Tarih seç (opsiyonel)'
                    : '${eventDate!.day.toString().padLeft(2, '0')}.${eventDate!.month.toString().padLeft(2, '0')}.${eventDate!.year}'),
              ),
            ),
            if (booked.isNotEmpty)
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                    color: ArucadColors.warning.withValues(alpha: .1),
                    borderRadius: BorderRadius.circular(12)),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  const Text('Bu mekân o gün şu saatlerde dolu:',
                      style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5)),
                  const SizedBox(height: 4),
                  for (final b in booked)
                    Text('${b.time} — ${b.title}',
                        style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5)),
                ]),
              ),
            DropdownButtonFormField<String?>(
              initialValue: places.any((p) => p.id == placeId) ? placeId : null,
              decoration: InputDecoration(labelText: strings.t('admin_event_registered_place')),
              items: [
                const DropdownMenuItem<String?>(value: null, child: Text('— Serbest metin —')),
                ...places.map((p) => DropdownMenuItem<String?>(value: p.id, child: Text(p.name))),
              ],
              onChanged: (v) {
                setDialogState(() {
                  placeId = v;
                  if (v != null) placeC.text = places.firstWhere((p) => p.id == v).name;
                });
                refreshAvailability(setDialogState);
              },
            ),
            TextField(controller: placeC, decoration: InputDecoration(labelText: strings.t('admin_field_place'))),
            DropdownButtonFormField<String>(
              initialValue: category,
              decoration: InputDecoration(labelText: strings.t('admin_field_category')),
              items: [
                for (final c in ContentCategories.activity)
                  DropdownMenuItem(value: c, child: Text(c)),
              ],
              onChanged: (v) => setDialogState(() => category = v),
            ),
            TextField(controller: organizerC, decoration: InputDecoration(labelText: strings.t('admin_event_organizer'))),
            TextField(
                controller: organizerEmailC,
                decoration: InputDecoration(
                    labelText: strings.t('admin_event_organizer_email'),
                    hintText: strings.t('admin_event_organizer_email_hint'))),
            TextField(
                controller: descriptionC,
                decoration: InputDecoration(labelText: strings.t('admin_field_description_short')),
                maxLines: 3),
            Wrap(spacing: 8, runSpacing: 8, children: [
              OutlinedButton.icon(
                onPressed: () async {
                  final result = await openBlockEditor(ctx,
                      title: strings.t('admin_event_content_title'),
                      initialBlocks: body,
                      uploaderName: widget.uploaderName,
                      repository: widget.repository,
                      draftKey: existing == null ? null : 'event:${existing.id}');
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
                            repository: widget.repository, contentKey: 'event:${existing.id}');
                    if (restored != null) setDialogState(() => body = restored);
                  },
                  icon: const Icon(Icons.history_outlined, size: 16),
                  label: Text(strings.t('admin_history')),
                ),
              if (existing != null)
                OutlinedButton.icon(
                  onPressed: () => _manageParticipationTypes(existing),
                  icon: const Icon(Icons.groups_2_outlined, size: 16),
                  label: Text(strings.t('admin_event_participation_types')),
                ),
              if (existing != null)
                OutlinedButton.icon(
                  onPressed: () => _manageAttendance(existing),
                  icon: const Icon(Icons.how_to_reg_outlined, size: 16),
                  label: Text(strings.t('admin_event_attendance')),
                ),
            ]),
            TextField(
                controller: attendeesC,
                decoration: InputDecoration(labelText: strings.t('admin_event_attendees')),
                keyboardType: TextInputType.number),
            TextField(
                controller: xpC,
                decoration: InputDecoration(labelText: strings.t('admin_event_xp')),
                keyboardType: TextInputType.number),
            _DialogSection(strings.t('admin_section_publishing')),
            TextField(
                controller: audienceC,
                decoration: InputDecoration(
                    labelText: strings.t('admin_event_audience'),
                    hintText: strings.t('admin_event_audience_hint'))),
            DropdownButtonFormField<String?>(
              initialValue: academicYears.any((y) => y.id == academicYearId) ? academicYearId : null,
              decoration: InputDecoration(labelText: strings.t('admin_event_academic_year')),
              items: [
                const DropdownMenuItem<String?>(value: null, child: Text('— Yok —')),
                ...academicYears.map((y) => DropdownMenuItem<String?>(value: y.id, child: Text(y.label))),
              ],
              onChanged: (v) => setDialogState(() => academicYearId = v),
            ),
            TextField(
                controller: publishC,
                decoration: InputDecoration(labelText: strings.t('admin_event_publish_date'))),
            TextField(
                controller: expiresC,
                decoration: InputDecoration(labelText: strings.t('admin_event_expiry_date'))),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(strings.t('admin_event_draft_switch')),
              value: draft,
              onChanged: (v) => setDialogState(() => draft = v),
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
    final id = existing?.id ?? 'event-${DateTime.now().millisecondsSinceEpoch}';
    try {
      await widget.repository.upsertEvent(CampusEvent(
      id: id,
      title: titleC.text.trim(),
      time: timeC.text.trim(),
      eventDate: eventDate,
      placeName: placeC.text.trim(),
      placeId: placeId,
      category: category ?? ContentCategories.activity.first,
      attendees: int.tryParse(attendeesC.text) ?? 0,
      xp: int.tryParse(xpC.text) ?? 0,
      draft: draft,
      publishAt: _parseDate(publishC.text),
      expiresAt: _parseDate(expiresC.text),
      audience: audienceC.text.trim().isEmpty ? 'Tümü' : audienceC.text.trim(),
      organizer: organizerC.text.trim(),
      organizerEmail: organizerEmailC.text.trim().isEmpty ? null : organizerEmailC.text.trim(),
      description: descriptionC.text.trim(),
      academicYearId: academicYearId,
      body: body,
      ));
      await widget.repository.recordRevision('event:$id', body, widget.uploaderName);
      await AuditLogStore.logIfMock(widget.repository,
          actorName: widget.uploaderName,
          action: existing == null ? 'create' : 'update',
          targetType: 'event',
          targetLabel: titleC.text.trim());
      _reload();
    } on PlaceConflictException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.reason)));
    }
  }

  /// Real, admin-managed participation options for one event (e.g.
  /// Katılımcı/Gönüllü/Organizasyon) — what the student-facing join popup
  /// (`showEventJoinSheet`) actually reads. Immediate-effect (each add/
  /// delete calls the backend right away), independent of the outer
  /// dialog's own "Kaydet" button.
  Future<void> _manageParticipationTypes(CampusEvent event) async {
    final strings = AdminLocale.of(context);
    var types = [...event.participationTypes];
    final labelC = TextEditingController();
    await showDialog<void>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(strings.t('admin_event_participation_types')),
          content: SizedBox(
            width: 360,
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              if (types.isEmpty)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 12),
                  child: Text(strings.t('admin_event_participation_types_empty'),
                      style: const TextStyle(color: ArucadColors.muted)),
                )
              else
                for (final t in types)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    dense: true,
                    title: Text(t.label),
                    trailing: IconButton(
                      icon: const Icon(Icons.delete_outline, size: 20),
                      onPressed: () async {
                        await widget.repository.deleteParticipationType(event.id, t.id);
                        setDialogState(() => types = types.where((x) => x.id != t.id).toList());
                        _reload();
                      },
                    ),
                  ),
              const Divider(),
              Row(children: [
                Expanded(
                  child: TextField(
                    controller: labelC,
                    decoration: InputDecoration(hintText: strings.t('admin_event_participation_type_hint')),
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.add_circle_outline),
                  onPressed: () async {
                    final label = labelC.text.trim();
                    if (label.isEmpty) return;
                    final created = await widget.repository.upsertParticipationType(event.id,
                        label: label, sortOrder: types.length);
                    labelC.clear();
                    setDialogState(() => types = [...types, created]);
                    _reload();
                  },
                ),
              ]),
            ]),
          ),
          actions: [
            FilledButton(onPressed: () => Navigator.pop(ctx), child: Text(strings.t('admin_close'))),
          ],
        ),
      ),
    );
  }

  /// Real attendance roster ("yoklama") — who actually joined, with their
  /// chosen participation type, approvable one by one. This is the real
  /// club-manager/teacher step after the join-time 2-stage email: joining
  /// alone doesn't mean "attending" until someone here says so.
  Future<void> _manageAttendance(CampusEvent event) async {
    final strings = AdminLocale.of(context);
    var future = widget.repository.getEventParticipants(event.id);
    await showDialog<void>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(strings.t('admin_event_attendance')),
          content: SizedBox(
            width: 380,
            child: FutureBuilder<List<EventParticipant>>(
              future: future,
              builder: (context, snap) {
                if (!snap.hasData) {
                  return const SizedBox(
                      height: 80, child: Center(child: CircularProgressIndicator()));
                }
                final participants = snap.data!;
                if (participants.isEmpty) {
                  return Padding(
                    padding: const EdgeInsets.symmetric(vertical: 12),
                    child: Text(strings.t('admin_event_attendance_empty'),
                        style: const TextStyle(color: ArucadColors.muted)),
                  );
                }
                return SizedBox(
                  height: 320,
                  child: ListView.separated(
                    shrinkWrap: true,
                    itemCount: participants.length,
                    separatorBuilder: (_, __) => const Divider(height: 1),
                    itemBuilder: (context, i) {
                      final p = participants[i];
                      return ListTile(
                        contentPadding: EdgeInsets.zero,
                        title: Text(p.studentName ?? p.userId ?? '—',
                            style: const TextStyle(fontWeight: FontWeight.w700)),
                        subtitle: Text(p.participationTypeLabel ?? strings.t('admin_none')),
                        // Approval is a hard backend gate on form
                        // completion (FORM_NOT_SUBMITTED) — mirrored here
                        // so the button never fires a request doomed to
                        // fail, and the admin sees exactly why it's not
                        // actionable yet.
                        trailing: p.isApproved
                            ? const Icon(Icons.check_circle, color: ArucadColors.success)
                            : !p.isFormSubmitted
                                ? Text(strings.t('admin_event_attendance_form_pending'),
                                    style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5))
                                : TextButton(
                                    onPressed: () async {
                                      await widget.repository
                                          .approveEventParticipant(event.id, p.id);
                                      setDialogState(() => future =
                                          widget.repository.getEventParticipants(event.id));
                                    },
                                    child: Text(strings.t('admin_event_attendance_approve')),
                                  ),
                      );
                    },
                  ),
                );
              },
            ),
          ),
          actions: [
            FilledButton(onPressed: () => Navigator.pop(ctx), child: Text(strings.t('admin_close'))),
          ],
        ),
      ),
    );
  }

  String? _statusLabel(AdminStrings strings, CampusEvent e) {
    if (e.aiDraft && e.draft) return 'AI taslak';
    if (e.draft) return strings.t('admin_status_draft');
    final now = DateTime.now();
    if (e.publishAt != null && e.publishAt!.isAfter(now)) return strings.t('admin_status_scheduled');
    if (e.expiresAt != null && e.expiresAt!.isBefore(now)) return strings.t('admin_status_expired');
    return null;
  }

  Future<void> _draftFromPoster() async {
    final picked = await ImagePicker().pickImage(source: ImageSource.gallery, imageQuality: 85);
    if (picked == null) return;
    try {
      final bytes = await picked.readAsBytes();
      final draft = await widget.repository
          .draftEventFromPoster(bytes, fileName: picked.name);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(
              'Taslak oluşturuldu: ${draft.title} (yayınlanmadı — incele ve kaydet)')));
      _reload();
      await _editEvent(draft);
    } catch (e) {
      if (!mounted) return;
      final msg = e is ApiClientException
          ? (e.code == 'AI_NOT_CONFIGURED'
              ? 'AI yapılandırılmamış (GROQ_API_KEY). Sahte AI sonucu kullanılmaz.'
              : e.message)
          : '$e';
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    return Scaffold(
      floatingActionButton: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          FloatingActionButton.extended(
            heroTag: 'poster-draft',
            onPressed: _draftFromPoster,
            icon: const Icon(Icons.image_search_outlined),
            label: const Text('Poster → taslak'),
          ),
          const SizedBox(height: 10),
          FloatingActionButton(
              heroTag: 'event-add',
              onPressed: () => _editEvent(),
              child: const Icon(Icons.add)),
        ],
      ),
      body: Column(children: [
        _AdminListToolbar(
          searchHint: strings.t('admin_search_events'),
          onQueryChanged: (v) => setState(() => _query = v),
          selectedCount: _selected.length,
          onCancelSelection: () => setState(_selected.clear),
          onDeleteSelected: _deleteSelected,
        ),
        Expanded(
          child: FutureBuilder<List<CampusEvent>>(
            future: _future,
            builder: (context, snap) {
              if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
              final events = snap.data!
                  .where((e) => e.title.toLowerCase().contains(_query.toLowerCase()))
                  .toList();
              if (events.isEmpty) {
                return Center(child: Text(AdminLocale.of(context).t('admin_empty_events')));
              }
              return ListView.separated(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 90),
                itemCount: events.length,
                separatorBuilder: (_, __) => const SizedBox(height: 8),
                itemBuilder: (context, i) {
                  final e = events[i];
                  final status = _statusLabel(AdminLocale.of(context), e);
                  final isSelected = _selected.contains(e.id);
                  return Card(
                    child: ListTile(
                      leading: _selected.isEmpty
                          ? null
                          : Checkbox(
                              value: isSelected,
                              onChanged: (_) => setState(() =>
                                  isSelected ? _selected.remove(e.id) : _selected.add(e.id)),
                            ),
                      title: Row(children: [
                        Expanded(
                            child: Text(e.title,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(fontWeight: FontWeight.w800))),
                        if (status != null) ...[
                          const SizedBox(width: 6),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                            decoration: BoxDecoration(
                                color: ArucadColors.warning.withValues(alpha: .15),
                                borderRadius: BorderRadius.circular(999)),
                            child: Text(status,
                                style:
                                    const TextStyle(fontSize: 11, color: ArucadColors.warning)),
                          ),
                        ],
                      ]),
                      subtitle: Text(
                          '${e.time} · ${e.placeName} · ${e.category} · +${e.xp} XP · ${e.audience}',
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis),
                      onTap: () =>
                          _selected.isEmpty ? _editEvent(e) : setState(() => isSelected
                              ? _selected.remove(e.id)
                              : _selected.add(e.id)),
                      onLongPress: () => setState(() => _selected.add(e.id)),
                      trailing: _selected.isNotEmpty
                          ? null
                          : IconButton(
                              icon: const Icon(Icons.delete_outline),
                              onPressed: () async {
                                await widget.repository.deleteEvent(e.id);
                                await AuditLogStore.logIfMock(widget.repository,
                                    actorName: widget.uploaderName,
                                    action: 'delete',
                                    targetType: 'event',
                                    targetLabel: e.title);
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
