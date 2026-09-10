import 'dart:async';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/config/place_tour.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/chat_message.dart';
import 'package:arucad_campus_prototype/core/models/page_slice.dart';
import 'package:arucad_campus_prototype/core/services/content_moderation.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/image_moderation_service.dart';
import 'package:arucad_campus_prototype/core/services/chat_realtime_service.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/core/utils/relative_time.dart';
import 'package:arucad_campus_prototype/core/models/report_reason.dart';
import 'package:arucad_campus_prototype/features/widgets/report_sheet.dart';
import 'package:arucad_campus_prototype/features/place/place_detail_screen.dart';
import 'package:arucad_campus_prototype/features/social/compose_post_sheet.dart';
import 'package:arucad_campus_prototype/features/social/compose_story_sheet.dart';
import 'package:arucad_campus_prototype/features/social/notifications_screen.dart';
import 'package:arucad_campus_prototype/features/social/post_detail_screen.dart';
import 'package:arucad_campus_prototype/features/social/social_profile_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_network_image.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_avatar.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';
import 'package:arucad_campus_prototype/features/widgets/feed_post_media.dart';

const _apiPageSize = 20;
const _socialBlue = ArucadColors.primary;
const _socialBackground = ArucadColors.canvas;
const _socialBorder = ArucadColors.border;
const _socialSecondary = ArucadColors.muted;

enum _FeedFilter { forYou, following, official, campus, courses, events }

class SocialScreen extends StatefulWidget {
  final CampusRepository repository;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;

  /// When true, page title is shown by [SocialShell] next to the ☰ button.
  final bool titleInShell;

  const SocialScreen({
    super.key,
    required this.repository,
    required this.mapProvider,
    required this.analyticsTracker,
    this.titleInShell = false,
  });

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
  bool _loadingMore = false;
  bool _feedHasMore = true;
  int _feedPage = 0;
  _FeedFilter _filter = _FeedFilter.forYou;
  final _searchController = TextEditingController();
  String _query = '';
  Set<String> _saved = {};
  Set<String> _viewedStories = {};
  String? _loadError;
  ChatRealtimeService? _realtime;
  StreamSubscription<List<String>>? _campusChanges;
  bool _refreshingFromRealtime = false;

