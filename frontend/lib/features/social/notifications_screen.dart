import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/inbox_notification.dart';
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
  late Future<List<_NotificationItem>> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
    // Real, backend-tracked read state — opening this screen is the one
    // real "the student has now seen these" signal available.
    widget.repository.markAllNotificationsRead();
  }

  Future<List<_NotificationItem>> _load() async {
    final results = await Future.wait([
      widget.repository.getFeed(),
      widget.repository.getMyActivity(),
      widget.repository.getInboxNotifications(),
    ]);
    final feed = results[0] as List<FeedPost>;
    final activity = results[1] as List<ActivityItem>;
    final inbox = results[2] as List<InboxNotification>;

    final items = <_NotificationItem>[
      for (final n in inbox)
        _NotificationItem(
          icon: _inboxIcon(n.kind),
          color: ArucadColors.success,
          title: n.title,
          subtitle: n.body,
          meta: n.createdAt == null ? '' : _relativeMeta(n.createdAt!),
        ),
      for (final post in feed.where((p) => p.official))
        _NotificationItem(
          icon: Icons.campaign_outlined,
          color: ArucadColors.primary,
          announcementAuthor: post.name,
          subtitle: post.text,
          meta: post.meta,
        ),
      for (final a in activity)
        _NotificationItem(
          icon: _activityIcon(a.kind),
          color: ArucadColors.blue,
          title: a.title,
          subtitle: a.subtitle,
          meta: a.meta,
        ),
    ];
    return items;
  }

  IconData _inboxIcon(String kind) => switch (kind) {
        'follow' => Icons.person_add_alt_1_outlined,
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
    return Scaffold(
      appBar: AppBar(title: Text(strings.t('notif_title'))),
      body: FutureBuilder<List<_NotificationItem>>(
        future: _future,
        builder: (context, snap) {
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
          final items = snap.data!;
          return Column(children: [
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
              child: items.isEmpty
                  ? Center(
                      child: Text(strings.t('notif_empty'),
                          style: const TextStyle(color: ArucadColors.muted)))
                  : ListView.separated(
                      padding: const EdgeInsets.symmetric(vertical: 8),
                      itemCount: items.length,
                      separatorBuilder: (_, __) => const Divider(height: 1),
                      itemBuilder: (context, i) {
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
                              style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5)),
                          subtitle: Text(item.subtitle,
                              maxLines: 2, overflow: TextOverflow.ellipsis),
                          trailing: Text(item.meta,
                              style: const TextStyle(color: ArucadColors.muted, fontSize: 11)),
                        );
                      },
                    ),
            ),
          ]);
        },
      ),
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
  const _NotificationItem({
    required this.icon,
    required this.color,
    this.title,
    this.announcementAuthor,
    required this.subtitle,
    required this.meta,
  });
}
