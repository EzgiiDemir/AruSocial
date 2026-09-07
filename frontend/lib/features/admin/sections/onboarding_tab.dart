part of '../admin_panel_screen.dart';

// ------------------------------------------------------------ Onboarding

/// Real backend + admin CRUD for the "First 30 Days" checklist — replaces
/// the previously fully hardcoded `onboardingSteps` const in
/// `onboarding_config.dart`, which no admin could ever change without
/// editing Dart code.
class _OnboardingTab extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;
  const _OnboardingTab({required this.repository, required this.adminName});
  @override
  State<_OnboardingTab> createState() => _OnboardingTabState();
}

class _OnboardingTabState extends State<_OnboardingTab> {
  late Future<List<OnboardingStep>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getAdminOnboardingSteps();
  }

  void _reload() =>
      setState(() => _future = widget.repository.getAdminOnboardingSteps());

  Future<void> _edit([OnboardingStep? existing]) async {
    final idC = TextEditingController(text: existing?.id);
    final groupC = TextEditingController(text: existing?.group);
    final titleC = TextEditingController(text: existing?.title);
    final detailC = TextEditingController(text: existing?.detail);
    final refIdC = TextEditingController(text: existing?.refId);
    final sortOrderC =
        TextEditingController(text: existing?.sortOrder.toString() ?? '0');
    var actionKind = existing?.actionKind ?? OnboardingActionKind.info;
    var active = existing?.active ?? true;

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? 'Yeni adım' : 'Adımı düzenle'),
          content: _DialogShell(fields: [
            TextField(
              controller: idC,
              enabled: existing == null,
              decoration: const InputDecoration(
                  labelText: 'Kimlik (id)', helperText: 'ör. week5-extra'),
            ),
            TextField(
                controller: groupC,
                decoration:
                    const InputDecoration(labelText: 'Grup (ör. "1. Hafta")')),
            TextField(
                controller: titleC,
                decoration: const InputDecoration(labelText: 'Başlık')),
            TextField(
              controller: detailC,
              decoration: const InputDecoration(labelText: 'Açıklama'),
              maxLines: 3,
            ),
            DropdownButtonFormField<OnboardingActionKind>(
              initialValue: actionKind,
              decoration: const InputDecoration(labelText: 'Aksiyon türü'),
              items: const [
                DropdownMenuItem(
                    value: OnboardingActionKind.info, child: Text('Bilgi')),
                DropdownMenuItem(
                    value: OnboardingActionKind.service,
                    child: Text('Hizmet detayına git')),
                DropdownMenuItem(
                    value: OnboardingActionKind.list,
                    child: Text('Liste göster (kulüp/spor)')),
              ],
              onChanged: (v) =>
                  setDialogState(() => actionKind = v ?? actionKind),
            ),
            TextField(
              controller: refIdC,
              decoration: const InputDecoration(
                  labelText: 'Referans id',
                  helperText:
                      'Hizmet için servis id\'si, liste için "clubs" veya "sports"'),
            ),
            TextField(
              controller: sortOrderC,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'Sıra'),
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: const Text('Aktif (öğrenciye görünür)'),
              value: active,
              onChanged: (v) => setDialogState(() => active = v),
            ),
          ]),
          actions: [
            TextButton(
                onPressed: () => Navigator.pop(ctx, false),
                child: const Text('Vazgeç')),
            FilledButton(
                onPressed: () => Navigator.pop(ctx, true),
                child: const Text('Kaydet')),
          ],
        ),
      ),
    );
    if (saved != true) return;
    final id = idC.text.trim();
    final group = groupC.text.trim();
    final title = titleC.text.trim();
    final detail = detailC.text.trim();
    if (id.isEmpty || group.isEmpty || title.isEmpty || detail.isEmpty) return;
    await widget.repository.upsertOnboardingStep(OnboardingStep(
      id: id,
      group: group,
      title: title,
      detail: detail,
      actionKind: actionKind,
      refId: refIdC.text.trim().isEmpty ? null : refIdC.text.trim(),
      sortOrder: int.tryParse(sortOrderC.text.trim()) ?? 0,
      active: active,
    ));
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: existing == null ? 'create' : 'update',
        targetType: 'onboarding_step',
        targetLabel: title);
    if (mounted) _reload();
  }

  Future<void> _delete(OnboardingStep step) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Adım silinsin mi?'),
        content: Text(step.title),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('Vazgeç')),
          FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('Sil')),
        ],
      ),
    );
    if (ok != true) return;
    await widget.repository.deleteOnboardingStep(step.id);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: 'delete',
        targetType: 'onboarding_step',
        targetLabel: step.title);
    if (mounted) _reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _edit(),
        icon: const Icon(Icons.add),
        label: const Text('Adım Ekle'),
      ),
      body: FutureBuilder<List<OnboardingStep>>(
        future: _future,
        builder: (context, snap) {
          if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) {
            return const Center(child: CircularProgressIndicator());
          }
          final items = snap.data!;
          return ListView(
            padding: const EdgeInsets.fromLTRB(20, 20, 20, 90),
            children: [
              const Text('İlk 30 Gün Adımları',
                  style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
              const SizedBox(height: 12),
              if (items.isEmpty)
                const Text('Henüz adım yok.',
                    style: TextStyle(color: ArucadColors.muted)),
              for (final s in items)
                Card(
                  child: ListTile(
                    leading: Icon(
                        s.active ? Icons.visibility : Icons.visibility_off,
                        color: s.active ? ArucadColors.success : ArucadColors.muted),
                    title: Text(s.title,
                        style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text('${s.group} · ${s.detail}',
                        maxLines: 2, overflow: TextOverflow.ellipsis),
                    isThreeLine: true,
                    trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                      IconButton(
                          icon: const Icon(Icons.edit_outlined),
                          onPressed: () => _edit(s)),
                      IconButton(
                          icon: const Icon(Icons.delete_outline),
                          onPressed: () => _delete(s)),
                    ]),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }
}
