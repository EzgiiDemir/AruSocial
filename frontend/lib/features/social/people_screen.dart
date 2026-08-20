import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/social/chat_screen.dart';
import 'package:arucad_campus_prototype/features/social/post_detail_screen.dart';
import 'package:arucad_campus_prototype/features/social/social_profile_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

/// Keşfet — real ARUCAD classmates (the same roster the leaderboard uses)
/// with a genuine follow/block relationship — a real, shared backend row
/// in Rest mode, per-device only in Mock mode (see
/// [CampusRepository.getFollowing]'s doc comment).
class PeopleScreen extends StatefulWidget {
  final CampusRepository repository;
  const PeopleScreen({super.key, required this.repository});

  @override
  State<PeopleScreen> createState() => _PeopleScreenState();
}

class _PeopleScreenState extends State<PeopleScreen> {
  List<LeaderboardEntry> _people = const [];
  List<FeedPost> _posts = const [];
  Set<String> _following = {};
  Set<String> _blocked = {};
  bool _loading = true;
  String _query = '';
  int _visibleCount = kPageSize;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final results = await Future.wait([
      widget.repository.getLeaderboard(),
      widget.repository.getFollowing(),
      widget.repository.getBlocked(),
      widget.repository.getFeed(),
    ]);
    if (!mounted) return;
    setState(() {
      _people = (results[0] as List<LeaderboardEntry>).where((p) => !p.isMe).toList();
      _following = results[1] as Set<String>;
      _blocked = results[2] as Set<String>;
      _posts = results[3] as List<FeedPost>;
      _loading = false;
    });
  }

  List<FeedPost> get _matchingPosts {
    final q = _query.trim().toLowerCase();
    if (q.isEmpty) return const [];
    final tag = q.startsWith('#') ? q.substring(1) : q;
    return _posts.where((p) {
      if (!p.official && _blocked.contains(p.name)) return false;
      if (p.hashtags.any((h) => h.toLowerCase().contains(tag))) return true;
      if ((p.courseTag ?? '').toLowerCase().contains(q)) return true;
      if ((p.locationTag ?? '').toLowerCase().contains(q)) return true;
      return p.text.toLowerCase().contains(q);
    }).toList();
  }

  Future<void> _toggleFollow(String name) async {
    await widget.repository.toggleFollow(name);
    if (!mounted) return;
    setState(() {
      if (_following.contains(name)) {
        _following.remove(name);
      } else {
        _following.add(name);
      }
    });
  }

  Future<void> _toggleBlock(String name) async {
    final nowBlocked = await widget.repository.toggleBlock(name);
    if (!mounted) return;
    setState(() {
      if (nowBlocked) {
        _blocked.add(name);
        _following.remove(name);
      } else {
        _blocked.remove(name);
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final q = _query.trim().toLowerCase();
    final visible = _people.where((p) {
      if (_blocked.contains(p.name)) return false;
      if (q.isEmpty) return true;
      return p.name.toLowerCase().contains(q) ||
          (p.department ?? '').toLowerCase().contains(q);
    }).toList();
    final matchingPosts = _matchingPosts;
    final shown = _visibleCount.clamp(0, visible.length);
    final hasMorePeople = shown < visible.length;

    return Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 4),
        child: TextField(
          decoration: InputDecoration(
            hintText: strings.t('people_search_hint'),
            prefixIcon: const Icon(Icons.search),
            isDense: true,
          ),
          onChanged: (v) => setState(() {
            _query = v;
            _visibleCount = kPageSize;
          }),
        ),
      ),
      Expanded(
        child: _loading
            ? const Center(child: CircularProgressIndicator())
            : (visible.isEmpty && matchingPosts.isEmpty)
                ? Center(
                    child: Text(strings.t('people_no_results'),
                        style: const TextStyle(color: ArucadColors.muted)))
                : ListView(
                    padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
                    children: [
                      if (matchingPosts.isNotEmpty) ...[
                        Padding(
                          padding: const EdgeInsets.only(bottom: 8, left: 4),
                          child: Text(strings.t('people_posts_section'),
                              style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 13)),
                        ),
                        for (final post in matchingPosts.take(10))
                          Card(
                            margin: const EdgeInsets.only(bottom: 8),
                            child: ListTile(
                              leading: CircleAvatar(
                                  backgroundColor: ArucadColors.mist,
                                  child: Text(post.postType.emoji,
                                      style: const TextStyle(fontSize: 16))),
                              title: Text(
                                  '${post.name} · ${post.postType.label(strings)}',
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13)),
                              subtitle: Text(post.text.isEmpty ? (post.courseTag ?? post.locationTag ?? '') : post.text,
                                  maxLines: 2, overflow: TextOverflow.ellipsis),
                              onTap: () => Navigator.of(context).push(MaterialPageRoute(
                                  builder: (_) => PostDetailScreen(
                                      post: post, repository: widget.repository))),
                            ),
                          ),
                        const SizedBox(height: 8),
                      ],
                      if (visible.isNotEmpty) ...[
                        if (matchingPosts.isNotEmpty)
                          Padding(
                            padding: const EdgeInsets.only(bottom: 8, left: 4),
                            child: Text(strings.t('people_popular_students'),
                                style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 13)),
                          ),
                        for (int i = 0; i < shown; i++)
                          _PersonTile(
                            repository: widget.repository,
                            person: visible[i],
                            isFollowing: _following.contains(visible[i].name),
                            onToggleFollow: () => _toggleFollow(visible[i].name),
                            onLongPress: () => _showPeerMenu(visible[i].name),
                            onOpenProfile: () => Navigator.of(context).push(MaterialPageRoute(
                                builder: (_) => SocialProfileScreen(
                                    repository: widget.repository,
                                    viewedUserName: visible[i].name))),
                          ),
                        if (hasMorePeople)
                          LoadMoreButton(
                            shown: shown,
                            total: visible.length,
                            itemLabel: 'kişi',
                            onTap: () => setState(() => _visibleCount += kPageSize),
                          ),
                      ],
                    ],
                  ),
      ),
    ]);
  }

  Future<void> _showPeerMenu(String name) async {
    final strings = AppLocale.of(context);
    final blocked = _blocked.contains(name);
    await showModalBottomSheet<void>(
      context: context,
      builder: (ctx) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          ListTile(
            leading: Icon(blocked ? Icons.block_flipped : Icons.block,
                color: ArucadColors.danger),
            title: Text(blocked ? strings.t('people_unblock') : strings.t('social_block'),
                style: const TextStyle(color: ArucadColors.danger)),
            onTap: () {
              Navigator.pop(ctx);
              _toggleBlock(name);
            },
          ),
        ]),
      ),
    );
  }
}

