import 'package:collection/collection.dart';
import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/auth/app_settings_store.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/content_moderation.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/photo_picker_service.dart';
import 'package:arucad_campus_prototype/core/services/profile_bio_store.dart';
import 'package:arucad_campus_prototype/core/services/upload_rules.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/social/chat_screen.dart';
import 'package:arucad_campus_prototype/features/social/post_detail_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/moderation_notice.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_avatar.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_back_button.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_network_image.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

enum _ProfileSection { posts, saved, archives, locations }

/// One screen, two modes: the signed-in student's own profile (editable,
/// full bio) when [viewedUserName] is null, or a read-only view of a
/// classmate's profile (Follow/Message, no edit access) when it's set —
/// Instagram's own "your profile vs. someone else's" split. Follower counts
/// are real, not invented: nobody has ever actually followed anyone in this
/// single-device prototype (see `SocialGraphStore`'s scope), so they're
/// honestly 0 rather than a fabricated number.
class SocialProfileScreen extends StatefulWidget {
  final CampusRepository repository;
  final String? viewedUserName;

  /// When true (own profile inside SocialShell mobile), title sits next to ☰.
  final bool titleInShell;

  const SocialProfileScreen({
    super.key,
    required this.repository,
    this.viewedUserName,
    this.titleInShell = false,
  });

  @override
  State<SocialProfileScreen> createState() => _SocialProfileScreenState();
}

class _SocialProfileScreenState extends State<SocialProfileScreen> {
  bool get isOwn => widget.viewedUserName == null;

  bool _loading = true;
  CampusUser? _me;
  LeaderboardEntry? _peer;
  String? _avatarOverride;
  List<FeedPost> _posts = const [];
  List<ActivityItem> _checkIns = const [];
  Set<String> _following = {};
  Set<String> _savedIds = {};
  List<FeedPost> _allFeed = const [];
  int _visibleCount = kPageSize;
  _ProfileSection _section = _ProfileSection.posts;
  bool _locked = false;
  CampusUser? _viewedUser;
  int _followerCount = 0;

  String get _displayName => isOwn ? (_me?.name ?? '') : (widget.viewedUserName ?? '');

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
    final futures = <Future>[
      widget.repository.getFeed(),
      widget.repository.getLeaderboard(),
      widget.repository.getFollowing(),
      widget.repository.getSavedPostIds(),
    ];
    if (isOwn) {
      futures.addAll([
        widget.repository.getMe(),
        AppSettingsStore.avatarUrl(),
        widget.repository.getMyActivity(),
        widget.repository.getFollowers(),
      ]);
    }
    final results = await Future.wait(futures);
    if (!mounted || !context.mounted) return;

    final feed = results[0] as List<FeedPost>;
    final leaderboard = results[1] as List<LeaderboardEntry>;
    final following = results[2] as Set<String>;
    final savedIds = results[3] as Set<String>;

    CampusUser? me;
    List<ActivityItem> checkIns = const [];
    CampusUser? viewed;
    var locked = false;
    var followerCount = 0;
    if (isOwn) {
      final baseUser = results[4] as CampusUser;
      final avatarUrl = results[5] as String?;
      checkIns = (results[6] as List<ActivityItem>)
          .where((a) => a.kind == ActivityKind.checkIn)
          .toList()
        ..sort((a, b) => b.timestamp.compareTo(a.timestamp));
      me = baseUser.copyWith(avatarUrl: avatarUrl ?? baseUser.avatarUrl);
      followerCount = (results[7] as Set<String>).length;
    } else {
      viewed = await widget.repository.getSocialUser(widget.viewedUserName!);
      locked = viewed?.isLocked == true;
      followerCount = viewed?.followerCount ?? 0;
    }

