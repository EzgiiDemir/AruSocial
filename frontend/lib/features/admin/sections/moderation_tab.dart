part of '../admin_panel_screen.dart';

// ----------------------------------------------------------- Moderation

class _ModerationTab extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;
  const _ModerationTab({required this.repository, required this.adminName});
  @override
  State<_ModerationTab> createState() => _ModerationTabState();
}

class _ModerationTabState extends State<_ModerationTab> {
  late Future<List<ModerationReport>> _reportsFuture;
  late Future<PageSlice<MediaItem>> _queueFuture;
  ChatRealtimeService? _realtime;
  StreamSubscription<List<String>>? _campusChanges;

  @override
  void initState() {
    super.initState();
    _reload();
    unawaited(_startRealtime());
  }

  Future<void> _startRealtime() async {
    try {
      final me = await widget.repository.getMe();
      if (!mounted) return;
      final realtime = ChatRealtimeService.forRepository(widget.repository);
      _realtime = realtime;
      _campusChanges = realtime.campusChanged.listen((resources) {
        if (mounted && resources.contains('moderation')) _reload();
      });
      await realtime.start(userId: me.id, userName: me.name);
    } catch (_) {
      // The queue still refreshes after an explicit moderation action.
    }
  }

  @override
  void dispose() {
    unawaited(_campusChanges?.cancel() ?? Future.value());
    unawaited(_realtime?.dispose() ?? Future.value());
    super.dispose();
  }

  void _reload() => setState(() {
        _reportsFuture = widget.repository.getReports();
        _queueFuture = widget.repository.getModerationQueue();
      });