class _PersonTile extends StatelessWidget {
  final CampusRepository repository;
  final LeaderboardEntry person;
  final bool isFollowing;
  final VoidCallback onToggleFollow;
  final VoidCallback onLongPress;
  final VoidCallback onOpenProfile;

  const _PersonTile({
    required this.repository,
    required this.person,
    required this.isFollowing,
    required this.onToggleFollow,
    required this.onLongPress,
    required this.onOpenProfile,
  });

  @override
  Widget build(BuildContext context) => Card(
        margin: const EdgeInsets.only(bottom: 8),
        child: ListTile(
          leading: CircleAvatar(
              backgroundColor: ArucadColors.mist,
              child: Text(person.name.substring(0, 1))),
          title: Text(person.name,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontWeight: FontWeight.w800)),
          subtitle: Text(
              person.department != null
                  ? '${person.department} · ${person.xp} XP'
                  : '${person.xp} XP',
              maxLines: 1,
              overflow: TextOverflow.ellipsis),
          trailing: Row(mainAxisSize: MainAxisSize.min, children: [
            IconButton(
              tooltip: AppLocale.of(context).t('people_send_message'),
              icon: const Icon(Icons.chat_bubble_outline, size: 20),
              visualDensity: VisualDensity.compact,
              padding: EdgeInsets.zero,
              constraints: const BoxConstraints(minWidth: 36, minHeight: 36),
              onPressed: () => Navigator.of(context).push(MaterialPageRoute(
                  builder: (_) => ChatThreadScreen(repository: repository, peer: person.name))),
            ),
            const SizedBox(width: 4),
            OutlinedButton(
              onPressed: onToggleFollow,
              style: OutlinedButton.styleFrom(
                  minimumSize: const Size(0, 34),
                  padding: const EdgeInsets.symmetric(horizontal: 10),
                  visualDensity: VisualDensity.compact,
                  backgroundColor: isFollowing ? ArucadColors.mist : null),
              child: Text(
                  isFollowing
                      ? AppLocale.of(context).t('social_following')
                      : AppLocale.of(context).t('social_follow'),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 12.5)),
            ),
          ]),
          onTap: onOpenProfile,
          onLongPress: onLongPress,
        ),
      );
}
