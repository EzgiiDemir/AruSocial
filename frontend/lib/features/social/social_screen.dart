import 'dart:typed_data';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/content_moderation.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/image_moderation_service.dart';
import 'package:arucad_campus_prototype/core/services/photo_picker_service.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/social/compose_post_sheet.dart';
import 'package:arucad_campus_prototype/features/social/notifications_screen.dart';
import 'package:arucad_campus_prototype/features/social/post_detail_screen.dart';
import 'package:arucad_campus_prototype/features/social/social_profile_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

const _pageSize = kPageSize;
enum _FeedFilter { forYou, following, campus, courses, events }
const _storyColors = [
  ArucadColors.primary,
  ArucadColors.blue,
  ArucadColors.warning,
  ArucadColors.success,
  ArucadColors.ink,
];

class SocialScreen extends StatefulWidget {
  final CampusRepository repository;
  const SocialScreen({super.key, required this.repository});

  @override
  State<SocialScreen> createState() => _SocialScreenState();
}

class _SocialScreenState extends State<SocialScreen> {
  List<FeedPost> _posts = const [];
  List<CampusStory> _stories = const [];
  Set<String> _blocked = {};
  Set<String> _following = {};
  String? _myName;
  bool _loading = true;
  int _visibleCount = _pageSize;
  _FeedFilter _filter = _FeedFilter.forYou;
  Set<String> _saved = {};