  List<FeedPost> get _filteredPosts {
    final base = _posts.where((p) {
      if (!p.official && _blocked.contains(p.name)) return false;
      final query = _query.trim().toLowerCase();
      if (query.isEmpty) return true;
      return p.name.toLowerCase().contains(query) ||
          p.text.toLowerCase().contains(query) ||
          (p.courseTag?.toLowerCase().contains(query) ?? false) ||
          (p.locationTag?.toLowerCase().contains(query) ?? false);
    });
    final List<FeedPost> list;
    switch (_filter) {
      case _FeedFilter.forYou:
        list = base.toList();
      case _FeedFilter.following:
        list = base
            .where((p) =>
                p.official ||
                p.isPinned ||
                p.name == _myName ||
                _following.contains(p.name))
            .toList();
      case _FeedFilter.official:
        list = base.where((p) => p.official).toList();
      case _FeedFilter.campus:
        list = base
            .where((p) => p.official || p.kind == FeedKind.announcement)
            .toList();
      case _FeedFilter.courses:
        list = base
            .where(
                (p) => p.postType == PostCategory.ders || p.courseTag != null)
            .toList();
      case _FeedFilter.events:
        list = base.where((p) => p.postType == PostCategory.etkinlik).toList();
    }
    list.sort((a, b) {
      if (a.isPinned != b.isPinned) return a.isPinned ? -1 : 1;
      return 0;
    });
    return list;
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final results = await Future.wait([
        widget.repository.getFeedPage(page: 1, perPage: _apiPageSize),
        widget.repository.getStories(),
        widget.repository.getBlocked(),
        widget.repository.getFollowing(),
        widget.repository.getMe(),
        widget.repository.getSavedPostIds(),
      ]);
      if (!mounted) return;
      final page = results[0] as PageSlice<FeedPost>;
      final me = results[4] as CampusUser;
      final stories = results[1] as List<CampusStory>;
      setState(() {
        _posts = page.items;
        _feedPage = page.currentPage;
        _feedHasMore = page.hasMore;
        _stories = stories;
        _blocked = results[2] as Set<String>;
        _following = results[3] as Set<String>;
        _myName = me.name;
        _saved = results[5] as Set<String>;
        // Real per-account seen state from the server (`story.viewedByMe`),
        // not a device-local cache — this now matches across installs.
        _viewedStories = {
          for (final s in stories)
            if (s.viewedByMe) s.id
        };
        _loading = false;
        _loadingMore = false;
        _loadError = null;
      });
      unawaited(_startRealtime(me));
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _loadingMore = false;
        _loadError = e is ApiClientException
            ? e.displayMessage
            : 'Sosyal akış yüklenemedi.';
      });
    }
  }

  Future<void> _startRealtime(CampusUser me) async {
    if (_realtime != null) return;
    final realtime = ChatRealtimeService.forRepository(widget.repository);
    _realtime = realtime;
    _campusChanges = realtime.campusChanged.listen((resources) {
      if (resources.contains('feed')) unawaited(_refreshFeedFromRealtime());
    });
    await realtime.start(userId: me.id, userName: me.name);
  }

  Future<void> _refreshFeedFromRealtime() async {
    if (_refreshingFromRealtime || _loading) return;
    _refreshingFromRealtime = true;
    try {
      final page =
          await widget.repository.getFeedPage(page: 1, perPage: _apiPageSize);
      if (mounted) {
        setState(() {
          _posts = page.items;
          _feedPage = page.currentPage;
          _feedHasMore = page.hasMore;
        });
      }
    } catch (_) {
      // Pull-to-refresh remains available when REST is temporarily offline.
    } finally {
      _refreshingFromRealtime = false;
    }
  }

  @override
  void dispose() {
    _searchController.dispose();
    unawaited(_campusChanges?.cancel() ?? Future.value());
    unawaited(_realtime?.dispose() ?? Future.value());
    super.dispose();
  }

  Future<void> _loadMore() async {
    if (_loadingMore || !_feedHasMore || _loading) return;
    setState(() => _loadingMore = true);
    try {
      final page = await widget.repository
          .getFeedPage(page: _feedPage + 1, perPage: _apiPageSize);
      if (!mounted) return;
      final seen = _posts.map((p) => p.id).toSet();
      setState(() {
        _posts = [..._posts, ...page.items.where((p) => !seen.contains(p.id))];
        _feedPage = page.currentPage;
        _feedHasMore = page.hasMore;
        _loadingMore = false;
      });
    } catch (_) {
      if (mounted) setState(() => _loadingMore = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);

    return Scaffold(
      backgroundColor: _socialBackground,
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'social-home-compose-fab',
        backgroundColor: _socialBlue,
        foregroundColor: Colors.white,
        elevation: 8,
        shape: const StadiumBorder(),
        onPressed: () => _compose(context),
        icon: Icon(Icons.add),
        label: Text(strings.t('social_share')),
      ),
      body: Column(
        children: [
          if (!widget.titleInShell)
            CampusPageHeader(
              title: strings.t('nav_social'),
              actions: [
                IconButton(
                  tooltip: strings.t('social_notifications'),
                  icon: const Icon(Icons.notifications_none_rounded),
                  onPressed: () => Navigator.of(context).push(MaterialPageRoute(
                      builder: (_) =>
                          NotificationsScreen(repository: widget.repository))),
                ),
              ],
            ),
          Expanded(
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 640),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 10, 16, 14),
                      child: SizedBox(
                        height: 52,
                        child: TextField(
                          controller: _searchController,
                          onChanged: (value) => setState(() => _query = value),
                          decoration: InputDecoration(
                            hintText: AppLocale.of(context).t('sf_search_hint'),
                            hintStyle: const TextStyle(color: _socialSecondary),
                            prefixIcon: const Icon(Icons.search_rounded,
                                size: 23, color: _socialSecondary),
                            suffixIcon: _query.isEmpty
                                ? null
                                : IconButton(
                                    tooltip: AppLocale.of(context)
                                        .t('sf_clear_search'),
                                    icon: const Icon(Icons.close, size: 18),
                                    onPressed: () {
                                      _searchController.clear();
                                      setState(() => _query = '');
                                    },
                                  ),
                            filled: true,
                            fillColor: ArucadColors.paper,
                            contentPadding:
                                const EdgeInsets.symmetric(vertical: 14),
                            border: _searchBorder(),
                            enabledBorder: _searchBorder(),
                            focusedBorder:
                                _searchBorder(color: _socialBlue, width: 1.4),
                          ),
                        ),
                      ),
                    ),
                    Expanded(child: _buildFeed(context)),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  OutlineInputBorder _searchBorder({
    Color color = _socialBorder,
    double width = 1,
  }) =>
      OutlineInputBorder(
        borderRadius: BorderRadius.circular(999),
        borderSide: BorderSide(color: color, width: width),
      );

  Widget _buildFeed(BuildContext context) {
    final strings = AppLocale.of(context);
    final filtered = _filteredPosts;
    final hasMore = _feedHasMore;

    return RefreshIndicator(
      onRefresh: _load,
      child: NotificationListener<ScrollNotification>(
        onNotification: (notification) {
          if (!hasMore || _loadingMore) return false;
          if (notification.metrics.pixels >=
              notification.metrics.maxScrollExtent - 300) {
            _loadMore();
          }
          return false;
        },
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 96),
          children: [
            if (!_loading)
              _StoriesBar(
                stories: _stories,
                viewedIds: _viewedStories,
                onAddStory: () => _addStory(context),
                onOpenStory: (index) => _openStory(context, index),
                onOpenTrends: () =>
                    setState(() => _filter = _FeedFilter.campus),
              ),
            const SizedBox(height: 14),
            if (!_loading)
              _FeedControls(
                selected: _filter,
                onSelected: (value) => setState(() => _filter = value),
              ),
            const SizedBox(height: 14),
            if (_loading)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 30),
                child: Center(child: CircularProgressIndicator()),
              )
            else if (_loadError != null)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 30),
                child: Center(
                    child: Text(_loadError!,
                        textAlign: TextAlign.center,
                        style: const TextStyle(color: ArucadColors.muted))),
              )
            else if (filtered.isEmpty)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 30),
                child: Center(
                    child: Text(
                        _posts.isEmpty
                            ? strings.t('social_empty')
                            : strings.t('social_empty_category'),
                        style: const TextStyle(color: ArucadColors.muted))),
              )
            else ...[
              for (int i = 0; i < filtered.length; i++)
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
                    onOpenProfile: () =>
                        _openProfile(context, filtered[i].name),
                    onLocationTag: filtered[i].locationTag == null
                        ? null
                        : () =>
                            _openLocationTag(context, filtered[i].locationTag!),
                    showPeerActions:
                        !filtered[i].official && filtered[i].name != _myName,
                    isOwn: !filtered[i].official && filtered[i].name == _myName,
                    onEdit: () => _editPost(context, filtered[i]),
                    onDelete: () => _deletePost(context, filtered[i]),
                    isFollowing: _following.contains(filtered[i].name),
                    onToggleFollow: () => _toggleFollow(filtered[i].name),
                    onToggleBlock: () => _toggleBlock(filtered[i].name),
                    canPin: filtered[i].official,
                    onPin: () => _togglePin(filtered[i]),
                  ),
                ),
              if (hasMore)
                Padding(
                  padding: const EdgeInsets.only(top: 14),
                  child: Center(
                    child: _loadingMore
                        ? const Padding(
                            padding: EdgeInsets.symmetric(vertical: 8),
                            child: SizedBox(
                                width: 24,
                                height: 24,
                                child:
                                    CircularProgressIndicator(strokeWidth: 2)),
                          )
                        : OutlinedButton.icon(
                            onPressed: _loadMore,
                            icon: const Icon(Icons.expand_more),
                            label: Text(strings.t('social_load_more')),
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
    // Optimistic: flip locally first so the tap feels instant. The guess is
    // only ever a guess — the server decides, and the reload below replaces
    // it with the real per-user state. If the call fails, put back exactly
    // what was on screen instead of leaving a heart the backend never
    // recorded.
    final before = _posts;
    setState(() {
      _posts = _posts.map((p) {
        if (p.id != postId) return p;
        final liked = !p.likedByMe;
        return p.copyWith(
            likedByMe: liked, likes: liked ? p.likes + 1 : p.likes - 1);
      }).toList();
    });
    try {
      final updated = await widget.repository.toggleLike(postId);
      if (!mounted) return;
      // The response is the real per-user state. Replacing the optimistic
      // guess with it is what keeps two accounts from sharing a heart.
      setState(() {
        _posts = _posts.map((p) => p.id == updated.id ? updated : p).toList();
      });
    } catch (e) {
      if (!mounted) return;
      setState(() => _posts = before);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(AppLocale.of(context).t('sf_like_failed'))),
      );
    }
  }

  Future<void> _toggleSave(String postId) async {
    try {
      final nowSaved = await widget.repository.toggleSavedPost(postId);
      if (!mounted) return;
      setState(() {
        if (nowSaved) {
          _saved.add(postId);
        } else {
          _saved.remove(postId);
        }
      });
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Kaydetme işlemi tamamlanamadı.')),
      );
    }
  }

  Future<void> _openDetail(BuildContext context, FeedPost post) async {
    await Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => PostDetailScreen(
              post: post,
              repository: widget.repository,
              mapProvider: widget.mapProvider,
              analyticsTracker: widget.analyticsTracker,
            )));
    if (!mounted) return;
    await _load();
  }

  Future<void> _openLocationTag(BuildContext context, String tag) async {
    try {
      final places = await widget.repository.getPlaces();
      final place = placeMatchingLocationTag(places, tag);
      if (!context.mounted) return;
      if (place == null) {
        ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('"$tag" için eşleşen mekân bulunamadı.')));
        return;
      }
      await Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => PlaceDetailScreen(
          place: place,
          repository: widget.repository,
          mapProvider: widget.mapProvider,
          analyticsTracker: widget.analyticsTracker,
        ),
      ));
    } catch (_) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(AppLocale.of(context).t('sf_place_failed'))));
    }
  }

  void _openProfile(BuildContext context, String name) {
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => SocialProfileScreen(
            repository: widget.repository,
            viewedUserName: name == _myName ? null : name)));
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
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Takip işlemi tamamlanamadı.')),
      );
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
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Engelleme işlemi tamamlanamadı.')),
      );
    }
  }

  Future<void> _openStory(BuildContext context, int index) async {
    await Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => _StoryViewerScreen(
            stories: _stories,
            initialIndex: index,
            myName: _myName,
            repository: widget.repository,
            onDelete: _deleteStory,
            onViewed: (story) async {
              // Optimistic instant ring-fade; the server call is the real
              // source of truth for the *next* load (`story.viewedByMe`).
              if (mounted) {
                setState(() => _viewedStories = {..._viewedStories, story.id});
              }
              try {
                await widget.repository.markStoryViewed(story.id);
              } catch (_) {
                if (!mounted) return;
                setState(() => _viewedStories = {
                      ..._viewedStories.where((id) => id != story.id),
                    });
                ScaffoldMessenger.of(this.context).showSnackBar(
                  const SnackBar(
                      content: Text('Hikâye görüntülenmesi kaydedilemedi.')),
                );
              }
            }),
        fullscreenDialog: true));
  }

  Future<void> _addStory(BuildContext context) async {
    final result =
        await showComposeStorySheet(context, repository: widget.repository);
    if (result == null) return;
    final text = result.text?.trim();
    if ((text == null || text.isEmpty) && result.imageBytes == null) return;
    try {
      if (result.imageBytes != null) {
        await ImageModerationService.assertImageAllowed(
            result.imageBytes!, widget.repository);
      }
      await widget.repository.addStory(
        text: result.imageBytes == null ? text : null,
        imageBytes: result.imageBytes,
        backgroundColorValue:
            result.imageBytes == null ? result.backgroundColorValue : null,
        style: result.imageBytes == null ? result.style : null,
        visibility: result.visibility,
      );
    } on ContentModerationException catch (e) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(e.reason)));
      return;
    } catch (_) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Hikâye paylaşılamadı.')),
      );
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
                Text(
                    '${strings.t('social_comments_label')} (${comments.length})',
                    style:
                        TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
                const SizedBox(height: 10),
                if (comments.isEmpty)
                  Padding(
                    padding: EdgeInsets.symmetric(vertical: 10),
                    child: Text(strings.t('social_no_comments_yet'),
                        style: const TextStyle(color: ArucadColors.muted)),
                  )
                else
                  ConstrainedBox(
                    constraints: BoxConstraints(maxHeight: 280),
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
                                style: const TextStyle(
                                    fontWeight: FontWeight.w800)),
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
                      decoration: InputDecoration(
                          hintText: strings.t('social_write_comment')),
                    ),
                  ),
                  IconButton(
                    onPressed: () async {
                      final text = commentController.text.trim();
                      if (text.isEmpty) return;
                      final previous = comments;
                      setSheetState(() {
                        comments = [
                          ...comments,
                          PostComment(
                            id: 'tmp-${DateTime.now().millisecondsSinceEpoch}',
                            author: _myName ?? '',
                            text: text,
                            meta: 'şimdi',
                          ),
                        ];
                      });
                      try {
                        final updated =
                            await widget.repository.addComment(post.id, text);
                        commentController.clear();
                        if (!ctx.mounted) return;
                        setSheetState(() => comments = updated.comments);
                        if (mounted) {
                          setState(() {
                            _posts = _posts
                                .map((p) => p.id == updated.id ? updated : p)
                                .toList();
                          });
                        }
                      } on ContentModerationException catch (e) {
                        setSheetState(() => comments = previous);
                        if (!ctx.mounted) return;
                        ScaffoldMessenger.of(ctx)
                            .showSnackBar(SnackBar(content: Text(e.reason)));
                      } catch (_) {
                        setSheetState(() => comments = previous);
                        if (!ctx.mounted) return;
                        ScaffoldMessenger.of(ctx).showSnackBar(
                          const SnackBar(content: Text('Yorum kaydedilemedi.')),
                        );
                      }
                    },
                    icon: const Icon(Icons.send, color: ArucadColors.blue),
                  ),
                ]),
              ],
            ),
          );
        },
      ),
    );
  }

  /// Reporting now goes through the shared sheet, which sends a reason
  /// *code* rather than a localised sentence. The old dialog posted the
  /// Turkish label as free text, so the same complaint filed in two
  /// languages arrived as two unrelated things the queue could not group
  /// or prioritise.
  Future<void> _report(BuildContext context, FeedPost post) async {
    ReportReason? chosen;

    final sent = await showReportSheet(
      context,
      targetLabel: post.text.isEmpty ? post.name : post.text,
      onSubmit: (submission) async {
        chosen = submission.reason;
        await widget.repository.reportPost(
          post.id,
          submission.description,
          reasonCode: submission.reason.code,
        );
      },
    );

    if (!sent || !context.mounted || chosen == null) return;
    showReportSentMessage(context, chosen!);
  }

  Future<void> _editPost(BuildContext context, FeedPost post) async {
    final textC = TextEditingController(text: post.text);
    final newText = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(AppLocale.of(context).t('sp_edit_post')),
        content: TextField(controller: textC, maxLines: 5, autofocus: true),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: Text(AppLocale.of(context).t('act_cancel'))),
          FilledButton(
              onPressed: () => Navigator.pop(ctx, textC.text.trim()),
              child: Text(AppLocale.of(context).t('act_save'))),
        ],
      ),
    );
    if (newText == null || newText.isEmpty || newText == post.text) return;
    try {
      final updated =
          await widget.repository.updatePost(post.id, text: newText);
      if (!mounted) return;
      setState(() => _posts =
          _posts.map((p) => p.id == updated.id ? updated : p).toList());
    } on ContentModerationException catch (e) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(e.reason)));
    } catch (_) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Gönderi güncellenemedi.')),
      );
    }
  }

  Future<void> _deletePost(BuildContext context, FeedPost post) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(AppLocale.of(context).t('sp_delete_post_q')),
        content: Text(AppLocale.of(context).t('act_undoable')),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: Text(AppLocale.of(context).t('act_cancel'))),
          FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('Sil')),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await widget.repository.deletePost(post.id);
      if (!mounted) return;
      setState(() => _posts = _posts.where((p) => p.id != post.id).toList());
    } catch (_) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Gönderi silinemedi.')),
      );
    }
  }

  Future<void> _deleteStory(CampusStory story) async {
    try {
      await widget.repository.deleteStory(story.id);
      if (!mounted) return;
      setState(
          () => _stories = _stories.where((s) => s.id != story.id).toList());
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Hikâye silinemedi.')),
      );
      rethrow;
    }
  }

  Future<void> _togglePin(FeedPost post) async {
    try {
      final updated = post.isPinned
          ? await widget.repository.unpinPost(post.id)
          : await widget.repository.pinPost(post.id);
      if (!mounted) return;
      setState(() {
        _posts = [
          for (final p in _posts)
            if (p.id == post.id) updated else p
        ]..sort((a, b) {
            if (a.isPinned != b.isPinned) return a.isPinned ? -1 : 1;
            return 0;
          });
      });
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Pin işlemi başarısız: $e')),
      );
    }
  }
}

