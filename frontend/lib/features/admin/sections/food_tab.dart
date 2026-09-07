part of '../admin_panel_screen.dart';

// ------------------------------------------------------------- Yemek

class _FoodTab extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;
  const _FoodTab({required this.repository, required this.adminName});
  @override
  State<_FoodTab> createState() => _FoodTabState();
}

class _FoodTabState extends State<_FoodTab> {
  late Future<List<CampusFoodVenue>> _future;
  String _query = '';
  final Set<String> _selected = {};

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getFoodVenues();
  }

  void _reload() => setState(() { _future = widget.repository.getFoodVenues(); });

  Future<void> _deleteSelected() async {
    for (final id in _selected) {
      await widget.repository.deleteFoodVenue(id);
    }
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: 'delete',
        targetType: 'food_venue',
        targetLabel: '${_selected.length} yemek noktası (toplu)');
    setState(() => _selected.clear());
    _reload();
  }

  Future<void> _editVenue([CampusFoodVenue? existing]) async {
    final strings = AdminLocale.of(context);
    final nameC = TextEditingController(text: existing?.name);
    final hoursC = TextEditingController(text: existing?.hours);
    final fileUrlC = TextEditingController(text: existing?.menuFileUrl);

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(existing == null ? strings.t('admin_venue_new') : strings.t('admin_venue_edit')),
        content: _DialogShell(fields: [
          TextField(controller: nameC, decoration: InputDecoration(labelText: strings.t('admin_field_name'))),
          TextField(
              controller: hoursC,
              decoration: InputDecoration(
                  labelText: strings.t('admin_venue_hours'), hintText: strings.t('admin_venue_hours_hint'))),
          TextField(
              controller: fileUrlC,
              decoration: InputDecoration(
                  labelText: strings.t('admin_venue_menu_file'),
                  hintText: strings.t('admin_venue_menu_file_hint')),
              maxLines: 2),
          Align(
            alignment: Alignment.centerLeft,
            child: Text(
              strings.t('admin_venue_note'),
              style: const TextStyle(color: ArucadColors.muted, fontSize: 11.5),
            ),
          ),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(strings.t('admin_cancel'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(strings.t('admin_save'))),
        ],
      ),
    );
    if (saved != true || nameC.text.trim().isEmpty) return;
    await widget.repository.upsertFoodVenue(CampusFoodVenue(
      id: existing?.id ?? 'food-${slugify(nameC.text)}',
      name: nameC.text.trim(),
      hours: hoursC.text.trim().isEmpty ? null : hoursC.text.trim(),
      dailyMenus: existing?.dailyMenus ?? const [],
      menuFileUrl: fileUrlC.text.trim().isEmpty ? null : fileUrlC.text.trim(),
    ));
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: existing == null ? 'create' : 'update',
        targetType: 'food_venue',
        targetLabel: nameC.text.trim());
    _reload();
  }

  Future<void> _manageCalendar(CampusFoodVenue venue) async {
    final strings = AdminLocale.of(context);
    await Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => _FoodMenuCalendarScreen(
            repository: widget.repository, venue: venue, strings: strings)));
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    return Scaffold(
      floatingActionButton: FloatingActionButton(
          onPressed: () => _editVenue(), child: const Icon(Icons.add)),
      body: Column(children: [
        _AdminListToolbar(
          searchHint: strings.t('admin_search_food'),
          onQueryChanged: (v) => setState(() => _query = v),
          selectedCount: _selected.length,
          onCancelSelection: () => setState(_selected.clear),
          onDeleteSelected: _deleteSelected,
        ),
        Expanded(
          child: FutureBuilder<List<CampusFoodVenue>>(
            future: _future,
            builder: (context, snap) {
              if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
              final venues = snap.data!
                  .where((v) => v.name.toLowerCase().contains(_query.toLowerCase()))
                  .toList();
              return ListView.separated(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 90),
                itemCount: venues.length,
                separatorBuilder: (_, __) => const SizedBox(height: 8),
                itemBuilder: (context, i) {
                  final v = venues[i];
                  final isSelected = _selected.contains(v.id);
                  return Card(
                    child: ListTile(
                      leading: _selected.isEmpty
                          ? null
                          : Checkbox(
                              value: isSelected,
                              onChanged: (_) => setState(() =>
                                  isSelected ? _selected.remove(v.id) : _selected.add(v.id)),
                            ),
                      title: Text(v.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontWeight: FontWeight.w800)),
                      subtitle: Text(
                          v.dailyMenus.isEmpty
                              ? strings.t('admin_food_daily_menu_empty')
                              : '${v.dailyMenus.length} ${strings.t('admin_food_daily_menu_count')}',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis),
                      onTap: () =>
                          _selected.isEmpty ? _editVenue(v) : setState(() => isSelected
                              ? _selected.remove(v.id)
                              : _selected.add(v.id)),
                      onLongPress: () => setState(() => _selected.add(v.id)),
                      trailing: _selected.isNotEmpty
                          ? null
                          : Row(mainAxisSize: MainAxisSize.min, children: [
                              IconButton(
                                icon: const Icon(Icons.calendar_month_outlined),
                                tooltip: strings.t('admin_food_calendar_tooltip'),
                                onPressed: () => _manageCalendar(v),
                              ),
                              IconButton(
                                icon: const Icon(Icons.delete_outline),
                                onPressed: () async {
                                  await widget.repository.deleteFoodVenue(v.id);
                                  await AuditLogStore.logIfMock(widget.repository,
                                      actorName: widget.adminName,
                                      action: 'delete',
                                      targetType: 'food_venue',
                                      targetLabel: v.name);
                                  _reload();
                                },
                              ),
                            ]),
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

/// Per-venue daily menu editor — add/edit/delete a specific calendar day's
/// items/price/hours, so a student picking a date on the Garden's calendar
/// sees exactly what an admin actually entered for that day.
class _FoodMenuCalendarScreen extends StatefulWidget {
  final CampusRepository repository;
  final CampusFoodVenue venue;
  // Passed in explicitly rather than read via `AdminLocale.of(context)`:
  // this screen is reached via `Navigator.push`, which mounts it as a new
  // route outside the `AdminPanelScreen` subtree that `AdminLocale` wraps,
  // so there is no ancestor to look up here.
  final AdminStrings strings;
  const _FoodMenuCalendarScreen(
      {required this.repository, required this.venue, required this.strings});

  @override
  State<_FoodMenuCalendarScreen> createState() => _FoodMenuCalendarScreenState();
}

class _FoodMenuCalendarScreenState extends State<_FoodMenuCalendarScreen> {
  late List<DailyMenu> _menus;

  @override
  void initState() {
    super.initState();
    _menus = [...widget.venue.dailyMenus]..sort((a, b) => a.date.compareTo(b.date));
  }

  Future<void> _persistMenu(DailyMenu menu) async {
    await widget.repository.upsertFoodMenu(widget.venue.id, menu);
  }

  Future<void> _persistDelete(DailyMenu menu) async {
    await widget.repository.deleteFoodMenu(widget.venue.id, menu.date);
  }

  Future<void> _editDay([DailyMenu? existing]) async {
    final strings = widget.strings;
    DateTime date = existing?.date ?? DateTime.now();
    final itemsC = TextEditingController(text: existing?.items.join(', '));
    final priceC = TextEditingController(text: existing?.price);
    final hoursC = TextEditingController(text: existing?.hours);

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(strings.t('admin_day_title')),
          content: _DialogShell(fields: [
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.event_outlined),
              title: Text('${date.day}.${date.month}.${date.year}'),
              trailing: TextButton(
                onPressed: () async {
                  final picked = await showDatePicker(
                    context: ctx,
                    initialDate: date,
                    firstDate: DateTime.now().subtract(const Duration(days: 365)),
                    lastDate: DateTime.now().add(const Duration(days: 365)),
                  );
                  if (picked != null) setDialogState(() => date = picked);
                },
                child: Text(strings.t('admin_day_pick_date')),
              ),
            ),
            TextField(
                controller: itemsC,
                decoration: InputDecoration(
                    labelText: strings.t('admin_day_items'), hintText: strings.t('admin_day_items_hint')),
                maxLines: 2),
            TextField(
                controller: priceC,
                decoration: InputDecoration(
                    labelText: strings.t('admin_day_price'), hintText: strings.t('admin_day_price_hint'))),
            TextField(
                controller: hoursC,
                decoration: InputDecoration(
                    labelText: strings.t('admin_day_hours'), hintText: strings.t('admin_day_hours_hint'))),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(strings.t('admin_cancel'))),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(strings.t('admin_save'))),
          ],
        ),
      ),
    );
    if (saved != true) return;
    final items =
        itemsC.text.split(',').map((t) => t.trim()).where((t) => t.isNotEmpty).toList();
    final entry = DailyMenu(
      date: date,
      items: items,
      price: priceC.text.trim().isEmpty ? null : priceC.text.trim(),
      hours: hoursC.text.trim().isEmpty ? null : hoursC.text.trim(),
    );
    setState(() {
      _menus.removeWhere((m) =>
          m.date.year == entry.date.year &&
          m.date.month == entry.date.month &&
          m.date.day == entry.date.day);
      _menus.add(entry);
      _menus.sort((a, b) => a.date.compareTo(b.date));
    });
    await _persistMenu(entry);
  }

  Future<void> _deleteDay(DailyMenu menu) async {
    setState(() => _menus.remove(menu));
    await _persistDelete(menu);
  }

  @override
  Widget build(BuildContext context) {
    final strings = widget.strings;
    return Scaffold(
      appBar: AppBar(
          title: Text('${widget.venue.name} · ${strings.t('admin_day_calendar_title')}',
              maxLines: 1, overflow: TextOverflow.ellipsis)),
      floatingActionButton: FloatingActionButton(
          onPressed: () => _editDay(), child: const Icon(Icons.add)),
      body: _menus.isEmpty
          ? Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Text(strings.t('admin_day_empty'), textAlign: TextAlign.center),
              ),
            )
          : ListView.separated(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 90),
              itemCount: _menus.length,
              separatorBuilder: (_, __) => const SizedBox(height: 8),
              itemBuilder: (context, i) {
                final m = _menus[i];
                return Card(
                  child: ListTile(
                    title: Text('${m.date.day}.${m.date.month}.${m.date.year}',
                        style: const TextStyle(fontWeight: FontWeight.w800)),
                    subtitle: Text([
                      if (m.items.isNotEmpty) m.items.join(' · '),
                      if (m.price != null) m.price!,
                      if (m.hours != null) m.hours!,
                    ].join(' — '), maxLines: 2, overflow: TextOverflow.ellipsis),
                    onTap: () => _editDay(m),
                    trailing: IconButton(
                      icon: const Icon(Icons.delete_outline),
                      onPressed: () => _deleteDay(m),
                    ),
                  ),
                );
              },
            ),
    );
  }
}