  List<FeedPost> get _filteredPosts {
    final base = _posts.where((p) => p.official || !_blocked.contains(p.name));
    switch (_filter) {
      case _FeedFilter.forYou:
        return base.toList();
      case _FeedFilter.following:
        return base.where((p) => _following.contains(p.name)).toList();
      case _FeedFilter.campus:
        return base.where((p) => p.kind == FeedKind.announcement).toList();
      case _FeedFilter.courses:
        return base
            .where((p) => p.postType == PostCategory.ders || p.courseTag != null)
            .toList();
      case _FeedFilter.events:
        return base.where((p) => p.postType == PostCategory.etkinlik).toList();
    }
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final results = await Future.wait([
      widget.repository.getFeed(),
      widget.repository.getStories(),
      widget.repository.getBlocked(),
      widget.repository.getFollowing(),
      widget.repository.getMe(),
      widget.repository.getSavedPostIds(),
    ]);
    if (!mounted) return;
    setState(() {
      _posts = results[0] as List<FeedPost>;
      _stories = results[1] as List<CampusStory>;
      _blocked = results[2] as Set<String>;
      _following = results[3] as Set<String>;
      _myName = (results[4] as CampusUser).name;
      _saved = results[5] as Set<String>;
      _loading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);

    return Scaffold(
      backgroundColor: Colors.transparent,
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'social-home-compose-fab',
        onPressed: () => _compose(context),
        icon: const Icon(Icons.add),
        label: Text(strings.t('social_share')),
      ),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 640),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 18, 20, 0),
                child: Row(children: [
                  Expanded(
                    child: Text(strings.t('social_title'),
                        style: Theme.of(context)
                            .textTheme
                            .headlineSmall
                            ?.copyWith(fontWeight: FontWeight.w900)),
                  ),
                  IconButton(
                    tooltip: strings.t('social_notifications'),
                    icon: const Icon(Icons.favorite_border),
                    onPressed: () => Navigator.of(context).push(MaterialPageRoute(
                        builder: (_) => NotificationsScreen(repository: widget.repository))),
                  ),
                ]),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
                child: Text(strings.t('social_tagline'),
                    style: const TextStyle(color: ArucadColors.muted)),
              ),
              const SizedBox(height: 4),
              Expanded(child: _buildFeed(context)),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildFeed(BuildContext context) {
    final strings = AppLocale.of(context);
    final filtered = _filteredPosts;
    final pageCount = _visibleCount.clamp(0, filtered.length);
    final hasMore = pageCount < filtered.length;

    return RefreshIndicator(
      onRefresh: _load,
      child: NotificationListener<ScrollNotification>(
        onNotification: (notification) {
          if (!hasMore) return false;
          if (notification.metrics.pixels >=
              notification.metrics.maxScrollExtent - 300) {
            setState(() => _visibleCount += _pageSize);
          }
          return false;
        },
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 90),
          children: [
                  if (!_loading) _StoriesBar(
                    stories: _stories,
                    onAddStory: () => _addStory(context),
                    onOpenStory: (index) => _openStory(context, index),
                  ),
                  const SizedBox(height: 12),
                  if (!_loading)
                    SizedBox(
                      height: 34,
                      child: ListView(
                        scrollDirection: Axis.horizontal,
                        children: [
                          for (final entry in {
                            _FeedFilter.forYou: strings.t('social_filter_for_you'),
                            _FeedFilter.following: strings.t('social_filter_following'),
                            _FeedFilter.campus: strings.t('social_filter_campus'),
                            _FeedFilter.courses: strings.t('social_filter_courses'),
                            _FeedFilter.events: strings.t('social_filter_events'),
                          }.entries)
                            Padding(
                              padding: const EdgeInsets.only(right: 8),
                              child: SelectableChip(
                                label: entry.value,
                                selected: _filter == entry.key,
                                onSelected: (_) => setState(() {
                                  _filter = entry.key;
                                  _visibleCount = _pageSize;
                                }),
                              ),
                            ),
                        ],
                      ),
                    ),
                  const SizedBox(height: 8),
                  if (_loading)
                    const Padding(
                      padding: EdgeInsets.symmetric(vertical: 30),
                      child: Center(child: CircularProgressIndicator()),
                    )
                  else if (filtered.isEmpty)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 30),
                      child: Center(
                          child: Text(
                              _posts.isEmpty ? strings.t('social_empty') : strings.t('social_empty_category'),
                              style: const TextStyle(color: ArucadColors.muted))),
                    )
                  else ...[
                    for (int i = 0; i < pageCount; i++)
                      Padding(
                        padding: EdgeInsets.only(top: i == 0 ? 0 : 12),
                        child: _PostCard(
                          post: filtered[i],
                          saved: _saved.contains(filtered[i].id),
                          onLike: () => _like(filtered[i].id),
                          onComment: () => _openComments(context, filtered[i]),
                          onReport: () => _report(context, filtered[i]),
                          onSave: () => _toggleSave(filtered[i].id),
                          onOpen: () => _openDetail(context, filtered[i]),
                          onOpenProfile: () => _openProfile(context, filtered[i].name),
                          showPeerActions:
                              !filtered[i].official && filtered[i].name != _myName,
                          isFollowing: _following.contains(filtered[i].name),
                          onToggleFollow: () => _toggleFollow(filtered[i].name),
                          onToggleBlock: () => _toggleBlock(filtered[i].name),
                        ),
                      ),
                    if (hasMore)
                      Padding(
                        padding: const EdgeInsets.only(top: 14),
                        child: Center(
                          child: OutlinedButton.icon(
                            onPressed: () =>
                                setState(() => _visibleCount += _pageSize),
                            icon: const Icon(Icons.expand_more),
                            label: Text(
                                '${strings.t('social_load_more')} (${filtered.length - pageCount})'),
                          ),
                        ),
                      ),
                  ],
          ],
        ),
      ),
    );
  }

  Future<void> _like(String postId) async {
    // Optimistic-ish: flip locally first so the tap feels instant, then sync
    // with the repository's real state.
    setState(() {
      _posts = _posts.map((p) {
        if (p.id != postId) return p;
        final liked = !p.likedByMe;
        return p.copyWith(
            likedByMe: liked, likes: liked ? p.likes + 1 : p.likes - 1);
      }).toList();
    });
    await widget.repository.toggleLike(postId);
    if (!mounted) return;
    await _load();
  }

  Future<void> _toggleSave(String postId) async {
    final nowSaved = await widget.repository.toggleSavedPost(postId);
    if (!mounted) return;
    setState(() {
      if (nowSaved) {
        _saved.add(postId);
      } else {
        _saved.remove(postId);
      }
    });
  }

  Future<void> _openDetail(BuildContext context, FeedPost post) async {
    await Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => PostDetailScreen(post: post, repository: widget.repository)));
    if (!mounted) return;
    await _load();
  }

  void _openProfile(BuildContext context, String name) {
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => SocialProfileScreen(
            repository: widget.repository,
            viewedUserName: name == _myName ? null : name)));
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

  Future<void> _openStory(BuildContext context, int index) async {
    await Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => _StoryViewerScreen(stories: _stories, initialIndex: index),
        fullscreenDialog: true));
  }

  Future<void> _addStory(BuildContext context) async {
    final strings = AppLocale.of(context);
    final textController = TextEditingController();
    Uint8List? pickedBytes;
    Color backgroundColor = _storyColors.first;
    PostVisibility visibility = PostVisibility.everyone;

    final posted = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheetState) => Padding(
          padding: EdgeInsets.only(
              left: 20,
              right: 20,
              top: 20,
              bottom: MediaQuery.of(ctx).viewInsets.bottom + 20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(strings.t('social_new_story'),
                  style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 18)),
              const SizedBox(height: 14),
              if (pickedBytes != null)
                Stack(children: [
                  ClipRRect(
                    borderRadius: BorderRadius.circular(14),
                    child: AspectRatio(
                      aspectRatio: 9 / 12,
                      child: Image.memory(pickedBytes!, fit: BoxFit.cover),
                    ),
                  ),
                  Positioned(
                    right: 6,
                    top: 6,
                    child: IconButton.filled(
                      style: IconButton.styleFrom(
                          backgroundColor: Colors.black54, minimumSize: const Size(32, 32)),
                      onPressed: () => setSheetState(() => pickedBytes = null),
                      icon: const Icon(Icons.close, size: 16, color: Colors.white),
                    ),
                  ),
                ])
              else ...[
                OutlinedButton.icon(
                  onPressed: () async {
                    final bytes = await PhotoPickerService.pick(ctx);
                    if (bytes != null) setSheetState(() => pickedBytes = bytes);
                  },
                  icon: const Icon(Icons.add_a_photo_outlined),
                  label: Text(strings.t('social_add_photo')),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: textController,
                  maxLines: 2,
                  decoration: InputDecoration(hintText: strings.t('social_or_write_text')),
                ),
                const SizedBox(height: 10),
                Row(children: [
                  for (final color in _storyColors)
                    Padding(
                      padding: const EdgeInsets.only(right: 8),
                      child: GestureDetector(
                        onTap: () => setSheetState(() => backgroundColor = color),
                        child: CircleAvatar(
                          radius: 14,
                          backgroundColor: color,
                          child: backgroundColor == color
                              ? const Icon(Icons.check, size: 14, color: Colors.white)
                              : null,
                        ),
                      ),
                    ),
                ]),
              ],
              const SizedBox(height: 14),
              Text(strings.t('social_visibility'),
                  style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12)),
              const SizedBox(height: 6),
              Row(children: [
                SelectableChip(
                  label: strings.t('social_visibility_everyone'),
                  selected: visibility == PostVisibility.everyone,
                  onSelected: (_) =>
                      setSheetState(() => visibility = PostVisibility.everyone),
                ),
                const SizedBox(width: 8),
                SelectableChip(
                  label: strings.t('social_visibility_only_me'),
                  selected: visibility == PostVisibility.onlyMe,
                  onSelected: (_) =>
                      setSheetState(() => visibility = PostVisibility.onlyMe),
                ),
              ]),
              const SizedBox(height: 16),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: () => Navigator.of(ctx).pop(true),
                  child: Text(strings.t('social_share')),
                ),
              ),
            ],
          ),
        ),
      ),
    );
    if (posted != true) return;
    final text = textController.text.trim();
    if (text.isEmpty && pickedBytes == null) return;
    try {
      if (pickedBytes != null) {
        await ImageModerationService.assertImageAllowed(pickedBytes!, widget.repository);
      }
      await widget.repository.addStory(
        text: pickedBytes == null ? text : null,
        imageBytes: pickedBytes,
        backgroundColorValue: pickedBytes == null ? backgroundColor.toARGB32() : null,
        visibility: visibility,
      );
    } on ContentModerationException catch (e) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.reason)));
      return;
    }
    if (!mounted) return;
    await _load();
  }

  Future<void> _compose(BuildContext context) async {
    final created = await showComposePostSheet(context, widget.repository);
    if (!created || !mounted) return;
    await _load();
  }

  Future<void> _openComments(BuildContext context, FeedPost post) async {
    final strings = AppLocale.of(context);
    final commentController = TextEditingController();
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheetState) {
          var comments = post.comments;
          return Padding(
            padding: EdgeInsets.only(
                left: 20,
                right: 20,
                top: 20,
                bottom: MediaQuery.of(ctx).viewInsets.bottom + 20),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('${strings.t('social_comments_label')} (${comments.length})',
                    style: const TextStyle(
                        fontWeight: FontWeight.w900, fontSize: 16)),
                const SizedBox(height: 10),
                if (comments.isEmpty)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 10),
                    child: Text(strings.t('social_no_comments_yet'),
                        style: const TextStyle(color: ArucadColors.muted)),
                  )
                else
                  ConstrainedBox(
                    constraints: const BoxConstraints(maxHeight: 280),
                    child: ListView.separated(
                      shrinkWrap: true,
                      itemCount: comments.length,
                      separatorBuilder: (_, __) => const Divider(height: 18),
                      itemBuilder: (_, i) {
                        final c = comments[i];
                        return Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(c.author,
                                style:
                                    const TextStyle(fontWeight: FontWeight.w800)),
                            const SizedBox(height: 2),
                            Text(c.text),
                            const SizedBox(height: 2),
                            Text(c.meta,
                                style: const TextStyle(
                                    color: ArucadColors.muted, fontSize: 11)),
                          ],
                        );
                      },
                    ),
                  ),
                const SizedBox(height: 12),
                Row(children: [
                  Expanded(
                    child: TextField(
                      controller: commentController,
                      decoration:
                          InputDecoration(hintText: strings.t('social_write_comment')),
                    ),
                  ),
                  IconButton(
                    onPressed: () async {
                      final text = commentController.text.trim();
                      if (text.isEmpty) return;
                      try {
                        await widget.repository.addComment(post.id, text);
                      } on ContentModerationException catch (e) {
                        if (!ctx.mounted) return;
                        ScaffoldMessenger.of(ctx)
                            .showSnackBar(SnackBar(content: Text(e.reason)));
                        return;
                      }
                      commentController.clear();
                      final refreshed = await widget.repository.getFeed();
                      final updated = refreshed
                          .where((p) => p.id == post.id)
                          .map((p) => p.comments)
                          .toList();
                      if (updated.isNotEmpty) {
                        setSheetState(() => comments = updated.first);
                      }
                      if (mounted) setState(() => _posts = refreshed);
                    },
                    icon: const Icon(Icons.send, color: ArucadColors.primary),
                  ),
                ]),
              ],
            ),
          );
        },
      ),
    );
  }

  Future<void> _report(BuildContext context, FeedPost post) async {
    final strings = AppLocale.of(context);
    final reasons = [
      strings.t('social_report_reason_spam'),
      strings.t('social_report_reason_inappropriate'),
      strings.t('social_report_reason_harassment'),
      strings.t('social_report_reason_other'),
    ];
    String selected = reasons.first;
    final ok = await showDialog<bool?>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(strings.t('social_report_post_title')),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              for (final reason in reasons)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  dense: true,
                  leading: Icon(
                      reason == selected
                          ? Icons.radio_button_checked
                          : Icons.radio_button_off,
                      color: reason == selected ? ArucadColors.primary : null),
                  title: Text(reason),
                  onTap: () => setDialogState(() => selected = reason),
                ),
            ],
          ),
          actions: [
            TextButton(
                onPressed: () => Navigator.of(ctx).pop(false),
                child: Text(strings.t('social_cancel'))),
            FilledButton(
                onPressed: () => Navigator.of(ctx).pop(true),
                child: Text(strings.t('social_send'))),
          ],
        ),
      ),
    );
    if (ok != true) return;
    await widget.repository.reportPost(post.id, selected);
    if (!context.mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(strings.t('social_report_sent'))));
  }
}

