import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/social/social_profile_screen.dart';

/// Full-screen single-post view — the post itself plus its comments inline,
/// reached by tapping a card in the feed. Likes/comments/save all act on
/// the same real repository state the feed card does.
class PostDetailScreen extends StatefulWidget {
  final FeedPost post;
  final CampusRepository repository;
  const PostDetailScreen({super.key, required this.post, required this.repository});

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

  Future<void> _refresh() async {
    final feed = await widget.repository.getFeed();
    final updated = feed.where((p) => p.id == _post.id).toList();
    if (updated.isNotEmpty && mounted) setState(() => _post = updated.first);
  }

  Future<void> _like() async {
    setState(() {
      final liked = !_post.likedByMe;
      _post = _post.copyWith(likedByMe: liked, likes: liked ? _post.likes + 1 : _post.likes - 1);
    });
    await widget.repository.toggleLike(_post.id);
    await _refresh();
  }

  Future<void> _toggleSave() async {
    final nowSaved = await widget.repository.toggleSavedPost(_post.id);
    if (mounted) setState(() => _saved = nowSaved);
  }

  Future<void> _addComment() async {
    final text = _commentController.text.trim();
    if (text.isEmpty) return;
    await widget.repository.addComment(_post.id, text);
    _commentController.clear();
    await _refresh();
  }

  void _openAuthorProfile(BuildContext context) {
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => SocialProfileScreen(
            repository: widget.repository,
            viewedUserName: _post.name == _myName ? null : _post.name)));
  }

  @override
  Widget build(BuildContext context) {
    final post = _post;
    final strings = AppLocale.of(context);
    return Scaffold(
      appBar: AppBar(title: Text('${post.postType.emoji} ${post.postType.label(strings)}')),
      body: Column(children: [
        Expanded(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 20),
            children: [
              GestureDetector(
                onTap: post.official ? null : () => _openAuthorProfile(context),
                child: Row(children: [
                  CircleAvatar(
                      radius: 18,
                      backgroundColor:
                          post.official ? ArucadColors.primary : ArucadColors.mist,
                      child: post.official
                          ? const Icon(Icons.school_outlined, size: 18, color: Colors.white)
                          : Text(post.name.isEmpty ? '?' : post.name.substring(0, 1))),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(post.name, style: const TextStyle(fontWeight: FontWeight.w900)),
                      Text(post.meta,
                          style: const TextStyle(color: ArucadColors.muted, fontSize: 12)),
                    ]),
                  ),
                ]),
              ),
              const SizedBox(height: 14),
              if (post.text.isNotEmpty)
                Text(post.text, style: const TextStyle(fontSize: 16, height: 1.4)),
              if (post.imageBytes != null) ...[
                const SizedBox(height: 12),
                ClipRRect(
                  borderRadius: BorderRadius.circular(16),
                  child: Image.memory(post.imageBytes!, fit: BoxFit.cover, width: double.infinity),
                ),
              ] else if (post.imageUrl != null) ...[
                const SizedBox(height: 12),
                ClipRRect(
                  borderRadius: BorderRadius.circular(16),
                  child: Image.network(post.imageUrl!, fit: BoxFit.cover, width: double.infinity),
                ),
              ],
              if (post.locationTag != null || post.courseTag != null) ...[
                const SizedBox(height: 10),
                Wrap(spacing: 8, runSpacing: 8, children: [
                  if (post.locationTag != null)
                    _TagChip(icon: Icons.place_outlined, label: post.locationTag!),
                  if (post.courseTag != null)
                    _TagChip(icon: Icons.menu_book_outlined, label: post.courseTag!),
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
                              color: ArucadColors.primary, fontWeight: FontWeight.w700)),
                  ],
                ),
              ],
              const SizedBox(height: 16),
              Row(children: [
                InkWell(
                  onTap: _like,
                  borderRadius: BorderRadius.circular(999),
                  child: Row(children: [
                    Icon(post.likedByMe ? Icons.favorite : Icons.favorite_border,
                        size: 22, color: post.likedByMe ? ArucadColors.primary : null),
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
                      size: 22, color: _saved ? ArucadColors.primary : null),
                ),
              ]),
              const Divider(height: 32),
              Text('${strings.t('social_comments_label')} (${post.comments.length})',
                  style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 15)),
              const SizedBox(height: 10),
              if (post.comments.isEmpty)
                Text(strings.t('social_no_comments_yet'),
                    style: const TextStyle(color: ArucadColors.muted))
              else
                for (final c in post.comments)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 14),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(c.author, style: const TextStyle(fontWeight: FontWeight.w800)),
                      const SizedBox(height: 2),
                      Text(c.text),
                      const SizedBox(height: 2),
                      Text(c.meta,
                          style: const TextStyle(color: ArucadColors.muted, fontSize: 11)),
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
                  decoration: InputDecoration(hintText: strings.t('social_write_comment')),
                  onSubmitted: (_) => _addComment(),
                ),
              ),
              IconButton(
                onPressed: _addComment,
                icon: const Icon(Icons.send, color: ArucadColors.primary),
              ),
            ]),
          ),
        ),
      ]),
    );
  }
}

class _TagChip extends StatelessWidget {
  final IconData icon;
  final String label;
  const _TagChip({required this.icon, required this.label});

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        decoration: BoxDecoration(
            color: ArucadColors.mist, borderRadius: BorderRadius.circular(999)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, size: 14, color: ArucadColors.muted),
          const SizedBox(width: 5),
          Text(label, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
        ]),
      );
}
