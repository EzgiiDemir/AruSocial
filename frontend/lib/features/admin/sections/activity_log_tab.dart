part of '../admin_panel_screen.dart';

// -------------------------------------------------------- Activity Log

class _ActivityLogTab extends StatefulWidget {
  final CampusRepository repository;
  const _ActivityLogTab({required this.repository});
  @override
  State<_ActivityLogTab> createState() => _ActivityLogTabState();
}

class _ActivityLogTabState extends State<_ActivityLogTab> {
  static const _apiPageSize = 20;
  List<AuditLogEntry> _entries = const [];
  bool _loading = true;
  bool _loadingMore = false;
  bool _hasMore = false;
  int _page = 0;
  int _total = 0;

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  Future<void> _refresh() async {
    final initial = _entries.isEmpty;
    if (initial) setState(() => _loading = true);
    try {
      final page = await widget.repository.getAuditLogPage(page: 1, perPage: _apiPageSize);
      if (!mounted) return;
      setState(() {
        _entries = page.items;
        _page = page.currentPage;
        _hasMore = page.hasMore;
        _total = page.total;
        _loading = false;
        _loadingMore = false;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() => _loading = false);
    }
  }

  Future<void> _loadMore() async {
    if (_loadingMore || !_hasMore || _loading) return;
    setState(() => _loadingMore = true);
    try {
      final page = await widget.repository.getAuditLogPage(page: _page + 1, perPage: _apiPageSize);
      if (!mounted) return;
      final seen = _entries.map((e) => e.id).toSet();
      setState(() {
        _entries = [..._entries, ...page.items.where((e) => !seen.contains(e.id))];
        _page = page.currentPage;
        _hasMore = page.hasMore;
        _total = page.total;
        _loadingMore = false;
      });
    } catch (_) {
      if (mounted) setState(() => _loadingMore = false);
    }
  }

  (IconData, Color) _visual(String action) => switch (action) {
        'create' => (Icons.add_circle_outline, ArucadColors.success),
        'update' => (Icons.edit_outlined, ArucadColors.blue),
        'delete' => (Icons.delete_outline, ArucadColors.danger),
        'login' => (Icons.login, ArucadColors.muted),
        'logout' => (Icons.logout, ArucadColors.muted),
        'role_change' => (Icons.admin_panel_settings_outlined, ArucadColors.warning),
        'moderation' => (Icons.flag_outlined, ArucadColors.warning),
        _ => (Icons.circle_outlined, ArucadColors.muted),
      };

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_entries.isEmpty) {
      return Center(
        child: Text(strings.t('admin_no_activity_yet'),
            style: const TextStyle(color: ArucadColors.muted)),
      );
    }
    return RefreshIndicator(
      onRefresh: _refresh,
      child: ListView.separated(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
        itemCount: _entries.length + 1,
        separatorBuilder: (_, __) => const SizedBox(height: 8),
        itemBuilder: (context, i) {
          if (i == _entries.length) {
            return LoadMoreButton(
              shown: _entries.length,
              total: _total,
              itemLabel: strings.t('admin_record_noun'),
              onTap: _hasMore && !_loadingMore ? _loadMore : () {},
            );
          }
          final e = _entries[i];
          final (icon, color) = _visual(e.action);
          return Card(
            child: ListTile(
              dense: true,
              leading: CircleAvatar(
                  radius: 16,
                  backgroundColor: color.withValues(alpha: .14),
                  child: Icon(icon, size: 16, color: color)),
              title: Text('${e.actorName} · ${e.action}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
              subtitle: Text(
                  e.targetLabel.isEmpty ? e.targetType : '${e.targetType}: ${e.targetLabel}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 12)),
              trailing: Text(
                  '${e.at.day}.${e.at.month} ${e.at.hour.toString().padLeft(2, '0')}:${e.at.minute.toString().padLeft(2, '0')}',
                  style: const TextStyle(color: ArucadColors.muted, fontSize: 11)),
            ),
          );
        },
      ),
    );
  }
}