class _FeedControls extends StatelessWidget {
  final _FeedFilter selected;
  final ValueChanged<_FeedFilter> onSelected;

  const _FeedControls({required this.selected, required this.onSelected});

  String get _categoryLabel => switch (selected) {
        _FeedFilter.official => 'Resmî',
        _FeedFilter.campus => 'Kampüs',
        _FeedFilter.courses => 'Dersler',
        _FeedFilter.events => 'Etkinlikler',
        _ => 'Tümü',
      };

  @override
  Widget build(BuildContext context) {
    return Row(children: [
      Expanded(
        child: Container(
          height: 52,
          padding: const EdgeInsets.all(4),
          decoration: BoxDecoration(
            color: ArucadColors.mist,
            borderRadius: BorderRadius.circular(999),
          ),
          child: Row(children: [
            _segment('Akış', _FeedFilter.forYou),
            _segment('Takip Ettiklerim', _FeedFilter.following),
          ]),
        ),
      ),
      const SizedBox(width: 10),
      PopupMenuButton<_FeedFilter>(
        tooltip: 'İçerik türü',
        style: ButtonStyle(
          overlayColor: WidgetStateProperty.all(Colors.transparent),
          splashFactory: NoSplash.splashFactory,
        ),
        color: ArucadColors.paper,
        surfaceTintColor: Colors.transparent,
        elevation: 10,
        position: PopupMenuPosition.under,
        offset: const Offset(0, 6),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(18),
          side: const BorderSide(color: ArucadColors.border),
        ),
        onSelected: onSelected,
        itemBuilder: (_) => [
          for (final entry in const [
            (_FeedFilter.forYou, 'Tümü', Icons.grid_view_rounded),
            (_FeedFilter.official, 'Resmî', Icons.verified_rounded),
            (_FeedFilter.campus, 'Kampüs', Icons.apartment_rounded),
            (_FeedFilter.courses, 'Dersler', Icons.menu_book_rounded),
            (_FeedFilter.events, 'Etkinlikler', Icons.event_rounded),
          ])
            PopupMenuItem(
              value: entry.$1,
              child: Row(children: [
                Icon(entry.$3,
                    size: 19,
                    color: selected == entry.$1
                        ? ArucadColors.primary
                        : ArucadColors.muted),
                const SizedBox(width: 10),
                Text(entry.$2,
                    style: TextStyle(
                      color: ArucadColors.ink,
                      fontWeight: selected == entry.$1
                          ? FontWeight.w800
                          : FontWeight.w600,
                    )),
              ]),
            ),
        ],
        child: Container(
          constraints: const BoxConstraints(minHeight: 44, minWidth: 82),
          padding: const EdgeInsets.symmetric(horizontal: 12),
          decoration: BoxDecoration(
            color: ArucadColors.paper,
            borderRadius: BorderRadius.circular(999),
            border: Border.all(color: ArucadColors.border),
          ),
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            Text(_categoryLabel,
                style: const TextStyle(
                    color: ArucadColors.ink,
                    fontSize: 13,
                    fontWeight: FontWeight.w700)),
            const SizedBox(width: 3),
            const Icon(Icons.keyboard_arrow_down_rounded,
                size: 20, color: ArucadColors.primary),
          ]),
        ),
      ),
    ]);
  }

  Widget _segment(String label, _FeedFilter value) {
    final active = selected == value;
    return Expanded(
      child: Material(
        color: active ? _socialBlue : Colors.transparent,
        borderRadius: BorderRadius.circular(999),
        child: InkWell(
          onTap: () => onSelected(value),
          borderRadius: BorderRadius.circular(999),
          child: Center(
            child: Text(label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  color: active ? Colors.white : ArucadColors.ink,
                  fontSize: 13,
                  fontWeight: FontWeight.w700,
                )),
          ),
        ),
      ),
    );
  }
}

