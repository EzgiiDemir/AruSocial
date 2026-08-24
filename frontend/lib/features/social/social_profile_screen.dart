import 'dart:convert';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/auth/app_settings_store.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/photo_picker_service.dart';
import 'package:arucad_campus_prototype/core/services/profile_bio_store.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/social/chat_screen.dart';
import 'package:arucad_campus_prototype/features/social/post_detail_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

enum _ProfileSection { posts, locations }

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

  const SocialProfileScreen({super.key, required this.repository, this.viewedUserName});

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
  Set<String> _following = {};
  int _visibleCount = kPageSize;
  _ProfileSection _section = _ProfileSection.posts;
  String? _selectedLocation;

  String get _displayName => isOwn ? (_me?.name ?? '') : (widget.viewedUserName ?? '');

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final futures = <Future>[
      widget.repository.getFeed(),
      widget.repository.getLeaderboard(),
      widget.repository.getFollowing(),
    ];
    if (isOwn) {
      futures.addAll([
        widget.repository.getMe(),
        AppSettingsStore.avatarUrl(),
        ProfileBioStore.load(),
      ]);
    }
    final results = await Future.wait(futures);
    if (!mounted) return;

    final feed = results[0] as List<FeedPost>;
    final leaderboard = results[1] as List<LeaderboardEntry>;
    final following = results[2] as Set<String>;

    CampusUser? me;
    if (isOwn) {
      final baseUser = results[3] as CampusUser;
      final avatarUrl = results[4] as String?;
      final edits = results[5] as ProfileBioEdits?;
      me = edits == null
          ? baseUser.copyWith(avatarUrl: avatarUrl)
          : baseUser.copyWith(
              avatarUrl: avatarUrl,
              department: edits.department,
              year: edits.year,
              university: edits.university,
              clubs: edits.clubs,
              achievements: edits.achievements,
              projects: edits.projects,
            );
    }

    final name = isOwn ? me!.name : widget.viewedUserName!;
    setState(() {
      _me = me;
      _avatarOverride = me?.avatarUrl;
      _peer = leaderboard.where((e) => e.name == name).firstOrNull;
      _posts = feed.where((p) => !p.official && p.name == name).toList();
      _following = following;
      _loading = false;
    });
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
            const Text('Fotoğrafı değiştir',
                style: TextStyle(fontWeight: FontWeight.w900, fontSize: 18)),
            const SizedBox(height: 14),
            OutlinedButton.icon(
              onPressed: () => Navigator.of(ctx).pop('__pick__'),
              icon: const Icon(Icons.add_a_photo_outlined),
              label: const Text('Kameradan çek / Galeriden seç'),
            ),
          ],
        ),
      ),
    );
    if (choice != '__pick__' || !mounted) return;
    final bytes = await PhotoPickerService.pick(context, imageQuality: 70, maxWidth: 480);
    if (bytes == null) return;
    final dataUri = 'data:image/jpeg;base64,${base64Encode(bytes)}';
    await AppSettingsStore.setAvatarUrl(dataUri);
    if (mounted) setState(() => _avatarOverride = dataUri);
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
              const Text('Profili Düzenle',
                  style: TextStyle(fontWeight: FontWeight.w900, fontSize: 18)),
              const SizedBox(height: 16),
              TextField(
                  controller: departmentC,
                  decoration: const InputDecoration(labelText: 'Bölüm')),
              const SizedBox(height: 10),
              TextField(
                  controller: yearC, decoration: const InputDecoration(labelText: 'Sınıf')),
              const SizedBox(height: 10),
              TextField(
                  controller: universityC,
                  decoration: const InputDecoration(labelText: 'Üniversite')),
              const SizedBox(height: 10),
              TextField(
                controller: clubsC,
                maxLines: 3,
                decoration: const InputDecoration(
                    labelText: 'Kulüpler (her satıra bir tane)'),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: achievementsC,
                maxLines: 3,
                decoration: const InputDecoration(
                    labelText: 'Başarılar (her satıra bir tane)'),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: projectsC,
                maxLines: 3,
                decoration: const InputDecoration(
                    labelText: 'Projeler (her satıra bir tane)'),
              ),
              const SizedBox(height: 16),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: () => Navigator.of(ctx).pop(true),
                  child: const Text('Kaydet'),
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
    await ProfileBioStore.save(edits);
    if (!mounted) return;
    setState(() {
      _me = me.copyWith(
        department: edits.department,
        year: edits.year,
        university: edits.university,
        clubs: edits.clubs,
        achievements: edits.achievements,
        projects: edits.projects,
      );
    });
  }

  void _showFollowingList() {
    showModalBottomSheet<void>(
      context: context,
      builder: (ctx) => SafeArea(
        child: _following.isEmpty
            ? const Padding(
                padding: EdgeInsets.all(24),
                child: Text('Henüz kimseyi takip etmiyorsun.',
                    style: TextStyle(color: ArucadColors.muted)),
              )
            : ListView(
                shrinkWrap: true,
                padding: const EdgeInsets.symmetric(vertical: 8),
                children: [
                  for (final name in _following)
                    ListTile(
                      leading: CircleAvatar(
                          backgroundColor: ArucadColors.mist,
                          child: Text(name.isEmpty ? '?' : name.substring(0, 1))),
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
    final locations = <String, int>{};
    for (final p in _posts) {
      if (p.locationTag != null) {
        locations[p.locationTag!] = (locations[p.locationTag!] ?? 0) + 1;
      }
    }
    final visiblePosts = _section == _ProfileSection.locations && _selectedLocation != null
        ? _posts.where((p) => p.locationTag == _selectedLocation).toList()
        : _posts;
    final shown = _visibleCount.clamp(0, visiblePosts.length);

    return Scaffold(
      appBar: AppBar(title: Text(_displayName)),
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
                  _StatColumn(label: 'Gönderi', value: '${_posts.length}'),
                  const SizedBox(width: 18),
                  const _StatColumn(label: 'Takipçi', value: '0'),
                  if (isOwn) ...[
                    const SizedBox(width: 18),
                    GestureDetector(
                      onTap: _showFollowingList,
                      child: _StatColumn(label: 'Takip', value: '${_following.length}'),
                    ),
                  ],
                ]),
              ),
            ]),
            const SizedBox(height: 14),
            if (!isOwn && _peer?.department != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Text(_peer!.department!,
                    style: const TextStyle(color: ArucadColors.muted, fontWeight: FontWeight.w600)),
              ),
            Row(children: [
              if (isOwn)
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _editProfile,
                    icon: const Icon(Icons.edit_outlined, size: 16),
                    label: const Text('Profili Düzenle'),
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
                    icon: const Icon(Icons.chat_bubble_outline, size: 16),
                    label: const Text('Mesaj'),
                  ),
                ),
              ],
            ]),
            if (isOwn && _me != null) ...[
              const SizedBox(height: 16),
              _StudentBioCard(user: _me!),
            ],
            const SizedBox(height: 18),
            Row(children: [
              Expanded(
                child: SelectableChip(
                  label: 'Gönderiler',
                  selected: _section == _ProfileSection.posts,
                  onSelected: (_) => setState(() => _section = _ProfileSection.posts),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: SelectableChip(
                  label: 'Konumlar',
                  selected: _section == _ProfileSection.locations,
                  onSelected: (_) => setState(() => _section = _ProfileSection.locations),
                ),
              ),
            ]),
            const SizedBox(height: 14),
            if (_section == _ProfileSection.locations && locations.isNotEmpty) ...[
              Wrap(spacing: 8, runSpacing: 8, children: [
                SelectableChip(
                  label: 'Tümü',
                  selected: _selectedLocation == null,
                  onSelected: (_) => setState(() => _selectedLocation = null),
                ),
                for (final entry in locations.entries)
                  SelectableChip(
                    label: '${entry.key} (${entry.value})',
                    selected: _selectedLocation == entry.key,
                    onSelected: (_) => setState(() => _selectedLocation = entry.key),
                  ),
              ]),
              const SizedBox(height: 14),
            ],
            if (_section == _ProfileSection.locations && locations.isEmpty)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 24),
                child: Center(
                    child: Text('Konum etiketli gönderi yok.',
                        style: TextStyle(color: ArucadColors.muted))),
              )
            else if (visiblePosts.isEmpty)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 24),
                child: Center(
                    child: Text('Henüz gönderi yok.',
                        style: TextStyle(color: ArucadColors.muted))),
              )
            else ...[
              GridView.builder(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                itemCount: shown,
                gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                    crossAxisCount: 3, crossAxisSpacing: 6, mainAxisSpacing: 6),
                itemBuilder: (context, i) {
                  final post = visiblePosts[i];
                  final image = post.imageBytes;
                  return GestureDetector(
                    onTap: () => Navigator.of(context).push(MaterialPageRoute(
                        builder: (_) =>
                            PostDetailScreen(post: post, repository: widget.repository))),
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(10),
                      child: image != null
                          ? Image.memory(image, fit: BoxFit.cover)
                          : Container(
                              color: ArucadColors.mist,
                              padding: const EdgeInsets.all(8),
                              alignment: Alignment.center,
                              child: Text(post.text,
                                  maxLines: 4,
                                  overflow: TextOverflow.ellipsis,
                                  textAlign: TextAlign.center,
                                  style: const TextStyle(fontSize: 10.5)),
                            ),
                    ),
                  );
                },
              ),
              if (shown < visiblePosts.length)
                Padding(
                  padding: const EdgeInsets.only(top: 14),
                  child: LoadMoreButton(
                    shown: shown,
                    total: visiblePosts.length,
                    itemLabel: 'gönderi',
                    onTap: () => setState(() => _visibleCount += kPageSize),
                  ),
                ),
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

