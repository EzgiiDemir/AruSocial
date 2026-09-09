import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/config/place_tour.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/content_moderation.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/mock_analytics_tracker.dart';
import 'package:arucad_campus_prototype/core/services/url_launcher_map_provider.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/core/utils/relative_time.dart';
import 'package:arucad_campus_prototype/features/place/place_detail_screen.dart';
import 'package:arucad_campus_prototype/features/social/social_profile_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_avatar.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_back_button.dart';
import 'package:arucad_campus_prototype/features/widgets/feed_post_media.dart';

/// Full-screen single-post view — the post itself plus its comments inline,
/// reached by tapping a card in the feed. Likes/comments/save all act on
/// the same real repository state the feed card does.
class PostDetailScreen extends StatefulWidget {
  final FeedPost post;
  final CampusRepository repository;
  final MapProvider? mapProvider;
  final AnalyticsTracker? analyticsTracker;

  const PostDetailScreen({
    super.key,
    required this.post,
    required this.repository,
    this.mapProvider,
    this.analyticsTracker,
  });

  @override
  State<PostDetailScreen> createState() => _PostDetailScreenState();
}

class _PostDetailScreenState extends State<PostDetailScreen> {
  late FeedPost _post;
  bool _saved = false;
  String? _myName;
  final _commentController = TextEditingController();

  @override
  void initState() {
    super.initState();
    _post = widget.post;
    widget.repository.getSavedPostIds().then((ids) {
      if (mounted) setState(() => _saved = ids.contains(_post.id));
    });
    widget.repository.getMe().then((me) {
      if (mounted) setState(() => _myName = me.name);
    });
  }