class _StoriesBar extends StatelessWidget {
  final List<CampusStory> stories;
  final Set<String> viewedIds;
  final VoidCallback onAddStory;
  final ValueChanged<int> onOpenStory;
  final VoidCallback onOpenTrends;

  const _StoriesBar(
      {required this.stories,
      required this.viewedIds,
      required this.onAddStory,
      required this.onOpenStory,
      required this.onOpenTrends});

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    return SizedBox(
      height: 106,
      child: ListView(
        scrollDirection: Axis.horizontal,
        physics: const BouncingScrollPhysics(),
        children: [
          _StoryBubble(
            label: strings.t('social_new_story'),
            onTap: onAddStory,
            child: Container(
              decoration: BoxDecoration(
                  color: ArucadColors.primary.withValues(alpha: .10),
                  shape: BoxShape.circle),
              child: const Icon(Icons.add, color: ArucadColors.primary),
            ),
          ),
          for (var i = 0; i < stories.length; i++)
            _StoryBubble(
              label: stories[i].authorName,
              ring: true,
              viewed: viewedIds.contains(stories[i].id),
              onTap: () => onOpenStory(i),
              child: stories[i].imageBytes != null
                  ? ClipOval(
                      child: Image.memory(stories[i].imageBytes!,
                          fit: BoxFit.cover))
                  : stories[i].imageUrl != null
                      ? ClipOval(
                          child: CampusNetworkImage(
                          stories[i].imageUrl!,
                          width: 60,
                          height: 60,
                        ))
                      : Container(
                          decoration: BoxDecoration(
                              color: Color(stories[i].backgroundColorValue ??
                                  ArucadColors.blue.toARGB32()),
                              shape: BoxShape.circle),
                          child: const Icon(Icons.text_fields,
                              color: Colors.white),
                        ),
            ),
          _StoryBubble(
            label: 'Trendler',
            onTap: onOpenTrends,
            child: Container(
              decoration: BoxDecoration(
                color: ArucadColors.yellow.withValues(alpha: .14),
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.tag_rounded,
                  color: ArucadColors.yellow, size: 30),
            ),
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
  final bool viewed;

  const _StoryBubble(
      {required this.label,
      required this.child,
      required this.onTap,
      this.ring = false,
      this.viewed = false});

  @override
  Widget build(BuildContext context) => GestureDetector(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.only(right: 14),
          child: Opacity(
            opacity: viewed ? 0.45 : 1,
            child: Column(children: [
              Container(
                width: 68,
                height: 68,
                padding: const EdgeInsets.all(3),
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  border: Border.all(
                    color: ring
                        ? (viewed ? ArucadColors.muted : _socialBlue)
                        : ArucadColors.primary.withValues(alpha: .12),
                    width: ring ? 2.5 : 2,
                  ),
                ),
                child: ClipOval(child: child),
              ),
              const SizedBox(height: 6),
              SizedBox(
                width: 76,
                child: Text(label,
                    textAlign: TextAlign.center,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                        fontSize: 11.5,
                        color: viewed ? ArucadColors.muted : null)),
              ),
            ]),
          ),
        ),
      );
}

class _StoryViewerScreen extends StatefulWidget {
  final List<CampusStory> stories;
  final int initialIndex;
  final String? myName;
  final CampusRepository? repository;
  final Future<void> Function(CampusStory)? onDelete;
  final Future<void> Function(CampusStory)? onViewed;
  const _StoryViewerScreen({
    required this.stories,
    required this.initialIndex,
    this.myName,
    this.repository,
    this.onDelete,
    this.onViewed,
  });

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
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _markCurrentViewed();
    });
  }

  void _markCurrentViewed() {
    if (_index < 0 || _index >= widget.stories.length) return;
    unawaited(widget.onViewed?.call(widget.stories[_index]) ?? Future.value());
  }

  bool get _isOwnCurrent =>
      widget.myName != null &&
      widget.stories[_index].authorName == widget.myName;

  Future<void> _showViewers() async {
    final repo = widget.repository;
    if (repo == null) return;
    final story = widget.stories[_index];
    List<StoryViewer> viewers = const [];
    try {
      viewers = await repo.getStoryViewers(story.id);
    } catch (_) {}
    if (!mounted) return;
    await showModalBottomSheet<void>(
      context: context,
      builder: (ctx) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Görenler (${viewers.length})',
                  style: TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
              const SizedBox(height: 12),
              if (viewers.isEmpty)
                Padding(
                  padding: EdgeInsets.symmetric(vertical: 12),
                  child: Text(AppLocale.of(context).t('sf_no_viewers')),
                )
              else
                Flexible(
                  child: ListView(
                    shrinkWrap: true,
                    children: [
                      for (final v in viewers)
                        ListTile(
                          dense: true,
                          leading: CircleAvatar(
                            radius: 16,
                            backgroundImage:
                                (v.avatarUrl != null && v.avatarUrl!.isNotEmpty)
                                    ? NetworkImage(v.avatarUrl!)
                                    : null,
                            child: (v.avatarUrl == null || v.avatarUrl!.isEmpty)
                                ? Text(v.name.isEmpty
                                    ? '?'
                                    : v.name.substring(0, 1).toUpperCase())
                                : null,
                          ),
                          title: Text(v.name),
                        ),
                    ],
                  ),
                ),
            ],
          ),
        ),
      ),
    );
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
      _controller.nextPage(
          duration: const Duration(milliseconds: 250), curve: Curves.ease);
    }
  }

  void _prev() {
    if (_index == 0) return;
    _controller.previousPage(
        duration: const Duration(milliseconds: 250), curve: Curves.ease);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      body: Stack(children: [
        PageView.builder(
          controller: _controller,
          itemCount: widget.stories.length,
          onPageChanged: (i) {
            setState(() => _index = i);
            _markCurrentViewed();
          },
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
              child: ClipRect(
                child: story.imageBytes != null
                    ? SizedBox.expand(
                        child:
                            Image.memory(story.imageBytes!, fit: BoxFit.cover))
                    : story.imageUrl != null
                        ? SizedBox.expand(
                            child: CampusNetworkImage(story.imageUrl!,
                                fit: BoxFit.cover))
                        : Container(
                            decoration: storyBackgroundDecoration(
                              backgroundColorValue: story.backgroundColorValue,
                              style: story.style,
                            ),
                            alignment: Alignment.center,
                            padding: const EdgeInsets.all(32),
                            child: Text(
                              story.text ?? '',
                              textAlign: TextAlign.center,
                              style: storyTextStyleFromMap(story.style),
                            ),
                          ),
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
          right: 72,
          child: Builder(builder: (context) {
            final story = widget.stories[_index];
            final style = story.style;
            final location = style?['locationTag'] as String?;
            final tagged = (style?['taggedPeople'] as List?)
                    ?.whereType<String>()
                    .toList() ??
                const <String>[];
            return Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(story.authorName,
                    style: const TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.w800,
                        fontSize: 14)),
                if (location != null && location.trim().isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Row(children: [
                    const Icon(Icons.place, color: Colors.white70, size: 14),
                    const SizedBox(width: 4),
                    Expanded(
                      child: Text(location,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                              color: Colors.white70, fontSize: 12)),
                    ),
                  ]),
                ],
                if (tagged.isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text('ile · ${tagged.join(', ')}',
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style:
                          const TextStyle(color: Colors.white70, fontSize: 12)),
                ],
              ],
            );
          }),
        ),
        Positioned(
          top: 56,
          right: 8,
          child: Row(children: [
            if (_isOwnCurrent && widget.repository != null)
              IconButton(
                tooltip: AppLocale.of(context).t('sf_viewers'),
                onPressed: _showViewers,
                icon:
                    const Icon(Icons.visibility_outlined, color: Colors.white),
              ),
            if (widget.onDelete != null && _isOwnCurrent)
              IconButton(
                onPressed: () => _confirmDeleteCurrent(),
                icon: const Icon(Icons.delete_outline, color: Colors.white),
              ),
            IconButton(
              onPressed: () => Navigator.of(context).pop(),
              icon: const Icon(Icons.close, color: Colors.white),
            ),
          ]),
        ),
      ]),
    );
  }

  Future<void> _confirmDeleteCurrent() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Hikaye silinsin mi?'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: Text(AppLocale.of(context).t('act_cancel'))),
          FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('Sil')),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await widget.onDelete?.call(widget.stories[_index]);
    } catch (_) {
      return;
    }
    if (mounted) Navigator.of(context).pop();
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
  final VoidCallback? onLocationTag;
  final bool showPeerActions;
  final bool isOwn;
  final VoidCallback? onEdit;
  final VoidCallback? onDelete;
  final bool isFollowing;
  final VoidCallback? onToggleFollow;
  final VoidCallback? onToggleBlock;
  final bool canPin;
  final VoidCallback? onPin;

  const _PostCard({
    required this.post,
    required this.saved,
    required this.onLike,
    required this.onComment,
    required this.onReport,
    required this.onSave,
    required this.onOpen,
    required this.onOpenProfile,
    this.onLocationTag,
    this.showPeerActions = false,
    this.isOwn = false,
    this.onEdit,
    this.onDelete,
    this.isFollowing = false,
    this.onToggleFollow,
    this.onToggleBlock,
    this.canPin = false,
    this.onPin,
  });

  @override
  Widget build(BuildContext context) => Card(
        margin: EdgeInsets.zero,
        clipBehavior: Clip.antiAlias,
        surfaceTintColor: Colors.transparent,
        color: Colors.white,
        shadowColor: const Color(0x16111827),
        elevation: 2,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(22),
          side: BorderSide(
            color: post.official ? _socialBlue : _socialBorder,
            width: post.official ? 1.2 : 1,
          ),
        ),
        child: InkWell(
          onTap: onOpen,
          hoverColor: Colors.transparent,
          splashColor: Colors.transparent,
          highlightColor: Colors.transparent,
          overlayColor: WidgetStateProperty.all(Colors.transparent),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child:
                Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                GestureDetector(
                  onTap: post.official ? null : onOpenProfile,
                  child: CampusAvatar(
                    name: post.name,
                    avatarUrl: post.authorAvatarUrl,
                    radius: 24,
                    official: post.official,
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Row(children: [
                    Flexible(
                      child: GestureDetector(
                        onTap: post.official ? null : onOpenProfile,
                        child: Text(post.name,
                            overflow: TextOverflow.ellipsis,
                            style:
                                const TextStyle(fontWeight: FontWeight.w900)),
                      ),
                    ),
                    if (post.postType != PostCategory.normal) ...[
                      const SizedBox(width: 6),
                      Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 7, vertical: 2),
                        decoration: BoxDecoration(
                            color: categoryAccent(post.postType.name)
                                .withValues(alpha: .18),
                            borderRadius: BorderRadius.circular(999)),
                        child: Text(post.postType.label(AppLocale.of(context)),
                            style: const TextStyle(
                                fontSize: 10, fontWeight: FontWeight.w800)),
                      ),
                    ],
                    if (post.isPinned) ...[
                      const SizedBox(width: 6),
                      const Icon(Icons.push_pin,
                          size: 14, color: ArucadColors.primary),
                    ],
                    if (post.official) ...[
                      SizedBox(width: 6),
                      Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 7, vertical: 2),
                        decoration: BoxDecoration(
                            color: ArucadColors.primary.withValues(alpha: .12),
                            borderRadius: BorderRadius.circular(999)),
                        child: Text(AppLocale.of(context).t('sf_official'),
                            style: ArucadTextStyles.display(
                                fontSize: 10,
                                fontWeight: FontWeight.w900,
                                color: ArucadColors.primary)),
                      ),
                    ],
                    if (post.visibility == PostVisibility.friends) ...[
                      SizedBox(width: 6),
                      Icon(Icons.people_outline,
                          size: 14, color: ArucadColors.muted),
                    ],
                    if (post.visibility == PostVisibility.onlyMe) ...[
                      SizedBox(width: 6),
                      Icon(Icons.lock_outline,
                          size: 14, color: ArucadColors.muted),
                    ],
                  ]),
                ),
                if (canPin)
                  IconButton(
                    tooltip: post.isPinned
                        ? AppLocale.of(context).t('social_unpin')
                        : AppLocale.of(context).t('social_pin'),
                    onPressed: onPin,
                    icon: Icon(
                      post.isPinned ? Icons.push_pin : Icons.push_pin_outlined,
                      color: ArucadColors.primary,
                    ),
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
                      case 'edit':
                        onEdit?.call();
                      case 'delete':
                        onDelete?.call();
                      case 'pin':
                        onPin?.call();
                    }
                  },
                  itemBuilder: (ctx) {
                    final strings = AppLocale.of(ctx);
                    return [
                      if (canPin)
                        PopupMenuItem(
                            value: 'pin',
                            child: Text(post.isPinned
                                ? strings.t('social_unpin')
                                : strings.t('social_pin'))),
                      if (showPeerActions) ...[
                        PopupMenuItem(
                            value: 'follow',
                            child: Text(isFollowing
                                ? strings.t('social_following')
                                : strings.t('social_follow'))),
                        PopupMenuItem(
                            value: 'block',
                            child: Text(strings.t('social_block'),
                                style: const TextStyle(
                                    color: ArucadColors.danger))),
                      ],
                      if (isOwn) ...[
                        PopupMenuItem(
                            value: 'edit',
                            child: Text(AppLocale.of(context).t('act_edit'))),
                        const PopupMenuItem(
                            value: 'delete',
                            child: Text('Sil',
                                style: TextStyle(color: ArucadColors.danger))),
                      ] else
                        PopupMenuItem(
                            value: 'report',
                            child: Text(strings.t('social_report_post'))),
                    ];
                  },
                ),
              ]),
              const SizedBox(height: 14),
              if (post.text.isNotEmpty)
                Text(post.displayText,
                    style: const TextStyle(
                        fontSize: 16, height: 1.42, color: ArucadColors.ink)),
              if (post.imageBytes != null || post.imageUrl != null) ...[
                const SizedBox(height: 10),
                FeedPostMedia(
                  imageUrl: post.imageUrl,
                  imageBytes: post.imageBytes,
                  mimeType: post.mediaMimeType,
                  maxWidth: 1000,
                  borderRadius: 16,
                  aspectRatio: 16 / 9,
                ),
              ],
              if (post.locationTag != null || post.courseTag != null) ...[
                const SizedBox(height: 8),
                Wrap(spacing: 6, runSpacing: 6, children: [
                  if (post.locationTag != null)
                    _MiniTagChip(
                      icon: Icons.place_outlined,
                      label: post.locationTag!,
                      onTap: onLocationTag,
                    ),
                  if (post.courseTag != null)
                    _MiniTagChip(
                        icon: Icons.menu_book_outlined, label: post.courseTag!),
                ]),
              ],
              if (post.hashtags.isNotEmpty) ...[
                const SizedBox(height: 6),
                Wrap(spacing: 6, children: [
                  for (final tag in post.hashtags)
                    Text('#$tag',
                        style: TextStyle(
                            color: categoryAccent(tag),
                            fontWeight: FontWeight.w700,
                            fontSize: 12)),
                ]),
              ],
              const SizedBox(height: 6),
              Text(formatRelativeTime(post.createdAt ?? DateTime.now()),
                  style:
                      const TextStyle(color: _socialSecondary, fontSize: 13)),
              const SizedBox(height: 8),
              Row(children: [
                _PostAction(
                  onTap: onLike,
                  icon: post.likedByMe
                      ? Icons.favorite_rounded
                      : Icons.favorite_border_rounded,
                  label: '${post.likes}',
                  color: post.likedByMe ? ArucadColors.red : null,
                ),
                const SizedBox(width: 8),
                _PostAction(
                  onTap: onComment,
                  icon: Icons.mode_comment_outlined,
                  label: '${post.comments.length}',
                ),
                const Spacer(),
                _PostAction(
                  onTap: onSave,
                  icon: saved
                      ? Icons.bookmark_rounded
                      : Icons.bookmark_border_rounded,
                  label: 'Kaydet',
                  color: saved ? _socialBlue : null,
                ),
              ]),
            ]),
          ),
        ),
      );
}