    final name = isOwn ? me!.name : widget.viewedUserName!;
    setState(() {
      _me = me;
      _viewedUser = viewed;
      _locked = locked;
      _followerCount = followerCount;
      _avatarOverride = me?.avatarUrl ?? viewed?.avatarUrl;
      _peer = leaderboard.where((e) => e.name == name).firstOrNull;
      _allFeed = feed;
      _savedIds = savedIds;
      _posts = locked
          ? const []
          : feed.where((p) => !p.official && p.name == name).toList();
      _checkIns = checkIns;
      _following = following;
      _loading = false;
    });
    } catch (_) {
      if (!mounted || !context.mounted) return;
      setState(() => _loading = false);
    }
  }

  Future<void> _editOwnPost(FeedPost post) async {
    final textC = TextEditingController(text: post.text);
    final newText = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(AppLocale.of(context).t('sp_edit_post')),
        content: TextField(controller: textC, maxLines: 5, autofocus: true),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx), child: Text(AppLocale.of(context).t('act_cancel'))),
          FilledButton(
              onPressed: () => Navigator.pop(ctx, textC.text.trim()),
              child: Text(AppLocale.of(context).t('act_save'))),
        ],
      ),
    );
    if (newText == null || newText.isEmpty || newText == post.text) return;
    try {
      await widget.repository.updatePost(post.id, text: newText);
      if (!mounted || !context.mounted) return;
      await _load();
    } on ContentModerationException catch (e) {
      if (!mounted || !context.mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(e.reason)));
    }
  }

  Future<void> _deleteOwnPost(FeedPost post) async {
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
    await widget.repository.deletePost(post.id);
    if (!mounted || !context.mounted) return;
    await _load();
  }

  Future<void> _archiveOwnPost(FeedPost post) async {
    try {
      await widget.repository
          .updatePost(post.id, visibility: PostVisibility.onlyMe);
      if (!mounted || !context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(AppLocale.of(context).t('sp_archived'))));
      await _load();
    } catch (_) {
      if (!mounted || !context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(AppLocale.of(context).t('sp_archive_failed'))));
    }
  }

  Future<void> _unarchiveOwnPost(FeedPost post) async {
    try {
      await widget.repository
          .updatePost(post.id, visibility: PostVisibility.everyone);
      if (!mounted || !context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(AppLocale.of(context).t('sp_unarchived'))));
      await _load();
    } catch (_) {
      if (!mounted || !context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(AppLocale.of(context).t('sp_unarchive_failed'))));
    }
  }

  Future<void> _ownPostMenu(FeedPost post, {required bool fromArchive}) async {
    final action = await showModalBottomSheet<String>(
      context: context,
      builder: (ctx) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          if (fromArchive)
            ListTile(
              leading: const Icon(Icons.unarchive_outlined),
              title: Text(AppLocale.of(context).t('sp_unarchive')),
              onTap: () => Navigator.pop(ctx, 'unarchive'),
            )
          else
            ListTile(
              leading: Icon(Icons.archive_outlined),
              title: Text(AppLocale.of(context).t('sp_archive')),
              onTap: () => Navigator.pop(ctx, 'archive'),
            ),
          ListTile(
            leading: Icon(Icons.edit_outlined),
            title: Text(AppLocale.of(context).t('act_edit')),
            onTap: () => Navigator.pop(ctx, 'edit'),
          ),
          ListTile(
            leading: Icon(Icons.delete_outline, color: ArucadColors.danger),
            title: Text('Sil',
                style: TextStyle(color: ArucadColors.danger)),
            onTap: () => Navigator.pop(ctx, 'delete'),
          ),
        ]),
      ),
    );
    if (action == null || !mounted) return;
    switch (action) {
      case 'edit':
        await _editOwnPost(post);
      case 'delete':
        await _deleteOwnPost(post);
      case 'archive':
        await _archiveOwnPost(post);
      case 'unarchive':
        await _unarchiveOwnPost(post);
    }
  }

  Future<void> _toggleFollow() async {
    final name = _displayName;
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
      if (!mounted || !context.mounted) return;
      setState(() {
        if (nowFollowing) {
          _following.add(name);
        } else {
          _following.remove(name);
        }
      });
      if (!isOwn) await _load();
    } catch (_) {
      if (!mounted || !context.mounted) return;
      setState(() {
        if (wasFollowing) {
          _following.add(name);
        } else {
          _following.remove(name);
        }
      });
    }
  }

  Future<void> _changeAvatar() async {
    final choice = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => Padding(
        padding: const EdgeInsets.fromLTRB(20, 20, 20, 30),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(AppLocale.of(context).t('sp_change_photo'),
                style: TextStyle(fontWeight: FontWeight.w900, fontSize: 18)),
            SizedBox(height: 14),
            OutlinedButton.icon(
              onPressed: () => Navigator.of(ctx).pop('__pick__'),
              icon: Icon(Icons.add_a_photo_outlined),
              label: Text(AppLocale.of(context).t('sp_photo_source')),
            ),
          ],
        ),
      ),
    );
    if (choice != '__pick__' || !mounted) return;
    final bytes = await PhotoPickerService.pick(context, imageQuality: 70, maxWidth: 480);
    if (bytes == null) return;

    // Refused locally before spending an upload on it.
    final reason = UploadRules.rejectionReason(bytes, 'avatar.jpg');
    if (reason != null) {
      if (!mounted || !context.mounted) return;
      await showModerationNotice(context, message: reason);

      return;
    }

    try {
      final item =
          await widget.repository.uploadMyMedia(bytes, fileName: 'avatar.jpg');
      final remote = item.url;
      final persisted = (remote != null &&
              remote.isNotEmpty &&
              !remote.startsWith('data:'))
          ? remote
          : item.displaySrc;
      if (!persisted.startsWith('data:')) {
        await widget.repository.updateProfileBio(avatarUrl: persisted);
      }
      await AppSettingsStore.setAvatarUrl(persisted);
      if (mounted) setState(() => _avatarOverride = persisted);
    } catch (e) {
      if (!mounted || !context.mounted) return;
      if (await showModerationNoticeFor(context, e)) return;
      if (!mounted || !context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Profil fotoğrafı kaydedilemedi: $e')),
      );
    }
  }

  Future<void> _editProfile() async {
    final me = _me;
    if (me == null) return;
    final departmentC = TextEditingController(text: me.department ?? '');
    final yearC = TextEditingController(text: me.year ?? '');
    final universityC = TextEditingController(text: me.university ?? '');
    final clubsC = TextEditingController(text: me.clubs.join('\n'));
    final achievementsC = TextEditingController(text: me.achievements.join('\n'));
    final projectsC = TextEditingController(text: me.projects.join('\n'));

    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => Padding(
        padding: EdgeInsets.only(
            left: 20, right: 20, top: 20, bottom: MediaQuery.of(ctx).viewInsets.bottom + 20),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(AppLocale.of(context).t('sp_edit_profile'),
                  style: TextStyle(fontWeight: FontWeight.w900, fontSize: 18)),
              SizedBox(height: 16),
              TextField(
                  controller: departmentC,
                  decoration: InputDecoration(labelText: AppLocale.of(context).t('sp_department'))),
              SizedBox(height: 10),
              TextField(
                  controller: yearC, decoration: InputDecoration(labelText: AppLocale.of(context).t('sp_year'))),
              SizedBox(height: 10),
              TextField(
                  controller: universityC,
                  decoration: InputDecoration(labelText: AppLocale.of(context).t('sp_university'))),
              SizedBox(height: 10),
              TextField(
                controller: clubsC,
                maxLines: 3,
                decoration: InputDecoration(
                    labelText: AppLocale.of(context).t('sp_clubs_hint')),
              ),
              SizedBox(height: 10),
              TextField(
                controller: achievementsC,
                maxLines: 3,
                decoration: InputDecoration(
                    labelText: AppLocale.of(context).t('sp_achievements_hint')),
              ),
              SizedBox(height: 10),
              TextField(
                controller: projectsC,
                maxLines: 3,
                decoration: InputDecoration(
                    labelText: AppLocale.of(context).t('sp_projects_hint')),
              ),
              SizedBox(height: 16),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: () => Navigator.of(ctx).pop(true),
                  child: Text(AppLocale.of(context).t('act_save')),
                ),
              ),
            ],
          ),
        ),
      ),
    );
    if (saved != true) return;
    List<String> lines(TextEditingController c) =>
        c.text.split('\n').map((s) => s.trim()).where((s) => s.isNotEmpty).toList();
    final edits = ProfileBioEdits(
      department: departmentC.text.trim().isEmpty ? null : departmentC.text.trim(),
      year: yearC.text.trim().isEmpty ? null : yearC.text.trim(),
      university: universityC.text.trim().isEmpty ? null : universityC.text.trim(),
      clubs: lines(clubsC),
      achievements: lines(achievementsC),
      projects: lines(projectsC),
    );
    final updated = await widget.repository.updateProfileBio(
      department: edits.department,
      year: edits.year,
      university: edits.university,
      clubs: edits.clubs,
      achievements: edits.achievements,
      projects: edits.projects,
    );
    if (!mounted || !context.mounted) return;
    setState(() {
      _me = updated.copyWith(avatarUrl: _avatarOverride ?? updated.avatarUrl);
    });
  }

  void _showFollowingList() {
    showModalBottomSheet<void>(
      context: context,
      builder: (ctx) => SafeArea(
        child: _following.isEmpty
            ? Padding(
                padding: EdgeInsets.all(24),
                child: Text(AppLocale.of(context).t('sp_following_empty'),
                    style: TextStyle(color: ArucadColors.muted)),
              )
            : ListView(
                shrinkWrap: true,
                padding: const EdgeInsets.symmetric(vertical: 8),
                children: [
                  for (final name in _following)
                    ListTile(
                      leading: CampusAvatar(name: name, radius: 18),
                      title: Text(name, maxLines: 1, overflow: TextOverflow.ellipsis),
                      onTap: () {
                        Navigator.of(ctx).pop();
                        Navigator.of(context).push(MaterialPageRoute(
                            builder: (_) => SocialProfileScreen(
                                repository: widget.repository, viewedUserName: name)));
                      },
                    ),
                ],
              ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    final publicPostCount =
        _posts.where((p) => p.visibility != PostVisibility.onlyMe).length;

    return Scaffold(
      backgroundColor: Theme.of(context).scaffoldBackgroundColor,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 0,
        title: (widget.titleInShell && isOwn)
            ? null
            : Text(_displayName),
        leading: !isOwn ? const CampusBackButton() : null,
        actions: [
          if (!isOwn)
            PopupMenuButton<String>(
              onSelected: (v) async {
                if (v == 'block') {
                  await widget.repository.toggleBlock(_displayName);
                  if (!mounted || !context.mounted) return;
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(content: Text('$_displayName engellendi / engel kaldırıldı')),
                  );
                } else if (v == 'report') {
                  final ok = await showDialog<bool>(
                    context: context,
                    builder: (ctx) => AlertDialog(
                      title: Text(AppLocale.of(context).t('act_report')),
                      content: Text(
                          '$_displayName kullanıcısını şikayet etmek istiyor musun?'),
                      actions: [
                        TextButton(
                            onPressed: () => Navigator.pop(ctx, false),
                            child: Text(AppLocale.of(context).t('act_cancel'))),
                        FilledButton(
                            onPressed: () => Navigator.pop(ctx, true),
                            child: Text(AppLocale.of(context).t('act_report'))),
                      ],
                    ),
                  );
                  if (ok == true && mounted) {
                    try {
                      await widget.repository
                          .reportUser(_displayName, 'Kullanıcı şikayeti');
                    } catch (_) {
                      if (!context.mounted) return;
                      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                          content: Text(AppLocale.of(context).t('act_report_failed'))));
                      return;
                    }
                    if (!mounted || !context.mounted) return;
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                        content: Text(AppLocale.of(context).t('act_report_sent'))));
                  }
                }
              },
              itemBuilder: (context) => [
                PopupMenuItem(
                    value: 'block',
                    child: Text(AppLocale.of(context).t('sp_block_toggle'))),
                PopupMenuItem(
                    value: 'report',
                    child: Text(AppLocale.of(context).t('act_report'))),
              ],
            ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 32),
          children: [
            Row(children: [
              GestureDetector(
                onTap: isOwn ? _changeAvatar : null,
                child: Stack(children: [
                  _Avatar(name: _displayName, avatarUrl: _avatarOverride),
                  if (isOwn)
                    Positioned(
                      right: -2,
                      bottom: -2,
                      child: Container(
                        padding: const EdgeInsets.all(4),
                        decoration: const BoxDecoration(
                            color: ArucadColors.primary, shape: BoxShape.circle),
                        child: const Icon(Icons.edit, size: 12, color: Colors.white),
                      ),
                    ),
                ]),
              ),
              const SizedBox(width: 20),
              Expanded(
                child: Row(children: [
                  _StatColumn(label: AppLocale.of(context).t('sp_posts_count'), value: '$publicPostCount'),
                  SizedBox(width: 18),
                  _StatColumn(label: AppLocale.of(context).t('sp_followers'), value: '$_followerCount'),
                  if (isOwn) ...[
                    SizedBox(width: 18),
                    GestureDetector(
                      onTap: _showFollowingList,
                      child: _StatColumn(label: 'Takip', value: '${_following.length}'),
                    ),
                  ],
                ]),
              ),
            ]),
            if (_locked) ...[
              const SizedBox(height: 14),
              Card(
                color: ArucadColors.navy,
                child: Padding(
                  padding: EdgeInsets.all(16),
                  child: Row(children: [
                    Icon(Icons.lock_outline, color: Colors.white),
                    SizedBox(width: 12),
                    Expanded(
                      child: Text(AppLocale.of(context).t('sp_private'),
                        style: TextStyle(color: Colors.white, fontWeight: FontWeight.w600),
                      ),
                    ),
                  ]),
                ),
              ),
            ],
            const SizedBox(height: 14),
            if (!_locked && !isOwn && (_peer?.department != null || _viewedUser?.department != null))
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Text(_peer?.department ?? _viewedUser!.department!,
                    style: TextStyle(color: ArucadColors.muted, fontWeight: FontWeight.w600)),
              ),
            Row(children: [
              if (isOwn)
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _editProfile,
                    icon: Icon(Icons.edit_outlined, size: 16),
                    label: Text(AppLocale.of(context).t('sp_edit_profile')),
                  ),
                )
              else ...[
                Expanded(
                  child: FilledButton.icon(
                    onPressed: _toggleFollow,
                    style: _following.contains(_displayName)
                        ? FilledButton.styleFrom(
                            backgroundColor: ArucadColors.mist, foregroundColor: ArucadColors.ink)
                        : null,
                    icon: Icon(
                        _following.contains(_displayName)
                            ? Icons.check
                            : Icons.person_add_alt_1_outlined,
                        size: 16),
                    label: Text(_following.contains(_displayName) ? 'Takipte' : 'Takip Et'),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () => Navigator.of(context).push(MaterialPageRoute(
                        builder: (_) =>
                            ChatThreadScreen(repository: widget.repository, peer: _displayName))),
                    icon: Icon(Icons.chat_bubble_outline, size: 16),
                    label: Text('Mesaj'),
                  ),
                ),
              ],
            ]),
            if (isOwn && _me != null) ...[
              const SizedBox(height: 8),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(AppLocale.of(context).t('profile_private')),
                subtitle: Text(AppLocale.of(context).t('profile_private_sub')),
                value: _me!.isPrivateProfile,
                onChanged: (v) async {
                  try {
                    await widget.repository.updateUserSettings(isPrivateProfile: v);
                    if (!mounted || !context.mounted) return;
                    setState(() => _me = _me!.copyWith(isPrivateProfile: v));
                  } catch (e) {
                    if (!context.mounted) return;
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(content: Text('Ayar kaydedilemedi: $e')),
                    );
                  }
                },
              ),
            ],
            if (isOwn && _me != null) ...[
              const SizedBox(height: 16),
              _StudentBioCard(user: _me!),
            ],
            const SizedBox(height: 18),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                SelectableChip(
                  label: AppLocale.of(context).t('sp_posts_tab'),
                  selected: _section == _ProfileSection.posts,
                  onSelected: (_) => setState(() {
                    _section = _ProfileSection.posts;
                    _visibleCount = kPageSize;
                  }),
                ),
                if (isOwn) ...[
                  SelectableChip(
                    label: 'Kaydedilenler',
                    selected: _section == _ProfileSection.saved,
                    onSelected: (_) => setState(() {
                      _section = _ProfileSection.saved;
                      _visibleCount = kPageSize;
                    }),
                  ),
                  SelectableChip(
                    label: AppLocale.of(context).t('sp_archive_tab'),
                    selected: _section == _ProfileSection.archives,
                    onSelected: (_) => setState(() {
                      _section = _ProfileSection.archives;
                      _visibleCount = kPageSize;
                    }),
                  ),
                ],
                SelectableChip(
                  label: 'Konumlar',
                  selected: _section == _ProfileSection.locations,
                  onSelected: (_) => setState(() {
                    _section = _ProfileSection.locations;
                    _visibleCount = kPageSize;
                  }),
                ),
              ],
            ),
            const SizedBox(height: 14),
            if (_locked && !isOwn)
              const SizedBox.shrink()
            else if (_section == _ProfileSection.locations) ...[
              if (!isOwn || _checkIns.isEmpty)
                Padding(
                  padding: EdgeInsets.symmetric(vertical: 24),
                  child: Center(
                      child: Text(AppLocale.of(context).t('sp_no_checkins'),
                          style: TextStyle(color: ArucadColors.muted))),
                )
              else
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    for (final checkIn in _checkIns)
                      Chip(
                        avatar: const Icon(Icons.location_on_outlined, size: 16),
                        label: Text(checkIn.title.replaceFirst('Check-in: ', '')),
                        labelStyle: const TextStyle(fontSize: 12.5),
                      ),
                  ],
                ),
            ] else if (isOwn && _section == _ProfileSection.saved) ...[
              Builder(builder: (context) {
                final saved = _allFeed
                    .where((p) => _savedIds.contains(p.id))
                    .toList();
                if (saved.isEmpty) {
                  return Padding(
                    padding: EdgeInsets.symmetric(vertical: 24),
                    child: Center(
                        child: Text(AppLocale.of(context).t('sp_no_saved'),
                            style: TextStyle(color: ArucadColors.muted))),
                  );
                }
                return GridView.builder(
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  itemCount: saved.length,
                  gridDelegate:
                      const SliverGridDelegateWithFixedCrossAxisCount(
                          crossAxisCount: 3,
                          crossAxisSpacing: 6,
                          mainAxisSpacing: 6),
                  itemBuilder: (context, i) {
                    final post = saved[i];
                    final image = post.imageBytes;
                    return GestureDetector(
                      onTap: () => Navigator.of(context).push(
                          MaterialPageRoute(
                              builder: (_) => PostDetailScreen(
                                  post: post,
                                  repository: widget.repository))),
                      child: ClipRRect(
                        borderRadius: BorderRadius.circular(10),
                        child: image != null
                            ? Image.memory(image, fit: BoxFit.cover)
                            : post.imageUrl != null
                                ? CampusNetworkImage(post.imageUrl!,
                                    fit: BoxFit.cover)
                                : Container(
                                    color: ArucadColors.mist,
                                    padding: const EdgeInsets.all(8),
                                    alignment: Alignment.center,
                                    child: Text(post.displayText,
                                        maxLines: 4,
                                        overflow: TextOverflow.ellipsis,
                                        style: const TextStyle(fontSize: 11)),
                                  ),
                      ),
                    );
                  },
                );
              }),
            ] else ...[
              Builder(builder: (context) {
                final fromArchive = isOwn && _section == _ProfileSection.archives;
                final gridPosts = fromArchive
                    ? _posts
                        .where((p) => p.visibility == PostVisibility.onlyMe)
                        .toList()
                    : _posts
                        .where((p) => p.visibility != PostVisibility.onlyMe)
                        .toList();
                final shown = _visibleCount.clamp(0, gridPosts.length);
                if (gridPosts.isEmpty) {
                  return Padding(
                    padding: const EdgeInsets.symmetric(vertical: 24),
                    child: Center(
                        child: Text(
                            fromArchive
                                ? 'Henüz arşivlenmiş gönderi yok.'
                                : 'Henüz gönderi yok.',
                            style: const TextStyle(color: ArucadColors.muted))),
                  );
                }
                return Column(
                  children: [
                    GridView.builder(
                      shrinkWrap: true,
                      physics: const NeverScrollableScrollPhysics(),
                      itemCount: shown,
                      gridDelegate:
                          const SliverGridDelegateWithFixedCrossAxisCount(
                              crossAxisCount: 3,
                              crossAxisSpacing: 6,
                              mainAxisSpacing: 6),
                      itemBuilder: (context, i) {
                        final post = gridPosts[i];
                        final image = post.imageBytes;
                        final tile = ClipRRect(
                          borderRadius: BorderRadius.circular(10),
                          child: image != null
                              ? Image.memory(image, fit: BoxFit.cover)
                              : post.imageUrl != null
                                  ? CampusNetworkImage(post.imageUrl!,
                                      fit: BoxFit.cover)
                                  : Container(
                                      color: ArucadColors.mist,
                                      padding: const EdgeInsets.all(8),
                                      alignment: Alignment.center,
                                      child: Text(post.displayText,
                                          maxLines: 4,
                                          overflow: TextOverflow.ellipsis,
                                          textAlign: TextAlign.center,
                                          style: const TextStyle(fontSize: 10.5)),
                                    ),
                        );
                        if (!isOwn) {
                          return GestureDetector(
                            onTap: () => Navigator.of(context).push(
                                MaterialPageRoute(
                                    builder: (_) => PostDetailScreen(
                                        post: post,
                                        repository: widget.repository))),
                            child: tile,
                          );
                        }
                        return GestureDetector(
                          onTap: () => Navigator.of(context).push(
                              MaterialPageRoute(
                                  builder: (_) => PostDetailScreen(
                                      post: post,
                                      repository: widget.repository))),
                          onLongPress: () =>
                              _ownPostMenu(post, fromArchive: fromArchive),
                          child: Stack(
                            fit: StackFit.expand,
                            children: [
                              tile,
                              Positioned(
                                top: 2,
                                right: 2,
                                child: Material(
                                  color: Colors.black45,
                                  shape: const CircleBorder(),
                                  child: PopupMenuButton<String>(
                                    padding: EdgeInsets.zero,
                                    iconSize: 18,
                                    icon: const Icon(Icons.more_vert,
                                        color: Colors.white, size: 16),
                                    onSelected: (v) {
                                      switch (v) {
                                        case 'edit':
                                          _editOwnPost(post);
                                        case 'delete':
                                          _deleteOwnPost(post);
                                        case 'archive':
                                          _archiveOwnPost(post);
                                        case 'unarchive':
                                          _unarchiveOwnPost(post);
                                      }
                                    },
                                    itemBuilder: (_) => [
                                      if (fromArchive)
                                        PopupMenuItem(
                                            value: 'unarchive',
                                            child: Text(AppLocale.of(context).t('sp_unarchive')))
                                      else
                                        PopupMenuItem(
                                            value: 'archive',
                                            child: Text(AppLocale.of(context).t('sp_archive'))),
                                      PopupMenuItem(
                                          value: 'edit',
                                          child: Text(AppLocale.of(context).t('act_edit'))),
                                      const PopupMenuItem(
                                          value: 'delete',
                                          child: Text('Sil',
                                              style: TextStyle(
                                                  color:
                                                      ArucadColors.danger))),
                                    ],
                                  ),
                                ),
                              ),
                            ],
                          ),
                        );
                      },
                    ),
                    if (shown < gridPosts.length)
                      Padding(
                        padding: const EdgeInsets.only(top: 14),
                        child: LoadMoreButton(
                          shown: shown,
                          total: gridPosts.length,
                          itemLabel: 'gönderi',
                          onTap: () =>
                              setState(() => _visibleCount += kPageSize),
                        ),
                      ),
                  ],
                );
              }),
            ],
          ],
        ),
      ),
    );
  }
}
class _StatColumn extends StatelessWidget {
  final String label;
  final String value;
  const _StatColumn({required this.label, required this.value});

