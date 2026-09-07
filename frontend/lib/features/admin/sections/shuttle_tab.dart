part of '../admin_panel_screen.dart';

// --------------------------------------------------------------- Shuttle

/// Real backend + admin CRUD for shuttle lines — replaces the previously
/// fully hardcoded `shuttleRoutes` const in `shuttle_config.dart`, which no
/// admin could ever change without editing Dart code.
class _ShuttleTab extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;
  const _ShuttleTab({required this.repository, required this.adminName});
  @override
  State<_ShuttleTab> createState() => _ShuttleTabState();
}

class _ShuttleTabState extends State<_ShuttleTab> {
  late Future<List<ShuttleRoute>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getShuttleRoutes();
  }

  void _reload() =>
      setState(() => _future = widget.repository.getShuttleRoutes());

  Future<void> _edit([ShuttleRoute? existing]) async {
    final idC = TextEditingController(text: existing?.id);
    final nameC = TextEditingController(text: existing?.name);
    final stopsC =
        TextEditingController(text: existing?.stops.join('\n') ?? '');
    final departuresC =
        TextEditingController(text: existing?.departures.join(', ') ?? '');
    final returnsC =
        TextEditingController(text: existing?.returns?.join(', ') ?? '');
    var colorKey = ShuttleRoute.colorKeyIsKnown(existing?.colorKey)
        ? existing!.colorKey
        : 'blue';

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? 'Yeni servis hattı' : 'Hattı düzenle'),
          content: _DialogShell(fields: [
            TextField(
              controller: idC,
              enabled: existing == null,
              decoration: const InputDecoration(
                  labelText: 'Kimlik (id)', helperText: 'ör. nicosia'),
            ),
            TextField(
                controller: nameC,
                decoration: const InputDecoration(labelText: 'Ad')),
            DropdownButtonFormField<String>(
              initialValue: colorKey,
              decoration: const InputDecoration(labelText: 'Renk'),
              items: [
                for (final key in ShuttleRoute.knownColorKeys)
                  DropdownMenuItem(
                      value: key,
                      child: Row(mainAxisSize: MainAxisSize.min, children: [
                        Container(
                          width: 14,
                          height: 14,
                          decoration: BoxDecoration(
                              color: colorForShuttleKey(key),
                              shape: BoxShape.circle),
                        ),
                        const SizedBox(width: 8),
                        Text(key),
                      ])),
              ],
              onChanged: (v) =>
                  setDialogState(() => colorKey = v ?? colorKey),
            ),
            TextField(
              controller: stopsC,
              decoration: const InputDecoration(
                  labelText: 'Duraklar (her satıra bir durak)'),
              maxLines: 4,
            ),
            TextField(
              controller: departuresC,
              decoration: const InputDecoration(
                  labelText: 'Kalkış saatleri (virgülle, HH:mm)'),
            ),
            TextField(
              controller: returnsC,
              decoration: const InputDecoration(
                  labelText: 'Dönüş saatleri (opsiyonel, virgülle)'),
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
    final name = nameC.text.trim();
    final stops = stopsC.text
        .split('\n')
        .map((s) => s.trim())
        .where((s) => s.isNotEmpty)
        .toList();
    final departures = departuresC.text
        .split(',')
        .map((s) => s.trim())
        .where((s) => s.isNotEmpty)
        .toList();
    final returnsList = returnsC.text
        .split(',')
        .map((s) => s.trim())
        .where((s) => s.isNotEmpty)
        .toList();
    if (id.isEmpty || name.isEmpty || stops.isEmpty || departures.isEmpty) {
      return;
    }
    await widget.repository.upsertShuttleRoute(ShuttleRoute(
      id: id,
      name: name,
      colorKey: colorKey,
      stops: stops,
      departures: departures,
      returns: returnsList.isEmpty ? null : returnsList,
    ));
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: existing == null ? 'create' : 'update',
        targetType: 'shuttle_route',
        targetLabel: name);
    if (mounted) _reload();
  }

  Future<void> _delete(ShuttleRoute route) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Servis hattı silinsin mi?'),
        content: Text(route.name),
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
    await widget.repository.deleteShuttleRoute(route.id);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: 'delete',
        targetType: 'shuttle_route',
        targetLabel: route.name);
    if (mounted) _reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _edit(),
        icon: const Icon(Icons.add),
        label: const Text('Hat Ekle'),
      ),
      body: FutureBuilder<List<ShuttleRoute>>(
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
              const Text('Servis Hatları',
                  style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
              const SizedBox(height: 12),
              if (items.isEmpty)
                const Text('Henüz servis hattı yok.',
                    style: TextStyle(color: ArucadColors.muted)),
              for (final r in items)
                Card(
                  child: ListTile(
                    leading: Container(
                      width: 14,
                      height: 14,
                      margin: const EdgeInsets.only(top: 4),
                      decoration:
                          BoxDecoration(color: r.color, shape: BoxShape.circle),
                    ),
                    title: Text(r.name,
                        style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text(
                        '${r.stops.length} durak · Kalkış: ${r.departures.join(', ')}'
                        '${r.returns == null ? '' : ' · Dönüş: ${r.returns!.join(', ')}'}'),
                    isThreeLine: true,
                    trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                      IconButton(
                          icon: const Icon(Icons.edit_outlined),
                          onPressed: () => _edit(r)),
                      IconButton(
                          icon: const Icon(Icons.delete_outline),
                          onPressed: () => _delete(r)),
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