class _PostAction extends StatelessWidget {
  final VoidCallback onTap;
  final IconData icon;
  final String label;
  final Color? color;

  const _PostAction({
    required this.onTap,
    required this.icon,
    required this.label,
    this.color,
  });

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(999),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 44),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 4),
            child: Row(mainAxisSize: MainAxisSize.min, children: [
              Icon(icon, size: 22, color: color),
              const SizedBox(width: 7),
              Text(label,
                  style: TextStyle(
                      color: color,
                      fontSize: 13.5,
                      fontWeight: FontWeight.w500)),
            ]),
          ),
        ),
      );
}

class _MiniTagChip extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback? onTap;
  const _MiniTagChip({required this.icon, required this.label, this.onTap});

  @override
  Widget build(BuildContext context) {
    final chip = Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
          color: onTap != null
              ? ArucadColors.primary.withValues(alpha: .10)
              : ArucadColors.mist,
          borderRadius: BorderRadius.circular(999)),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(icon,
            size: 12,
            color: onTap != null ? ArucadColors.primary : ArucadColors.muted),
        const SizedBox(width: 4),
        Text(label,
            style: TextStyle(
                fontSize: 11,
                fontWeight: FontWeight.w600,
                color: onTap != null ? ArucadColors.primary : null)),
      ]),
    );
    if (onTap == null) return chip;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(999),
      child: chip,
    );
  }
}
