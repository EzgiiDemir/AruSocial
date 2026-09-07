part of '../admin_panel_screen.dart';

// --------------------------------------------------------- Academic Years

class _AcademicYearsTab extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;
  const _AcademicYearsTab({required this.repository, required this.adminName});
  @override
  State<_AcademicYearsTab> createState() => _AcademicYearsTabState();
}

class _AcademicYearsTabState extends State<_AcademicYearsTab> {
  late Future<List<AcademicYear>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getAcademicYears();
  }

  void _reload() => setState(() { _future = widget.repository.getAcademicYears(); });

  Future<void> _editYear([AcademicYear? existing]) async {
    final idC = TextEditingController(text: existing?.id);
    final labelC = TextEditingController(text: existing?.label);
    final startsC = TextEditingController(
        text: existing?.startsOn.toIso8601String().substring(0, 10));
    final endsC =
        TextEditingController(text: existing?.endsOn.toIso8601String().substring(0, 10));
    var isActive = existing?.isActive ?? false;

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? 'Yeni Akademik Yıl' : 'Akademik Yılı Düzenle'),
          content: _DialogShell(fields: [
            TextField(
                controller: idC,
                enabled: existing == null,
                decoration: const InputDecoration(labelText: 'ID (örn. 2026-2027)')),
            TextField(controller: labelC, decoration: const InputDecoration(labelText: 'Etiket')),
            TextField(
                controller: startsC,
                decoration: const InputDecoration(labelText: 'Başlangıç (YYYY-MM-DD)')),
            TextField(
                controller: endsC, decoration: const InputDecoration(labelText: 'Bitiş (YYYY-MM-DD)')),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: const Text('Aktif yıl (diğerlerini pasifleştirir)'),
              value: isActive,
              onChanged: (v) => setDialogState(() => isActive = v),
            ),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Kaydet')),
          ],
        ),
      ),
    );
    final starts = DateTime.tryParse(startsC.text.trim());
    final ends = DateTime.tryParse(endsC.text.trim());
    if (saved != true || idC.text.trim().isEmpty || labelC.text.trim().isEmpty ||
        starts == null || ends == null) {
      return;
    }
    await widget.repository.upsertAcademicYear(
      id: idC.text.trim(),
      label: labelC.text.trim(),
      startsOn: starts,
      endsOn: ends,
      isActive: isActive,
    );
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: existing == null ? 'create' : 'update',
        targetType: 'academic_year',
        targetLabel: labelC.text.trim());
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      floatingActionButton: FloatingActionButton(
          onPressed: () => _editYear(), child: const Icon(Icons.add)),
      body: FutureBuilder<List<AcademicYear>>(
        future: _future,
        builder: (context, snap) {
          if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
          final years = snap.data!;
          return ListView.separated(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 90),
            itemCount: years.length,
            separatorBuilder: (_, __) => const SizedBox(height: 8),
            itemBuilder: (context, i) {
              final y = years[i];
              return Card(
                child: ListTile(
                  title: Text(y.label, style: const TextStyle(fontWeight: FontWeight.w800)),
                  subtitle: Text(
                      '${y.startsOn.toIso8601String().substring(0, 10)} → '
                      '${y.endsOn.toIso8601String().substring(0, 10)}'),
                  trailing: y.isActive
                      ? Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                          decoration: BoxDecoration(
                              color: ArucadColors.success.withValues(alpha: .15),
                              borderRadius: BorderRadius.circular(999)),
                          child: const Text('Aktif',
                              style: TextStyle(fontSize: 11, color: ArucadColors.success)),
                        )
                      : null,
                  onTap: () => _editYear(y),
                ),
              );
            },
          );
        },
      ),
    );
  }
}
