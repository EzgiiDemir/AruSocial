part of '../admin_panel_screen.dart';

// -------------------------------------------------------------- Email Log

class _EmailLogTab extends StatefulWidget {
  final CampusRepository repository;
  const _EmailLogTab({required this.repository});
  @override
  State<_EmailLogTab> createState() => _EmailLogTabState();
}

class _EmailLogTabState extends State<_EmailLogTab> {
  static const _apiPageSize = 20;
  List<EmailLogEntry> _logs = const [];
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
    final initial = _logs.isEmpty;
    if (initial) setState(() => _loading = true);
    try {
      final page = await widget.repository.getEmailLogsPage(page: 1, perPage: _apiPageSize);
      if (!mounted) return;
      setState(() {
        _logs = page.items;
        _page = page.currentPage;
        _hasMore = page.hasMore;
        _total = page.total;
        _loading = false;
        _loadingMore = false;
      });
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _loadMore() async {
    if (_loadingMore || !_hasMore || _loading) return;
    setState(() => _loadingMore = true);
    try {
      final page = await widget.repository.getEmailLogsPage(page: _page + 1, perPage: _apiPageSize);
      if (!mounted) return;
      final seen = _logs.map((e) => e.id).toSet();
      setState(() {
        _logs = [..._logs, ...page.items.where((e) => !seen.contains(e.id))];
        _page = page.currentPage;
        _hasMore = page.hasMore;
        _total = page.total;
        _loadingMore = false;
      });
    } catch (_) {
      if (mounted) setState(() => _loadingMore = false);
    }
  }

  Future<void> _sendBulk() async {
    final recipientsC = TextEditingController();
    final subjectC = TextEditingController();
    final bodyC = TextEditingController();
    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Toplu E-posta Gönder'),
        content: _DialogShell(fields: [
          TextField(
              controller: recipientsC,
              decoration: const InputDecoration(
                  labelText: 'Alıcılar (virgülle ayrılmış)',
                  hintText: 'ali@arucad.edu.tr, ayse@arucad.edu.tr'),
              maxLines: 2),
          TextField(controller: subjectC, decoration: const InputDecoration(labelText: 'Konu')),
          TextField(
              controller: bodyC, decoration: const InputDecoration(labelText: 'İçerik'), maxLines: 5),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Gönder')),
        ],
      ),
    );
    final recipients = recipientsC.text.split(',').map((e) => e.trim()).where((e) => e.isNotEmpty).toList();
    if (saved != true || recipients.isEmpty || subjectC.text.trim().isEmpty) return;
    final sent = await widget.repository.sendBulkEmail(
        recipients: recipients, subject: subjectC.text.trim(), body: bodyC.text.trim());
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('$sent / ${recipients.length} e-posta gönderildi.')));
    _refresh();
  }

  Future<void> _retry(EmailLogEntry log) async {
    await widget.repository.retryEmail(log.id);
    _refresh();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _sendBulk,
        icon: const Icon(Icons.send_outlined),
        label: const Text('Toplu E-posta'),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _logs.isEmpty
              ? const Center(
                  child: Padding(
                    padding: EdgeInsets.all(32),
                    child: Text('Henüz gönderilmiş e-posta yok.',
                        style: TextStyle(color: ArucadColors.muted)),
                  ),
                )
              : RefreshIndicator(
                  onRefresh: _refresh,
                  child: ListView.separated(
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 90),
                    itemCount: _logs.length + 1,
                    separatorBuilder: (_, __) => const SizedBox(height: 8),
                    itemBuilder: (context, i) {
                      if (i == _logs.length) {
                        return LoadMoreButton(
                          shown: _logs.length,
                          total: _total,
                          itemLabel: 'kayıt',
                          onTap: _hasMore && !_loadingMore ? _loadMore : () {},
                        );
                      }
                      final l = _logs[i];
                      final ok = l.status == 'sent';
                      return Card(
                        child: ListTile(
                          leading: Icon(ok ? Icons.check_circle_outline : Icons.error_outline,
                              color: ok ? ArucadColors.success : ArucadColors.danger),
                          title: Text(l.subject, maxLines: 1, overflow: TextOverflow.ellipsis),
                          subtitle: Text(
                              '${l.toEmail} · ${l.template}${l.error != null ? " · ${l.error}" : ""}',
                              maxLines: 2, overflow: TextOverflow.ellipsis),
                          trailing: ok
                              ? null
                              : IconButton(
                                  icon: const Icon(Icons.refresh, size: 20),
                                  onPressed: () => _retry(l),
                                ),
                        ),
                      );
                    },
                  ),
                ),
    );
  }
}

/// What a panel section shows when its data can't be loaded.
///
/// The common case is now a real one: the backend authorizes each admin
/// section separately (`EnsurePermission`), so a role that can open the
/// panel at all may still be refused a particular tab. Before this, every
/// section treated "no data yet" and "the server said no" identically and
/// spun forever, which reads as a broken app rather than as a boundary