class _StoriesBar extends StatelessWidget {
  final List<CampusStory> stories;
  final VoidCallback onAddStory;
  final ValueChanged<int> onOpenStory;

  const _StoriesBar(
      {required this.stories, required this.onAddStory, required this.onOpenStory});

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    return SizedBox(
      height: 92,
      child: ListView(
        scrollDirection: Axis.horizontal,
        children: [
          _StoryBubble(
            label: strings.t('social_new_story'),
            child: Container(
              decoration: const BoxDecoration(
                  color: ArucadColors.mist, shape: BoxShape.circle),
              child: const Icon(Icons.add, color: ArucadColors.primary),
            ),
            onTap: onAddStory,
          ),
          for (var i = 0; i < stories.length; i++)
            _StoryBubble(
              label: stories[i].authorName,
              ring: true,
              child: stories[i].imageBytes != null
                  ? ClipOval(
                      child: Image.memory(stories[i].imageBytes!, fit: BoxFit.cover))
                  : Container(
                      decoration: BoxDecoration(
                          color: Color(stories[i].backgroundColorValue ??
                              ArucadColors.primary.toARGB32()),
                          shape: BoxShape.circle),
                      child: const Icon(Icons.text_fields, color: Colors.white),
                    ),
              onTap: () => onOpenStory(i),
            ),
        ],
      ),
    );
  }
}

