part of '../admin_panel_screen.dart';

class _AchievementsTab extends StatefulWidget {
  final CampusRepository repository;
  const _AchievementsTab({required this.repository});

  @override
  State<_AchievementsTab> createState() => _AchievementsTabState();
}

class _AchievementsTabState extends State<_AchievementsTab> {
  bool _loading = true;
  List<Achievement> _items = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final items = await widget.repository.getAdminAchievements();
    if (!mounted) return;
    setState(() {
      _items = items;
      _loading = false;
    });
  }

  Future<void> _edit([Achievement? existing]) async {
    final title = TextEditingController(text: existing?.title ?? '');
    final subtitle = TextEditingController(text: existing?.subtitle ?? '');
    final trigger = TextEditingController(text: existing?.triggerKind ?? '');
    final threshold =
        TextEditingController(text: '${existing?.threshold ?? 1}');
    var active = true;
    await showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(existing == null ? 'Achievement Ekle' : 'Achievement Düzenle'),
        content: SizedBox(
          width: 420,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(controller: title, decoration: const InputDecoration(labelText: 'Name')),
              TextField(controller: subtitle, decoration: const InputDecoration(labelText: 'Description')),
              TextField(controller: trigger, decoration: const InputDecoration(labelText: 'Kind')),
              TextField(controller: threshold, decoration: const InputDecoration(labelText: 'Target')),
              StatefulBuilder(builder: (_, setLocal) {
                return SwitchListTile(
                  value: active,
                  onChanged: (v) => setLocal(() => active = v),
                  title: const Text('Active'),
                );
              }),
            ],
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Vazgeç')),
          FilledButton(
            onPressed: () async {
              await widget.repository.upsertAchievementDefinition(
                Achievement(
                  id: existing?.id ?? '',
                  title: title.text.trim(),
                  subtitle: subtitle.text.trim(),
                  triggerKind: trigger.text.trim(),
                  threshold: int.tryParse(threshold.text.trim()) ?? 1,
                  unlocked: !active,
                ),
              );
              if (!ctx.mounted) return;
              Navigator.pop(ctx);
              await _load();
            },
            child: const Text('Kaydet'),
          )
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Align(
            alignment: Alignment.centerRight,
            child: FilledButton.icon(
              onPressed: () => _edit(),
              icon: const Icon(Icons.add),
              label: const Text('Create'),
            ),
          ),
          const SizedBox(height: 8),
          Card(
            child: Column(
              children: [
                for (final item in _items) ...[
                  ListTile(
                    title: Text(item.title),
                    subtitle: Text('${item.triggerKind} · target ${item.threshold}'),
                    trailing: IconButton(
                      onPressed: () => _edit(item),
                      icon: const Icon(Icons.edit_outlined),
                    ),
                  ),
                  if (_items.last != item) const Divider(height: 1),
                ]
              ],
            ),
          )
        ],
      ),
    );
  }
}