class _Avatar extends StatefulWidget {
  final String name;
  final String? avatarUrl;
  const _Avatar({required this.name, required this.avatarUrl});

  @override
  State<_Avatar> createState() => _AvatarState();
}

class _AvatarState extends State<_Avatar> {
  bool _failed = false;

  @override
  void didUpdateWidget(covariant _Avatar oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.avatarUrl != widget.avatarUrl) _failed = false;
  }

  @override
  Widget build(BuildContext context) {
    final initials = widget.name.isEmpty ? '?' : widget.name.substring(0, 1);
    final fallback = CircleAvatar(
      radius: 36,
      backgroundColor: ArucadColors.primary,
      child: Text(initials,
          style:
              const TextStyle(color: Colors.white, fontSize: 28, fontWeight: FontWeight.w900)),
    );
    final url = widget.avatarUrl;
    if (url == null || _failed) return fallback;
    if (url.startsWith('data:')) {
      try {
        final bytes = base64Decode(url.split(',').last);
        return ClipOval(child: Image.memory(bytes, width: 72, height: 72, fit: BoxFit.cover));
      } catch (_) {
        return fallback;
      }
    }
    return ClipOval(
      child: Image.network(
        url,
        width: 72,
        height: 72,
        fit: BoxFit.cover,
        errorBuilder: (_, __, ___) {
          WidgetsBinding.instance.addPostFrameCallback((_) => setState(() => _failed = true));
          return fallback;
        },
      ),
    );
  }
}

/// The "Öğrenci Kimliği" bio block — real CampusUser fields, not decorative.
class _StudentBioCard extends StatelessWidget {
  final CampusUser user;
  const _StudentBioCard({required this.user});

  @override
  Widget build(BuildContext context) => Card(
        child: Padding(
          padding: const EdgeInsets.all(18),
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
              const Text('Başarılar',
                  style: TextStyle(fontWeight: FontWeight.w800, fontSize: 12.5)),
              const SizedBox(height: 4),
              for (final a in user.achievements) _BioLine(emoji: '🏆', text: a),
            ],
            if (user.projects.isNotEmpty) ...[
              const SizedBox(height: 4),
              const Text('Projeler',
                  style: TextStyle(fontWeight: FontWeight.w800, fontSize: 12.5)),
              const SizedBox(height: 4),
              for (final p in user.projects) _BioLine(emoji: '💻', text: p),
            ],
          ]),
        ),
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
