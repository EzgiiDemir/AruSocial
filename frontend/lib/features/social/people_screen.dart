import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/chat_message.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/social/chat_screen.dart';
import 'package:arucad_campus_prototype/features/social/chat_group_screen.dart';
import 'package:arucad_campus_prototype/features/social/post_detail_screen.dart';
import 'package:arucad_campus_prototype/features/social/social_profile_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_avatar.dart';
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

enum _SearchFilter { all, people, courses, groups, hashtags }

class _PeopleScreenState extends State<PeopleScreen> {
  List<LeaderboardEntry> _people = const [];
  List<FeedPost> _posts = const [];
  List<ChatGroup> _groups = const [];
  Set<String> _following = {};
  Set<String> _blocked = {};
  bool _loading = true;
  String _query = '';
  int _visibleCount = kPageSize;
  _SearchFilter _filter = _SearchFilter.all;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final results = await Future.wait([
        widget.repository.getLeaderboard(),
        widget.repository.getFollowing(),
        widget.repository.getBlocked(),
        widget.repository.getFeed(),
        widget.repository.getChatGroups(),
      ]);
      if (!mounted) return;
      setState(() {
        _people = (results[0] as List<LeaderboardEntry>)
            .where((p) => !p.isMe)
            .toList();
        _following = results[1] as Set<String>;
        _blocked = results[2] as Set<String>;
        _posts = results[3] as List<FeedPost>;
        _groups = results[4] as List<ChatGroup>;
        _loading = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _loading = false);
    }
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
    final wasFollowing = _following.contains(name);
    setState(() {
      if (wasFollowing) {
        _following.remove(name);
      } else {
        _following.add(name);
      }
    });
    try {
      final nowFollowing = await widget.repository.toggleFollow(name);
      if (!mounted) return;
      setState(() {
        if (nowFollowing) {
          _following.add(name);
        } else {
          _following.remove(name);
        }
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        if (wasFollowing) {
          _following.add(name);
        } else {
          _following.remove(name);
        }
      });
    }
  }

  Future<void> _toggleBlock(String name) async {
    final wasBlocked = _blocked.contains(name);
    final wasFollowing = _following.contains(name);
    setState(() {
      if (wasBlocked) {
        _blocked.remove(name);
      } else {
        _blocked.add(name);
        _following.remove(name);
      }
    });
    try {
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
    } catch (_) {
      if (!mounted) return;
      setState(() {
        if (wasBlocked) {
          _blocked.add(name);
        } else {
          _blocked.remove(name);
        }
        if (wasFollowing) {
          _following.add(name);
        } else {
          _following.remove(name);
        }
      });
    }
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
    final coursePosts = <String, FeedPost>{};
    final hashtags = <String, FeedPost>{};
    for (final post in _posts) {
      final course = post.courseTag?.trim();
      if (course != null && course.isNotEmpty) {
        coursePosts.putIfAbsent(course, () => post);
      }
      for (final hashtag in post.hashtags) {
        hashtags.putIfAbsent(hashtag, () => post);
      }
    }
    bool textMatches(String value) =>
        q.isEmpty || value.toLowerCase().contains(q.replaceFirst('#', ''));
    final visibleCourses =
        coursePosts.entries.where((e) => textMatches(e.key)).toList();
    final visibleGroups = _groups.where((g) => textMatches(g.name)).toList();
    final visibleHashtags =
        hashtags.entries.where((e) => textMatches(e.key)).toList();
    final showPeople =
        _filter == _SearchFilter.all || _filter == _SearchFilter.people;
    final showCourses =
        _filter == _SearchFilter.all || _filter == _SearchFilter.courses;
    final showGroups =
        _filter == _SearchFilter.all || _filter == _SearchFilter.groups;
    final showHashtags = _filter == _SearchFilter.hashtags;
    final shown = _visibleCount.clamp(0, visible.length);
    final hasMorePeople = shown < visible.length;

    return Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 10, 16, 8),
        child: TextField(
          decoration: InputDecoration(
            hintText: strings.t('people_search_hint'),
            prefixIcon: const Icon(Icons.search),
            isDense: true,
            filled: true,
            fillColor: ArucadColors.paper,
            border: OutlineInputBorder(
              borderRadius: BorderRadius.circular(999),
              borderSide: const BorderSide(color: ArucadColors.border),
            ),
            enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(999),
              borderSide: const BorderSide(color: ArucadColors.border),
            ),
          ),
          onChanged: (v) => setState(() {
            _query = v;
            _visibleCount = kPageSize;
          }),
        ),
      ),
      SizedBox(
        height: 44,
        child: ListView(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 16),
          children: [
            for (final entry in const [
              (_SearchFilter.all, 'Tümü'),
              (_SearchFilter.people, 'Kişiler'),
              (_SearchFilter.courses, 'Dersler'),
              (_SearchFilter.groups, 'Gruplar'),
              (_SearchFilter.hashtags, '#Hashtag'),
            ])
              Padding(
                padding: const EdgeInsets.only(right: 7),
                child: _SearchCategoryChip(
                  label: entry.$2,
                  selected: _filter == entry.$1,
                  onTap: () => setState(() {
                    _filter = entry.$1;
                    _visibleCount = kPageSize;
                  }),
                ),
              ),
          ],
        ),
      ),
      Expanded(
        child: _loading
            ? const Center(child: CircularProgressIndicator())
            : (visible.isEmpty &&
                    matchingPosts.isEmpty &&
                    visibleCourses.isEmpty &&
                    visibleGroups.isEmpty &&
                    visibleHashtags.isEmpty)
                ? Center(
                    child: Text(strings.t('people_no_results'),
                        style: const TextStyle(color: ArucadColors.muted)))
                : ListView(
                    padding: const EdgeInsets.fromLTRB(16, 10, 16, 24),
                    children: [
                      if (showHashtags && visibleHashtags.isNotEmpty) ...[
                        const _SearchSectionTitle(title: 'Hashtagler'),
                        Wrap(
                          spacing: 8,
                          runSpacing: 8,
                          children: [
                            for (final entry in visibleHashtags)
                              ActionChip(
                                avatar: const Icon(Icons.tag_rounded,
                                    size: 17, color: ArucadColors.primary),
                                label: Text('#${entry.key}'),
                                onPressed: () => Navigator.of(context).push(
                                    MaterialPageRoute(
                                        builder: (_) => PostDetailScreen(
                                            post: entry.value,
                                            repository: widget.repository))),
                              ),
                          ],
                        ),
                        const SizedBox(height: 16),
                      ],
                      if (showCourses && visibleCourses.isNotEmpty) ...[
                        const _SearchSectionTitle(title: 'Dersler'),
                        for (final entry in visibleCourses)
                          _SearchResultCard(
                            icon: Icons.menu_book_rounded,
                            title: entry.key,
                            subtitle: entry.value.displayText,
                            accent: ArucadColors.red,
                            onTap: () => Navigator.of(context).push(
                                MaterialPageRoute(
                                    builder: (_) => PostDetailScreen(
                                        post: entry.value,
                                        repository: widget.repository))),
                          ),
                        const SizedBox(height: 10),
                      ],
                      if (showGroups && visibleGroups.isNotEmpty) ...[
                        const _SearchSectionTitle(title: 'Gruplar'),
                        for (final group in visibleGroups)
                          _SearchResultCard(
                            icon: Icons.groups_rounded,
                            title: group.name,
                            subtitle: '${group.members.length} üye',
                            accent: ArucadColors.campusGreen,
                            onTap: () => Navigator.of(context).push(
                                MaterialPageRoute(
                                    builder: (_) => ChatGroupScreen(
                                        repository: widget.repository,
                                        group: group))),
                          ),
                        const SizedBox(height: 10),
                      ],
                      if (_filter == _SearchFilter.all &&
                          matchingPosts.isNotEmpty) ...[
                        Padding(
                          padding: const EdgeInsets.only(bottom: 8, left: 4),
                          child: Text(strings.t('people_posts_section'),
                              style: const TextStyle(
                                  fontWeight: FontWeight.w900, fontSize: 13)),
                        ),
                        for (final post in matchingPosts.take(10))
                          Card(
                            margin: const EdgeInsets.only(bottom: 8),
                            surfaceTintColor: Colors.transparent,
                            shadowColor: Colors.transparent,
                            elevation: 0,
                            child: ListTile(
                              hoverColor: Colors.transparent,
                              mouseCursor: SystemMouseCursors.click,
                              leading: CircleAvatar(
                                  backgroundColor: ArucadColors.mist,
                                  child: Text(post.postType.emoji,
                                      style: const TextStyle(fontSize: 16))),
                              title: Text(
                                  '${post.name} · ${post.postType.label(strings)}',
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(
                                      fontWeight: FontWeight.w800,
                                      fontSize: 13)),
                              subtitle: Text(
                                  post.displayText.isEmpty
                                      ? (post.courseTag ??
                                          post.locationTag ??
                                          '')
                                      : post.displayText,
                                  maxLines: 2,
                                  overflow: TextOverflow.ellipsis),
                              onTap: () => Navigator.of(context).push(
                                  MaterialPageRoute(
                                      builder: (_) => PostDetailScreen(
                                          post: post,
                                          repository: widget.repository))),
                            ),
                          ),
                        const SizedBox(height: 8),
                      ],
                      if (showPeople && visible.isNotEmpty) ...[
                        if (matchingPosts.isNotEmpty)
                          Padding(
                            padding: const EdgeInsets.only(bottom: 8, left: 4),
                            child: Text(strings.t('people_popular_students'),
                                style: const TextStyle(
                                    fontWeight: FontWeight.w900, fontSize: 13)),
                          ),
                        for (int i = 0; i < shown; i++)
                          _PersonTile(
                            repository: widget.repository,
                            person: visible[i],
                            isFollowing: _following.contains(visible[i].name),
                            onToggleFollow: () =>
                                _toggleFollow(visible[i].name),
                            onLongPress: () => _showPeerMenu(visible[i].name),
                            onOpenProfile: () => Navigator.of(context).push(
                                MaterialPageRoute(
                                    builder: (_) => SocialProfileScreen(
                                        repository: widget.repository,
                                        viewedUserName: visible[i].name))),
                          ),
                        if (hasMorePeople)
                          LoadMoreButton(
                            shown: shown,
                            total: visible.length,
                            itemLabel: 'kişi',
                            onTap: () =>
                                setState(() => _visibleCount += kPageSize),
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
            title: Text(
                blocked
                    ? strings.t('people_unblock')
                    : strings.t('social_block'),
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

class _SearchCategoryChip extends StatelessWidget {
  final String label;
  final bool selected;
  final VoidCallback onTap;

  const _SearchCategoryChip({
    required this.label,
    required this.selected,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) => Material(
        color: selected ? ArucadColors.primary : ArucadColors.mist,
        borderRadius: BorderRadius.circular(999),
        child: InkWell(
          onTap: onTap,
          hoverColor: Colors.transparent,
          splashFactory: NoSplash.splashFactory,
          overlayColor: WidgetStateProperty.all(Colors.transparent),
          borderRadius: BorderRadius.circular(999),
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 38, minWidth: 62),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 14),
              child: Center(
                child: Text(label,
                    style: TextStyle(
                      color: selected ? Colors.white : ArucadColors.muted,
                      fontWeight: FontWeight.w700,
                      fontSize: 12,
                    )),
              ),
            ),
          ),
        ),
      );
}

class _SearchSectionTitle extends StatelessWidget {
  final String title;
  const _SearchSectionTitle({required this.title});

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(2, 4, 2, 9),
        child: Text(title,
            style: const TextStyle(
                color: ArucadColors.ink,
                fontSize: 17,
                fontWeight: FontWeight.w900)),
      );
}

class _SearchResultCard extends StatelessWidget {
  final IconData icon;
  final String title;
  final String subtitle;
  final Color accent;
  final VoidCallback onTap;

  const _SearchResultCard({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.accent,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) => Card(
        margin: const EdgeInsets.only(bottom: 8),
        elevation: 0,
        color: ArucadColors.paper,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(18),
          side: const BorderSide(color: ArucadColors.border),
        ),
        child: ListTile(
          hoverColor: Colors.transparent,
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
          leading: CircleAvatar(
            backgroundColor: accent.withValues(alpha: .12),
            child: Icon(icon, color: accent, size: 20),
          ),
          title:
              Text(title, style: const TextStyle(fontWeight: FontWeight.w800)),
          subtitle:
              Text(subtitle, maxLines: 1, overflow: TextOverflow.ellipsis),
          trailing: const Icon(Icons.chevron_right_rounded),
          onTap: onTap,
        ),
      );
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
        surfaceTintColor: Colors.transparent,
        shadowColor: Colors.transparent,
        elevation: 0,
        child: ListTile(
          hoverColor: Colors.transparent,
          mouseCursor: SystemMouseCursors.click,
          leading: CampusAvatar(name: person.name, avatarUrl: person.avatarUrl),
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
                  builder: (_) => ChatThreadScreen(
                      repository: repository, peer: person.name))),
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
