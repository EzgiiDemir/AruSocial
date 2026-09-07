import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/inbox_notification.dart';
import 'package:arucad_campus_prototype/core/models/page_slice.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/profile/my_applications_screen.dart';
import 'package:arucad_campus_prototype/features/social/chat_screen.dart';
import 'package:arucad_campus_prototype/features/social/post_detail_screen.dart';
import 'package:arucad_campus_prototype/features/social/social_profile_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_back_button.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

class NotificationsScreen extends StatefulWidget {
  final CampusRepository repository;
  const NotificationsScreen({super.key, required this.repository});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  static const _apiPageSize = 20;

  List<InboxNotification> _inbox = const [];
  List<FollowRequestPeer> _requests = const [];
  List<FeedPost> _feed = const [];
  List<ActivityItem> _activity = const [];
  bool _loading = true;
  bool _loadingMore = false;
  bool _inboxHasMore = false;
  int _inboxPage = 0;
  String _query = '';
  int _visible = kPageSize;
  final _searchController = TextEditingController();

  @override
  void initState() {
    super.initState();
    widget.repository.markAllNotificationsRead();
    _refresh();
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  List<_NotificationItem> get _items {
    final q = _query.trim().toLowerCase();
    bool matches(String a, String b) =>
        q.isEmpty ||
        a.toLowerCase().contains(q) ||
        b.toLowerCase().contains(q);

    final items = <_NotificationItem>[
      for (final n in _inbox)
        if (matches(n.title, n.body))
          _NotificationItem(
            icon: _inboxIcon(n.kind),
            color: n.kind == 'follow_request'
                ? ArucadColors.yellow
                : ArucadColors.success,
            title: n.title,
            subtitle: n.body,
            meta: n.createdAt == null ? '' : _relativeMeta(n.createdAt!),
            read: n.read,
            kind: n.kind,
            actorName: n.actorName,
            actorAvatarUrl: n.actorAvatarUrl,
            onTap: () => _openInbox(n),
          ),
      for (final a in _activity)
        if (matches(a.title, a.subtitle))
          _NotificationItem(
            icon: _activityIcon(a.kind),
            color: ArucadColors.blue,
            title: a.title,
            subtitle: a.subtitle,
            meta: a.meta,
            kind: 'activity_${a.kind.name}',
            onTap: () => _openActivity(a),
          ),
    ];
    return items;
  }

  Future<void> _refresh() async {
    final initial = _inbox.isEmpty && _activity.isEmpty;
    if (initial) setState(() => _loading = true);
    try {
      final results = await Future.wait([
        widget.repository
            .getInboxNotificationsPage(page: 1, perPage: _apiPageSize),
        widget.repository.getFeed(),
        widget.repository.getMyActivity(),
        widget.repository.getFollowRequests(),
      ]);
      if (!mounted) return;
      final inboxPage = results[0] as PageSlice<InboxNotification>;
      setState(() {
        _inbox = inboxPage.items;
        _inboxPage = inboxPage.currentPage;
        _inboxHasMore = inboxPage.hasMore;
        _feed = results[1] as List<FeedPost>;
        _activity = results[2] as List<ActivityItem>;
        _requests = results[3] as List<FollowRequestPeer>;
        _loading = false;
        _loadingMore = false;
        _visible = kPageSize;
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
        'follow' || 'follow_request' => Icons.person_add_alt_1_outlined,
        'like' => Icons.favorite_outline,
        'comment' => Icons.mode_comment_outlined,
        'message' => Icons.chat_bubble_outline,
        'application_status' ||
        'application_received' ||
        'application_approved' ||
        'application_rejected' =>
          Icons.assignment_turned_in_outlined,
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

  Future<void> _openInbox(InboxNotification n) async {
    final dataActor = n.data?['actorName']?.toString();
    final actor = (dataActor != null && dataActor.isNotEmpty)
        ? dataActor
        : n.actorName;
    final postId = n.data?['postId']?.toString();
    if (postId != null && postId.isNotEmpty) {
      FeedPost? post;
      for (final p in _feed) {
        if (p.id == postId) {
          post = p;
          break;
        }
      }
      if (post != null) {
        await Navigator.of(context).push(MaterialPageRoute(
            builder: (_) =>
                PostDetailScreen(post: post!, repository: widget.repository)));
        return;
      }
    }
    if ((n.kind == 'follow' || n.kind == 'follow_request') &&
        actor != null &&
        actor.isNotEmpty) {
      await Navigator.of(context).push(MaterialPageRoute(
          builder: (_) => SocialProfileScreen(
              repository: widget.repository, viewedUserName: actor)));
      return;
    }
    if (n.kind == 'message' && actor != null) {
      await Navigator.of(context).push(MaterialPageRoute(
          builder: (_) => ChatThreadScreen(
              repository: widget.repository, peer: actor)));
      return;
    }
    if (n.kind == 'like' || n.kind == 'comment') {
      final post = _findPostForActor(actor);
      if (post != null) {
        await Navigator.of(context).push(MaterialPageRoute(
            builder: (_) =>
                PostDetailScreen(post: post, repository: widget.repository)));
        return;
      }
    }
    if (n.kind.contains('application')) {
      await Navigator.of(context).push(MaterialPageRoute(
          builder: (_) =>
              MyApplicationsScreen(repository: widget.repository)));
      return;
    }
    if (actor != null && actor.isNotEmpty) {
      await Navigator.of(context).push(MaterialPageRoute(
          builder: (_) => SocialProfileScreen(
              repository: widget.repository, viewedUserName: actor)));
    }
  }

  FeedPost? _findPostForActor(String? actor) {
    if (actor == null) return null;
    for (final p in _feed) {
      if (p.name == actor) return p;
    }
    return null;
  }

  Future<void> _openActivity(ActivityItem a) async {
    if (a.kind == ActivityKind.like || a.kind == ActivityKind.comment) {
      // Best effort: open first matching own post text
      for (final p in _feed) {
        if (a.title.toLowerCase().contains(p.text.toLowerCase().split(' ').first) ||
            p.text.toLowerCase().contains(a.subtitle.toLowerCase())) {
          await Navigator.of(context).push(MaterialPageRoute(
              builder: (_) =>
                  PostDetailScreen(post: p, repository: widget.repository)));
          return;
        }
      }
    }
    await Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => MyApplicationsScreen(repository: widget.repository)));
  }

  Future<void> _accept(FollowRequestPeer peer) async {
    await widget.repository.acceptFollowRequest(peer.name);
    if (!mounted) return;
    setState(() => _requests = _requests.where((r) => r.id != peer.id).toList());
  }

  Future<void> _decline(FollowRequestPeer peer) async {
    await widget.repository.declineFollowRequest(peer.name);
    if (!mounted) return;
    setState(() => _requests = _requests.where((r) => r.id != peer.id).toList());
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final items = _items;
    final shown = _visible.clamp(0, items.length);
    final page = items.take(shown).toList();

    return Scaffold(
      appBar:
          AppBar(title: Text(strings.t('notif_title')), leading: const CampusBackButton()),
      body: Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
          child: TextField(
            controller: _searchController,
            onChanged: (v) => setState(() {
              _query = v;
              _visible = kPageSize;
            }),
            decoration: InputDecoration(
              hintText: 'Bildirim ara',
              prefixIcon: const Icon(Icons.search_rounded),
              filled: true,
              fillColor: ArucadColors.paper,
              isDense: true,
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(14),
                borderSide: BorderSide.none,
              ),
              enabledBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(14),
                borderSide: BorderSide.none,
              ),
            ),
          ),
        ),
        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : RefreshIndicator(
                  onRefresh: _refresh,
                  child: ListView(
                    padding: const EdgeInsets.fromLTRB(0, 0, 0, 24),
                    children: [
                      if (_requests.isNotEmpty) ...[
                        const Padding(
                          padding: EdgeInsets.fromLTRB(16, 8, 16, 8),
                          child: Text('Takip istekleri',
                              style: TextStyle(
                                  fontWeight: FontWeight.w900, fontSize: 15)),
                        ),
                        for (final r in _requests)
                          ListTile(
                            leading: CircleAvatar(
                              backgroundColor: ArucadColors.mist,
                              backgroundImage:
                                  (r.avatarUrl != null && r.avatarUrl!.isNotEmpty)
                                      ? NetworkImage(r.avatarUrl!)
                                      : null,
                              child: (r.avatarUrl == null || r.avatarUrl!.isEmpty)
                                  ? Text(r.name.isEmpty
                                      ? '?'
                                      : r.name.substring(0, 1))
                                  : null,
                            ),
                            title: Text(r.name,
                                style: const TextStyle(
                                    fontWeight: FontWeight.w800)),
                            subtitle: const Text('Takip isteği gönderdi'),
                            trailing: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                TextButton(
                                    onPressed: () => _decline(r),
                                    child: const Text('Reddet')),
                                FilledButton(
                                    onPressed: () => _accept(r),
                                    child: const Text('Onayla')),
                              ],
                            ),
                            onTap: () => Navigator.of(context).push(
                                MaterialPageRoute(
                                    builder: (_) => SocialProfileScreen(
                                        repository: widget.repository,
                                        viewedUserName: r.name))),
                          ),
                        const Divider(height: 1),
                      ],
                      if (page.isEmpty)
                        Padding(
                          padding: const EdgeInsets.only(top: 80),
                          child: Center(
                              child: Text(strings.t('notif_empty'),
                                  style: const TextStyle(
                                      color: ArucadColors.muted))),
                        )
                      else
                        for (final item in page)
                          ListTile(
                            leading: CircleAvatar(
                                backgroundColor:
                                    item.color.withValues(alpha: .14),
                                backgroundImage: (item.actorAvatarUrl != null &&
                                        item.actorAvatarUrl!.isNotEmpty)
                                    ? NetworkImage(item.actorAvatarUrl!)
                                    : null,
                                child: (item.actorAvatarUrl == null ||
                                        item.actorAvatarUrl!.isEmpty)
                                    ? Icon(item.icon,
                                        color: item.color, size: 20)
                                    : null),
                            title: Text(item.title ?? '',
                                style: TextStyle(
                                    fontWeight: FontWeight.w700,
                                    fontSize: 13.5,
                                    color: item.read == false
                                        ? null
                                        : Theme.of(context)
                                            .colorScheme
                                            .onSurface)),
                            subtitle: Text(item.subtitle,
                                maxLines: 2,
                                overflow: TextOverflow.ellipsis),
                            trailing: Text(item.meta,
                                style: const TextStyle(
                                    color: ArucadColors.muted, fontSize: 11)),
                            onTap: item.onTap,
                          ),
                      LoadMoreButton(
                        shown: shown,
                        total: items.length,
                        itemLabel: 'bildirim',
                        onTap: () {
                          setState(() => _visible += kPageSize);
                          if (_inboxHasMore) _loadMoreInbox();
                        },
                      ),
                    ],
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
  final String subtitle;
  final String meta;
  final bool? read;
  final String kind;
  final String? actorName;
  final String? actorAvatarUrl;
  final VoidCallback? onTap;

  const _NotificationItem({
    required this.icon,
    required this.color,
    this.title,
    required this.subtitle,
    required this.meta,
    this.read,
    required this.kind,
    this.actorName,
    this.actorAvatarUrl,
    this.onTap,
  });
}