class _StoryBubble extends StatelessWidget {
  final String label;
  final Widget child;
  final VoidCallback onTap;
  final bool ring;

  const _StoryBubble(
      {required this.label, required this.child, required this.onTap, this.ring = false});

  @override
  Widget build(BuildContext context) => GestureDetector(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.only(right: 12),
          child: Column(children: [
            Container(
              width: 60,
              height: 60,
              padding: const EdgeInsets.all(2.5),
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: ring
                    ? const LinearGradient(colors: [
                        ArucadColors.primary,
                        ArucadColors.warning,
                      ])
                    : null,
                border: ring ? null : Border.all(color: ArucadColors.mist, width: 2),
              ),
              child: ClipOval(child: child),
            ),
            const SizedBox(height: 4),
            SizedBox(
              width: 64,
              child: Text(label,
                  textAlign: TextAlign.center,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 11)),
            ),
          ]),
        ),
      );
}

class _StoryViewerScreen extends StatefulWidget {
  final List<CampusStory> stories;
  final int initialIndex;
  const _StoryViewerScreen({required this.stories, required this.initialIndex});

  @override
  State<_StoryViewerScreen> createState() => _StoryViewerScreenState();
}

class _StoryViewerScreenState extends State<_StoryViewerScreen> {
  late final PageController _controller;
  late int _index;