  Future<void> _like() async {
    // Optimistic, then reconciled against the server — and rolled back if
    // the server never accepted it. See _SocialScreenState._like.
    final before = _post;
    setState(() {
      final liked = !_post.likedByMe;
      _post = _post.copyWith(
          likedByMe: liked, likes: liked ? _post.likes + 1 : _post.likes - 1);
    });
    try {
      final updated = await widget.repository.toggleLike(_post.id);
      if (!mounted) return;
      setState(() => _post = updated);
    } catch (e) {
      if (!mounted) return;
      setState(() => _post = before);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Beğeni kaydedilemedi.')),
      );
    }
  }

  Future<void> _toggleSave() async {
    try {
      final nowSaved = await widget.repository.toggleSavedPost(_post.id);
      if (mounted) setState(() => _saved = nowSaved);
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Kaydetme işlemi tamamlanamadı.')),
      );
    }
  }

  Future<void> _addComment() async {
    final text = _commentController.text.trim();
    if (text.isEmpty) return;
    final previous = _post;
    setState(() {
      _post = _post.copyWith(comments: [
        ..._post.comments,
        PostComment(
          id: 'tmp-${DateTime.now().millisecondsSinceEpoch}',
          author: _myName ?? '',
          text: text,
          meta: 'şimdi',
        ),
      ]);
    });
    try {
      final updated = await widget.repository.addComment(previous.id, text);
      _commentController.clear();
      if (!mounted) return;
      setState(() => _post = updated);
    } on ContentModerationException catch (e) {
      if (!mounted) return;
      setState(() => _post = previous);
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(e.reason)));
    } catch (_) {
      if (!mounted) return;
      setState(() => _post = previous);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Yorum kaydedilemedi.')),
      );
    }
  }

  void _openAuthorProfile(BuildContext context) {
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => SocialProfileScreen(
            repository: widget.repository,
            viewedUserName: _post.name == _myName ? null : _post.name)));
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
          mapProvider: widget.mapProvider ?? const UrlLauncherMapProvider(),
          analyticsTracker: widget.analyticsTracker ?? MockAnalyticsTracker(),
        ),
      ));
    } catch (_) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(const SnackBar(content: Text('Mekân açılamadı.')));
    }
  }

  @override
  Widget build(BuildContext context) {
    final post = _post;
    final strings = AppLocale.of(context);
    return Scaffold(
      backgroundColor: Colors.white,
      appBar: AppBar(
        backgroundColor: Colors.white,
        surfaceTintColor: Colors.transparent,
        title: Text('${post.postType.emoji} ${post.postType.label(strings)}'),
        leading: const CampusBackButton(),
      ),
      body: ColoredBox(
        color: Colors.white,
        child: Column(children: [
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(20, 16, 20, 20),
              children: [
                GestureDetector(
                  onTap:
                      post.official ? null : () => _openAuthorProfile(context),
                  child: Row(children: [
                    CampusAvatar(
                      name: post.name,
                      avatarUrl: post.authorAvatarUrl,
                      radius: 18,
                      official: post.official,
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(post.name,
                                style: const TextStyle(
                                    fontWeight: FontWeight.w900)),
                            Text(
                                formatRelativeTime(
                                    post.createdAt ?? DateTime.now()),
                                style: const TextStyle(
                                    color: ArucadColors.muted, fontSize: 12)),
                          ]),
                    ),
                  ]),
                ),
                const SizedBox(height: 14),
                if (post.text.isNotEmpty)
                  Text(post.displayText,
                      style: const TextStyle(fontSize: 16, height: 1.4)),
                if (post.imageBytes != null || post.imageUrl != null) ...[
                  const SizedBox(height: 12),
                  FeedPostMedia(
                    maxWidth: 252,
                    borderRadius: 16,
                    imageUrl: post.imageUrl,
                    imageBytes: post.imageBytes,
                    mimeType: post.mediaMimeType,
                  ),
                ],
                if (post.locationTag != null || post.courseTag != null) ...[
                  const SizedBox(height: 10),
                  Wrap(spacing: 8, runSpacing: 8, children: [
                    if (post.locationTag != null)
                      _TagChip(
                        icon: Icons.place_outlined,
                        label: post.locationTag!,
                        onTap: () =>
                            _openLocationTag(context, post.locationTag!),
                      ),
                    if (post.courseTag != null)
                      _TagChip(
                          icon: Icons.menu_book_outlined,
                          label: post.courseTag!),
                  ]),
                ],
                if (post.hashtags.isNotEmpty) ...[
                  const SizedBox(height: 10),
                  Wrap(
                    spacing: 6,
                    children: [
                      for (final tag in post.hashtags)
                        Text('#$tag',
                            style: const TextStyle(
                                color: ArucadColors.blue,
                                fontWeight: FontWeight.w700)),
                    ],
                  ),
                ],
                const SizedBox(height: 16),
                Row(children: [
                  InkWell(
                    onTap: _like,
                    borderRadius: BorderRadius.circular(999),
                    child: Row(children: [
                      Icon(
                          post.likedByMe
                              ? Icons.favorite
                              : Icons.favorite_border,
                          size: 22,
                          color: post.likedByMe ? ArucadColors.danger : null),
                      const SizedBox(width: 6),
                      Text('${post.likes}'),
                    ]),
                  ),
                  const SizedBox(width: 22),
                  Row(children: [
                    const Icon(Icons.mode_comment_outlined, size: 20),
                    const SizedBox(width: 6),
                    Text('${post.comments.length}'),
                  ]),
                  const Spacer(),
                  InkWell(
                    onTap: _toggleSave,
                    borderRadius: BorderRadius.circular(999),
                    child: Icon(_saved ? Icons.bookmark : Icons.bookmark_border,
                        size: 22, color: _saved ? ArucadColors.blue : null),
                  ),
                ]),
                const Divider(height: 32),
                Text(
                    '${strings.t('social_comments_label')} (${post.comments.length})',
                    style: const TextStyle(
                        fontWeight: FontWeight.w900, fontSize: 15)),
                const SizedBox(height: 10),
                if (post.comments.isEmpty)
                  Text(strings.t('social_no_comments_yet'),
                      style: const TextStyle(color: ArucadColors.muted))
                else
                  for (final c in post.comments)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 14),
                      child: Column(
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
                          ]),
                    ),
              ],
            ),
          ),
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
              child: Row(children: [
                Expanded(
                  child: TextField(
                    controller: _commentController,
                    decoration: InputDecoration(
                        hintText: strings.t('social_write_comment')),
                    onSubmitted: (_) => _addComment(),
                  ),
                ),
                IconButton(
                  onPressed: _addComment,
                  icon: const Icon(Icons.send, color: ArucadColors.blue),
                ),
              ]),
            ),
          ),
        ]),
      ),
    );
  }
}

class _TagChip extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback? onTap;
  const _TagChip({required this.icon, required this.label, this.onTap});

  @override
  Widget build(BuildContext context) {
    final chip = Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
          color: onTap != null
              ? ArucadColors.primary.withValues(alpha: .10)
              : ArucadColors.mist,
          borderRadius: BorderRadius.circular(999)),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(icon,
            size: 14,
            color: onTap != null ? ArucadColors.primary : ArucadColors.muted),
        const SizedBox(width: 5),
        Text(label,
            style: TextStyle(
                fontSize: 12,
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