  @override
  Widget build(BuildContext context) => Column(mainAxisSize: MainAxisSize.min, children: [
        Text(value,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
        Text(label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(color: ArucadColors.muted, fontSize: 12)),
      ]);
}

class _Avatar extends StatelessWidget {
  final String name;
  final String? avatarUrl;
  const _Avatar({required this.name, required this.avatarUrl});

  @override
  Widget build(BuildContext context) =>
      CampusAvatar(name: name, avatarUrl: avatarUrl, radius: 36);
}

/// The "Öğrenci Kimliği" bio block — real CampusUser fields, not decorative.
class _StudentBioCard extends StatelessWidget {
  final CampusUser user;
  const _StudentBioCard({required this.user});

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.all(4),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            if (user.department != null) _BioLine(emoji: '🎓', text: user.department!),
            if (user.year != null || user.university != null)
              _BioLine(
                  emoji: '📚',
                  text: [
                    if (user.year != null) user.year,
                    if (user.university != null) user.university,
                  ].join(' · ')),
            if (user.clubs.isNotEmpty) _BioLine(emoji: '🏛️', text: user.clubs.join(' · ')),
            if (user.achievements.isNotEmpty) ...[
              const SizedBox(height: 4),
              Text(AppLocale.of(context).t('sp_achievements'),
                  style: TextStyle(
                      fontWeight: FontWeight.w800,
                      fontSize: 12.5,
                      color: Theme.of(context).colorScheme.onSurface)),
              const SizedBox(height: 4),
              for (final a in user.achievements) _BioLine(emoji: '🏆', text: a),
            ],
            if (user.projects.isNotEmpty) ...[
              const SizedBox(height: 4),
              Text('Projeler',
                  style: TextStyle(
                      fontWeight: FontWeight.w800,
                      fontSize: 12.5,
                      color: Theme.of(context).colorScheme.onSurface)),
              const SizedBox(height: 4),
              for (final p in user.projects) _BioLine(emoji: '💻', text: p),
            ],
          ]),
      );
}

class _BioLine extends StatelessWidget {
  final String emoji;
  final String text;
  const _BioLine({required this.emoji, required this.text});

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(emoji, style: const TextStyle(fontSize: 14)),
          const SizedBox(width: 8),
          Expanded(child: Text(text, style: const TextStyle(fontSize: 13.5))),
        ]),
      );
}