  Future<void> _act(ModerationReport report, ModerationAction action) async {
    await widget.repository.resolveReport(report.id, action);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: 'moderation',
        targetType: report.kind.name,
        targetLabel: '${report.targetLabel} → ${action.name}');
    _reload();
  }

  Future<void> _resolveQueue(MediaItem item, String action) async {
    await widget.repository.resolveModerationQueueItem(item.id, action: action);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: 'moderation',
        targetType: 'media',
        targetLabel: '${item.fileName} → $action');
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        const Text('Görsel inceleme kuyruğu',
            style: TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
        const SizedBox(height: 4),
        const Text(
          'Tüm görsel ve video yüklemeleri, yayınlanmadan önce burada insan incelemesine alınır.',
          style: TextStyle(color: ArucadColors.muted, fontSize: 12),
        ),
        const SizedBox(height: 10),
        FutureBuilder<PageSlice<MediaItem>>(
          future: _queueFuture,
          builder: (context, snap) {
            if (snap.hasError) return _AdminLoadError(error: snap.error!);
            if (!snap.hasData) {
              return const Padding(
                padding: EdgeInsets.all(24),
                child: Center(child: CircularProgressIndicator()),
              );
            }
            final items = snap.data!.items;
            if (items.isEmpty) {
              return const Padding(
                padding: EdgeInsets.symmetric(vertical: 12),
                child: Text('Bekleyen medya yok.',
                    style: TextStyle(color: ArucadColors.muted)),
              );
            }
            return Column(
              children: [
                for (final item in items)
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            ClipRRect(
                              borderRadius: BorderRadius.circular(8),
                              child: SizedBox(
                                width: 72,
                                height: 72,
                                child: item.isVideo
                                    ? const ColoredBox(
                                        color: ArucadColors.mist,
                                        child: Icon(Icons.videocam_outlined,
                                            color: ArucadColors.warning),
                                      )
                                    : mediaPreview(item.displaySrc,
                                        cacheWidth: 240),
                              ),
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(item.fileName,
                                        maxLines: 2,
                                        overflow: TextOverflow.ellipsis,
                                        style: const TextStyle(
                                            fontWeight: FontWeight.w700)),
                                    const SizedBox(height: 4),
                                    Text(
                                      '${item.mimeType.isEmpty ? 'media' : item.mimeType} · İnsan incelemesi bekliyor',
                                      style: const TextStyle(
                                          color: ArucadColors.muted,
                                          fontSize: 12),
                                    ),
                                    const SizedBox(height: 8),
                                    Wrap(spacing: 6, runSpacing: 6, children: [
                                      OutlinedButton(
                                        onPressed: () =>
                                            _resolveQueue(item, 'approved'),
                                        child: const Text('Onayla'),
                                      ),
                                      FilledButton(
                                        style: FilledButton.styleFrom(
                                            backgroundColor:
                                                ArucadColors.danger),
                                        onPressed: () =>
                                            _resolveQueue(item, 'rejected'),
                                        child: const Text('Reddet'),
                                      ),
                                    ]),
                                  ]),
                            ),
                          ]),
                    ),
                  ),
              ],
            );
          },
        ),
        const SizedBox(height: 24),
        const Text('Gönderi inceleme kuyruğu',
            style: TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
        const SizedBox(height: 4),
        const Text(
          'Öğrenci gönderileri üretimde onay bekler — etkinlik kuyruğu ile aynı mantık.',
          style: TextStyle(color: ArucadColors.muted, fontSize: 12),
        ),
        const SizedBox(height: 10),
        FutureBuilder<PageSlice<FeedPost>>(
          future: widget.repository.getPendingPosts(),
          builder: (context, snap) {
            if (snap.hasError) return _AdminLoadError(error: snap.error!);
            if (!snap.hasData) {
              return const Padding(
                padding: EdgeInsets.all(24),
                child: Center(child: CircularProgressIndicator()),
              );
            }
            final posts = snap.data!.items;
            if (posts.isEmpty) {
              return const Padding(
                padding: EdgeInsets.symmetric(vertical: 12),
                child: Text('Bekleyen gönderi yok.',
                    style: TextStyle(color: ArucadColors.muted)),
              );
            }
            return Column(
              children: [
                for (final post in posts)
                  Card(
                    child: ListTile(
                      title: Text(post.name,
                          style: const TextStyle(fontWeight: FontWeight.w700)),
                      subtitle: Text(post.text),
                      trailing: Wrap(spacing: 6, children: [
                        OutlinedButton(
                          onPressed: () async {
                            await widget.repository.approvePendingPost(post.id);
                            _reload();
                          },
                          child: const Text('Yayınla'),
                        ),
                        FilledButton(
                          style: FilledButton.styleFrom(
                              backgroundColor: ArucadColors.danger),
                          onPressed: () async {
                            await widget.repository.rejectPendingPost(post.id);
                            _reload();
                          },
                          child: const Text('Reddet'),
                        ),
                      ]),
                    ),
                  ),
              ],
            );
          },
        ),
        const SizedBox(height: 24),
        Text(strings.t('admin_nav_moderation'),
            style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
        const SizedBox(height: 10),
        FutureBuilder<List<ModerationReport>>(
          future: _reportsFuture,
          builder: (context, snap) {
            if (snap.hasError) return _AdminLoadError(error: snap.error!);
            if (!snap.hasData) {
              return const Center(child: CircularProgressIndicator());
            }
            final reports = snap.data!;
            if (reports.isEmpty) {
              return Center(
                  child: Text(strings.t('admin_no_pending_reports'),
                      style: const TextStyle(color: ArucadColors.muted)));
            }
            return Column(
              children: [
                for (final r in reports) ...[
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(children: [
                            Icon(
                                r.kind == ReportedKind.post
                                    ? Icons.dynamic_feed_outlined
                                    : Icons.place_outlined,
                                size: 18,
                                color: ArucadColors.muted),
                            const SizedBox(width: 6),
                            Expanded(
                                child: Text(r.targetLabel,
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: const TextStyle(
                                        fontWeight: FontWeight.w800))),
                            if (r.action != null)
                              Container(
                                padding: const EdgeInsets.symmetric(
                                    horizontal: 8, vertical: 3),
                                decoration: BoxDecoration(
                                    color: ArucadColors.success
                                        .withValues(alpha: .12),
                                    borderRadius: BorderRadius.circular(999)),
                                child: Text(_actionLabel(strings, r.action!),
                                    style: const TextStyle(
                                        fontSize: 11,
                                        color: ArucadColors.success)),
                              ),
                          ]),
                          const SizedBox(height: 6),
                          Text(
                              '${strings.t('admin_reason_prefix')}: ${r.reason}',
                              style:
                                  const TextStyle(color: ArucadColors.muted)),
                          if (r.action == null) ...[
                            const SizedBox(height: 10),
                            Wrap(spacing: 8, children: [
                              OutlinedButton(
                                  onPressed: () =>
                                      _act(r, ModerationAction.dismissed),
                                  child:
                                      Text(strings.t('admin_action_dismiss'))),
                              OutlinedButton(
                                  onPressed: () =>
                                      _act(r, ModerationAction.warned),
                                  child: Text(strings.t('admin_action_warn'))),
                              FilledButton(
                                  onPressed: () =>
                                      _act(r, ModerationAction.removed),
                                  style: FilledButton.styleFrom(
                                      backgroundColor: ArucadColors.danger),
                                  child:
                                      Text(strings.t('admin_content_remove'))),
                            ]),
                          ],
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 10),
                ],
              ],
            );
          },
        ),
      ],
    );
  }

  String _actionLabel(AdminStrings strings, ModerationAction action) =>
      switch (action) {
        ModerationAction.dismissed => strings.t('admin_action_dismissed'),
        ModerationAction.warned => strings.t('admin_action_warned'),
        ModerationAction.removed => strings.t('admin_action_removed'),
      };
}