  @override
  void initState() {
    super.initState();
    _index = widget.initialIndex;
    _controller = PageController(initialPage: _index);
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _next() {
    if (_index >= widget.stories.length - 1) {
      Navigator.of(context).pop();
    } else {
      _controller.nextPage(duration: const Duration(milliseconds: 250), curve: Curves.ease);
    }
  }

  void _prev() {
    if (_index == 0) return;
    _controller.previousPage(duration: const Duration(milliseconds: 250), curve: Curves.ease);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      body: Stack(children: [
        PageView.builder(
          controller: _controller,
          itemCount: widget.stories.length,
          onPageChanged: (i) => setState(() => _index = i),
          itemBuilder: (context, i) {
            final story = widget.stories[i];
            return GestureDetector(
              onTapUp: (details) {
                final half = MediaQuery.of(context).size.width / 2;
                if (details.globalPosition.dx < half) {
                  _prev();
                } else {
                  _next();
                }
              },
              child: story.imageBytes != null
                  ? Center(child: Image.memory(story.imageBytes!, fit: BoxFit.contain))
                  : Container(
                      color: Color(story.backgroundColorValue ?? ArucadColors.primary.toARGB32()),
                      alignment: Alignment.center,
                      padding: const EdgeInsets.all(32),
                      child: Text(story.text ?? '',
                          textAlign: TextAlign.center,
                          style: const TextStyle(
                              color: Colors.white, fontSize: 26, fontWeight: FontWeight.w800)),
                    ),
            );
          },
        ),
        Positioned(
          top: 50,
          left: 12,
          right: 12,
          child: Row(children: [
            for (var i = 0; i < widget.stories.length; i++)
              Expanded(
                child: Container(
                  margin: const EdgeInsets.symmetric(horizontal: 2),
                  height: 3,
                  decoration: BoxDecoration(
                    color: i <= _index ? Colors.white : Colors.white24,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
              ),
          ]),
        ),
        Positioned(
          top: 64,
          left: 16,
          child: Text(widget.stories[_index].authorName,
              style: const TextStyle(
                  color: Colors.white, fontWeight: FontWeight.w800, fontSize: 14)),
        ),
        Positioned(
          top: 56,
          right: 8,
          child: IconButton(
            onPressed: () => Navigator.of(context).pop(),
            icon: const Icon(Icons.close, color: Colors.white),
          ),
        ),
      ]),
    );
  }
}

class _PostCard extends StatelessWidget {
  final FeedPost post;
  final bool saved;
  final VoidCallback onLike;
  final VoidCallback onComment;
  final VoidCallback onReport;
  final VoidCallback onSave;
  final VoidCallback onOpen;
  final VoidCallback onOpenProfile;
  final bool showPeerActions;
  final bool isFollowing;
  final VoidCallback? onToggleFollow;
  final VoidCallback? onToggleBlock;

  const _PostCard({
    required this.post,
    required this.saved,
    required this.onLike,
    required this.onComment,
    required this.onReport,
    required this.onSave,
    required this.onOpen,
    required this.onOpenProfile,
    this.showPeerActions = false,
    this.isFollowing = false,
    this.onToggleFollow,
    this.onToggleBlock,
  });

  @override
  Widget build(BuildContext context) => Card(
        clipBehavior: Clip.antiAlias,
        shape: post.official
            ? RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(18),
                side: const BorderSide(color: ArucadColors.primary, width: 1))
            : null,
        child: InkWell(
          onTap: onOpen,
          child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              GestureDetector(
                onTap: post.official ? null : onOpenProfile,
                child: CircleAvatar(
                    radius: 18,
                    backgroundColor:
                        post.official ? ArucadColors.primary : ArucadColors.mist,
                    child: post.official
                        ? const Icon(Icons.school_outlined, size: 18, color: Colors.white)
                        : Text(post.name.isEmpty ? '?' : post.name.substring(0, 1))),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Row(children: [
                  Flexible(
                    child: GestureDetector(
                      onTap: post.official ? null : onOpenProfile,
                      child: Text(post.name,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontWeight: FontWeight.w900)),
                    ),
                  ),
                  if (post.postType != PostCategory.normal) ...[
                    const SizedBox(width: 6),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                      decoration: BoxDecoration(
                          color: categoryAccent(post.postType.name).withValues(alpha: .18),
                          borderRadius: BorderRadius.circular(999)),
                      child: Text(
                          '${post.postType.emoji} ${post.postType.label(AppLocale.of(context))}',
                          style: const TextStyle(fontSize: 10, fontWeight: FontWeight.w800)),
                    ),
                  ],
                  if (post.official) ...[
                    const SizedBox(width: 6),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                      decoration: BoxDecoration(
                          color: ArucadColors.primary.withValues(alpha: .12),
                          borderRadius: BorderRadius.circular(999)),
                      child: Text('RESMİ',
                          style: ArucadTextStyles.display(
                              fontSize: 10,
                              fontWeight: FontWeight.w900,
                              color: ArucadColors.primary)),
                    ),
                  ],
                  if (post.visibility == PostVisibility.onlyMe) ...[
                    const SizedBox(width: 6),
                    const Icon(Icons.lock_outline, size: 14, color: ArucadColors.muted),
                  ],
                ]),
              ),
              PopupMenuButton<String>(
                icon: const Icon(Icons.more_horiz),
                onSelected: (value) {
                  switch (value) {
                    case 'report':
                      onReport();
                    case 'follow':
                      onToggleFollow?.call();
                    case 'block':
                      onToggleBlock?.call();
                  }
                },
                itemBuilder: (ctx) {
                  final strings = AppLocale.of(ctx);
                  return [
                    if (showPeerActions) ...[
                      PopupMenuItem(
                          value: 'follow',
                          child: Text(isFollowing
                              ? strings.t('social_following')
                              : strings.t('social_follow'))),
                      PopupMenuItem(
                          value: 'block',
                          child: Text(strings.t('social_block'),
                              style: const TextStyle(color: ArucadColors.danger))),
                    ],
                    PopupMenuItem(value: 'report', child: Text(strings.t('social_report_post'))),
                  ];
                },
              ),
            ]),
            const SizedBox(height: 12),
            if (post.text.isNotEmpty)
              Text(post.text, style: const TextStyle(fontSize: 16)),
            if (post.imageBytes != null) ...[
              const SizedBox(height: 10),
              ClipRRect(
                borderRadius: BorderRadius.circular(14),
                child: AspectRatio(
                  aspectRatio: 16 / 10,
                  child: Image.memory(post.imageBytes!, fit: BoxFit.cover),
                ),
              ),
            ] else if (post.imageUrl != null) ...[
              const SizedBox(height: 10),
              ClipRRect(
                borderRadius: BorderRadius.circular(14),
                child: AspectRatio(
                  aspectRatio: 16 / 10,
                  child: Image.network(
                    post.imageUrl!,
                    fit: BoxFit.cover,
                    errorBuilder: (_, __, ___) => Container(
                      color: ArucadColors.mist,
                      child: const Center(
                          child: Icon(Icons.broken_image_outlined,
                              color: ArucadColors.muted)),
                    ),
                  ),
                ),
              ),
            ],
            if (post.locationTag != null || post.courseTag != null) ...[
              const SizedBox(height: 8),
              Wrap(spacing: 6, runSpacing: 6, children: [
                if (post.locationTag != null)
                  _MiniTagChip(icon: Icons.place_outlined, label: post.locationTag!),
                if (post.courseTag != null)
                  _MiniTagChip(icon: Icons.menu_book_outlined, label: post.courseTag!),
              ]),
            ],
            if (post.hashtags.isNotEmpty) ...[
              const SizedBox(height: 6),
              Wrap(spacing: 6, children: [
                for (final tag in post.hashtags)
                  Text('#$tag',
                      style: const TextStyle(
                          color: ArucadColors.primary, fontWeight: FontWeight.w700, fontSize: 12)),
              ]),
            ],
            const SizedBox(height: 8),
            Text(post.meta,
                style: const TextStyle(color: ArucadColors.muted, fontSize: 12)),
            const SizedBox(height: 10),
            Row(children: [
              InkWell(
                onTap: onLike,
                borderRadius: BorderRadius.circular(999),
                child: Row(children: [
                  Icon(
                      post.likedByMe
                          ? Icons.favorite
                          : Icons.favorite_border,
                      size: 18,
                      color: post.likedByMe ? ArucadColors.primary : null),
                  const SizedBox(width: 5),
                  Text('${post.likes}'),
                ]),
              ),
              const SizedBox(width: 18),
              InkWell(
                onTap: onComment,
                borderRadius: BorderRadius.circular(999),
                child: Row(children: [
                  const Icon(Icons.mode_comment_outlined, size: 18),
                  const SizedBox(width: 5),
                  Text('${post.comments.length}'),
                ]),
              ),
              const Spacer(),
              InkWell(
                onTap: onSave,
                borderRadius: BorderRadius.circular(999),
                child: Icon(saved ? Icons.bookmark : Icons.bookmark_border,
                    size: 20, color: saved ? ArucadColors.primary : null),
              ),
            ]),
          ]),
          ),
        ),
      );
}

class _MiniTagChip extends StatelessWidget {
  final IconData icon;
  final String label;
  const _MiniTagChip({required this.icon, required this.label});

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
        decoration: BoxDecoration(
            color: ArucadColors.mist, borderRadius: BorderRadius.circular(999)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, size: 12, color: ArucadColors.muted),
          const SizedBox(width: 4),
          Text(label, style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600)),
        ]),
      );
}
