part of '../admin_panel_screen.dart';

// --------------------------------------------------- Pending Activities

/// Real "Kendi Aktiviteni Oluştur" review queue — every student-submitted
/// activity starts as `pending_review` and stays invisible to everyone
/// else until an admin genuinely approves or rejects it here (see
/// `Api\Admin\EventController::approveActivity/rejectActivity`).
class _PendingActivitiesTab extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;
  const _PendingActivitiesTab({required this.repository, required this.adminName});
  @override
  State<_PendingActivitiesTab> createState() => _PendingActivitiesTabState();
}

class _PendingActivitiesTabState extends State<_PendingActivitiesTab> {
  late Future<List<CampusEvent>> _future;
  Timer? _poll;

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getPendingActivities();
    _poll = Timer.periodic(const Duration(seconds: 30), (_) {
      if (mounted) _reload();
    });
  }

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  void _reload() => setState(() { _future = widget.repository.getPendingActivities(); });

  Future<void> _approve(CampusEvent e) async {
    await widget.repository.approveActivity(e.id);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName, action: 'approve', targetType: 'event', targetLabel: e.title);
    _reload();
  }

  Future<void> _reject(CampusEvent e) async {
    final noteC = TextEditingController();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Aktiviteyi reddet'),
        content: TextField(
          controller: noteC,
          decoration: const InputDecoration(
              labelText: 'Red sebebi (öğrenciye e-posta ile iletilir) *'),
          maxLines: 3,
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Reddet')),
        ],
      ),
    );
    if (confirmed != true) return;
    final note = noteC.text.trim();
    if (note.isEmpty) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Reddetme için sebep yazmalısınız — öğrenciye e-posta gider.')));
      return;
    }
    await widget.repository.rejectActivity(e.id, reviewNote: note);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName, action: 'reject', targetType: 'event', targetLabel: e.title);
    _reload();
  }

  Future<void> _showPlaceCalendar(CampusEvent e) async {
    if (e.placeId == null || e.placeId!.isEmpty || e.eventDate == null) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Bu başvuruda mekân veya tarih yok — takvim açılamaz.')));
      return;
    }
    final booked = await widget.repository.getPlaceAvailability(e.placeId!, e.eventDate!);
    if (!mounted) return;
    await showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('${e.placeName} · ${e.eventDate!.day}.${e.eventDate!.month}.${e.eventDate!.year}'),
        content: SizedBox(
          width: 360,
          child: booked.isEmpty
              ? const Text('Bu günde kayıtlı başka aktivite yok — mekân boş görünüyor.')
              : Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('Dolu slotlar:', style: TextStyle(fontWeight: FontWeight.w700)),
                    const SizedBox(height: 8),
                    for (final b in booked)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 6),
                        child: Text('• ${b.time.isEmpty ? '—' : b.time}  ${b.title} (${b.workflowStatus})',
                            style: TextStyle(
                                color: b.workflowStatus == 'rejected'
                                    ? ArucadColors.muted
                                    : ArucadColors.danger)),
                      ),
                  ],
                ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Kapat')),
        ],
      ),
                );
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<List<CampusEvent>>(
      future: _future,
      builder: (context, snap) {
        if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
        final events = snap.data!;
        if (events.isEmpty) {
          return const Center(
            child: Padding(
              padding: EdgeInsets.all(32),
              child: Text('İncelenmeyi bekleyen öğrenci aktivitesi yok.',
                  textAlign: TextAlign.center, style: TextStyle(color: ArucadColors.muted)),
            ),
          );
        }
        return ListView.separated(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 90),
          itemCount: events.length,
          separatorBuilder: (_, __) => const SizedBox(height: 8),
          itemBuilder: (context, i) {
            final e = events[i];
            return Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(e.title, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                  const SizedBox(height: 4),
                  Text('${e.organizer} · ${e.placeName} · ${e.category}',
                      style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5)),
                  if (e.eventDate != null || e.time.isNotEmpty) ...[
                    const SizedBox(height: 4),
                    Text(
                        'Talep edilen: ${e.eventDate != null ? '${e.eventDate!.day}.${e.eventDate!.month}.${e.eventDate!.year}' : '—'} ${e.time}',
                        style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600)),
                  ],
                  if (e.responsibleStaffName != null && e.responsibleStaffName!.isNotEmpty) ...[
                    const SizedBox(height: 4),
                    Text('Bölüm başkanı: ${e.responsibleStaffName}',
                        style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5)),
                  ],
                  if (e.description.isNotEmpty) ...[
                    const SizedBox(height: 8),
                    Text(e.description, maxLines: 3, overflow: TextOverflow.ellipsis),
                  ],
                  const SizedBox(height: 10),
                  Align(
                    alignment: Alignment.centerLeft,
                    child: TextButton.icon(
                      onPressed: () => _showPlaceCalendar(e),
                      icon: const Icon(Icons.calendar_month_outlined, size: 18),
                      label: const Text('Mekân takvimi (dolu/boş)'),
                    ),
                  ),
                  const SizedBox(height: 4),
                  Row(children: [
                    Expanded(
                      child: OutlinedButton(
                        onPressed: () => _reject(e),
                        child: const Text('Reddet + sebep'),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: FilledButton(
                        onPressed: () => _approve(e),
                        child: const Text('Onayla'),
                      ),
                    ),
                  ]),
                ]),
              ),
            );
          },
        );
      },
    );
  }
}
