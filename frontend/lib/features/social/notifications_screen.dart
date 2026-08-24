import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/inbox_notification.dart';
import 'package:arucad_campus_prototype/core/models/page_slice.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// Real notifications built entirely from data that genuinely exists.
/// Official ARUCAD announcements landing in the feed and the student's own
/// logged activity are always included; in Rest mode, real backend-
/// delivered notifications (e.g. a genuine follow event — see
/// `SocialGraphController`) are merged in too. Mock mode never has any of
/// those, since there's no other real user in a single-device prototype to
/// have actually generated one.
class NotificationsScreen extends StatefulWidget {
  final CampusRepository repository;
  const NotificationsScreen({super.key, required this.repository});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  static const _apiPageSize = 20;

  List<InboxNotification> _inbox = const [];
  List<FeedPost> _official = const [];
  List<ActivityItem> _activity = const [];
  bool _loading = true;
  bool _loadingMore = false;
  bool _inboxHasMore = false;
  int _inboxPage = 0;

  @override
  void initState() {
    super.initState();
    // Real, backend-tracked read state — opening this screen is the one
    // real "the student has now seen these" signal available.
    widget.repository.markAllNotificationsRead();
    _refresh();
  }

  List<_NotificationItem> get _items => [
        for (final n in _inbox)
          _NotificationItem(
            icon: _inboxIcon(n.kind),
            color: ArucadColors.success,
            title: n.title,
            subtitle: n.body,
            meta: n.createdAt == null ? '' : _relativeMeta(n.createdAt!),
            read: n.read,
          ),
        for (final post in _official)
          _NotificationItem(
            icon: Icons.campaign_outlined,
            color: ArucadColors.primary,
            announcementAuthor: post.name,
            subtitle: post.text,
            meta: post.meta,
          ),
        for (final a in _activity)
          _NotificationItem(
            icon: _activityIcon(a.kind),
            color: ArucadColors.blue,
            title: a.title,
            subtitle: a.subtitle,
            meta: a.meta,
          ),
      ];

  Future<void> _refresh() async {
    final initial = _inbox.isEmpty && _official.isEmpty && _activity.isEmpty;
    if (initial) setState(() => _loading = true);
    try {
      final results = await Future.wait([
        widget.repository.getInboxNotificationsPage(page: 1, perPage: _apiPageSize),
        widget.repository.getFeed(),
        widget.repository.getMyActivity(),
      ]);
      if (!mounted) return;
      final inboxPage = results[0] as PageSlice<InboxNotification>;
      final feed = results[1] as List<FeedPost>;
      setState(() {
        _inbox = inboxPage.items;
        _inboxPage = inboxPage.currentPage;
        _inboxHasMore = inboxPage.hasMore;
        _official = feed.where((p) => p.official).toList();
        _activity = results[2] as List<ActivityItem>;
        _loading = false;
        _loadingMore = false;
      });
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _loadMoreInbox() async {
    if (_loadingMore || !_inboxHasMore || _loading) return;
    setState(() => _loadingMore = true);
    try {
      final page = await widget.repository.getInboxNotificationsPage(
          page: _inboxPage + 1, perPage: _apiPageSize);
      if (!mounted) return;
      final seen = _inbox.map((n) => n.id).toSet();
      setState(() {
        _inbox = [..._inbox, ...page.items.where((n) => !seen.contains(n.id))];
        _inboxPage = page.currentPage;
        _inboxHasMore = page.hasMore;
        _loadingMore = false;
      });
    } catch (_) {
      if (mounted) setState(() => _loadingMore = false);
    }
  }

  IconData _inboxIcon(String kind) => switch (kind) {
        'follow' => Icons.person_add_alt_1_outlined,
        'like' => Icons.favorite_outline,
        'comment' => Icons.mode_comment_outlined,
        'message' => Icons.chat_bubble_outline,
        _ => Icons.notifications_outlined,
      };

  String _relativeMeta(DateTime at) {
    final diff = DateTime.now().difference(at);
    if (diff.inMinutes < 1) return 'şimdi';
    if (diff.inHours < 1) return '${diff.inMinutes}dk';
    if (diff.inDays < 1) return '${diff.inHours}sa';
    return '${diff.inDays}g';
  }

  IconData _activityIcon(ActivityKind kind) => switch (kind) {
        ActivityKind.checkIn => Icons.verified_outlined,
        ActivityKind.eventJoin => Icons.event_available_outlined,
        ActivityKind.review => Icons.star_outline,
        ActivityKind.comment => Icons.mode_comment_outlined,
        ActivityKind.like => Icons.favorite_outline,
        ActivityKind.report => Icons.flag_outlined,
      };

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final items = _items;
    return Scaffold(
      appBar: AppBar(title: Text(strings.t('notif_title'))),
      body: Column(children: [
        Container(
          width: double.infinity,
          color: ArucadColors.mist,
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
          child: Text(
            strings.t('notif_scope_banner'),
            style: const TextStyle(color: ArucadColors.muted, fontSize: 11.5),
          ),
        ),
        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : RefreshIndicator(
                  onRefresh: _refresh,
                  child: items.isEmpty
                      ? ListView(children: [
                          const SizedBox(height: 120),
                          Center(
                              child: Text(strings.t('notif_empty'),
                                  style: const TextStyle(color: ArucadColors.muted))),
                        ])
                      : ListView.separated(
                          padding: const EdgeInsets.symmetric(vertical: 8),
                          itemCount: items.length + (_inboxHasMore ? 1 : 0),
                          separatorBuilder: (_, __) => const Divider(height: 1),
                          itemBuilder: (context, i) {
                            if (i == items.length) {
                              return Padding(
                                padding: const EdgeInsets.symmetric(vertical: 12),
                                child: Center(
                                  child: _loadingMore
                                      ? const SizedBox(
                                          width: 24,
                                          height: 24,
                                          child: CircularProgressIndicator(strokeWidth: 2))
                                      : TextButton(
                                          onPressed: _loadMoreInbox,
                                          child: Text(strings.t('social_load_more')),
                                        ),
                                ),
                              );
                            }
                            final item = items[i];
                            final title = item.announcementAuthor != null
                                ? strings
                                    .t('notif_new_announcement')
                                    .replaceAll('{name}', item.announcementAuthor!)
                                : item.title!;
                            return ListTile(
                              leading: CircleAvatar(
                                  backgroundColor: item.color.withValues(alpha: .14),
                                  child: Icon(item.icon, color: item.color, size: 20)),
                              title: Text(title,
                                  style: TextStyle(
                                      fontWeight: FontWeight.w700,
                                      fontSize: 13.5,
                                      color: item.read == false ? null : ArucadColors.ink)),
                              subtitle: Text(item.subtitle,
                                  maxLines: 2, overflow: TextOverflow.ellipsis),
                              trailing: Text(item.meta,
                                  style: const TextStyle(color: ArucadColors.muted, fontSize: 11)),
                            );
                          },
                        ),
                ),
        ),
      ]),
    );
  }
}

class _NotificationItem {
  final IconData icon;
  final Color color;
  final String? title;
  final String? announcementAuthor;
  final String subtitle;
  final String meta;
  final bool? read;
  const _NotificationItem({
    required this.icon,
    required this.color,
    this.title,
    this.announcementAuthor,
    required this.subtitle,
    required this.meta,
    this.read,
  });
}
