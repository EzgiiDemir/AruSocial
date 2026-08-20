import 'dart:convert';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/auth/app_settings_store.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/photo_picker_service.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/home/campus_live_map.dart';
import 'package:arucad_campus_prototype/features/onboarding/new_student_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

const _presetAvatars = [
  'https://api.dicebear.com/7.x/notionists/png?seed=Aslan&size=200',
  'https://api.dicebear.com/7.x/notionists/png?seed=Deniz&size=200',
  'https://api.dicebear.com/7.x/notionists/png?seed=Kiraz&size=200',
  'https://api.dicebear.com/7.x/notionists/png?seed=Meltem&size=200',
  'https://api.dicebear.com/7.x/notionists/png?seed=Poyraz&size=200',
  'https://api.dicebear.com/7.x/notionists/png?seed=Yildiz&size=200',
];

class ProfileScreen extends StatefulWidget {
  final CampusUser user;
  final CampusRepository repository;
  final UserRole role;
  final String language;
  final CampusVisibility locationVisibility;
  final bool nearbyDiscoverable;
  final bool personalization;
  final ValueChanged<String> onLanguage;
  final ValueChanged<CampusVisibility> onLocationVisibility;
  final ValueChanged<bool> onNearbyDiscoverable;
  final ValueChanged<bool> onPersonalization;

  const ProfileScreen({
    super.key,
    required this.user,
    required this.repository,
    this.role = UserRole.student,
    required this.language,
    required this.locationVisibility,
    required this.nearbyDiscoverable,
    required this.personalization,
    required this.onLanguage,
    required this.onLocationVisibility,
    required this.onNearbyDiscoverable,
    required this.onPersonalization,
  });

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  late Future<List<ActivityItem>> _activityFuture;
  String? _avatarOverride;
  bool _checkInVisible = true;
  int _visibleActivity = kPageSize;

  @override
  void initState() {
    super.initState();
    _activityFuture = widget.repository.getMyActivity();
    AppSettingsStore.avatarUrl().then((url) {
      if (mounted && url != null) setState(() => _avatarOverride = url);
    });
    AppSettingsStore.checkInVisible().then((visible) {
      if (mounted) setState(() => _checkInVisible = visible);
    });
  }

  Future<void> _refreshActivity() async {
    setState(() {
      _activityFuture = widget.repository.getMyActivity();
    });
  }

  Future<void> _changeAvatar(BuildContext context) async {
    final strings = AppLocale.of(context);
    final choice = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => Padding(
        padding: const EdgeInsets.fromLTRB(20, 20, 20, 30),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(strings.t('profile_change_photo'),
                style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 18)),
            const SizedBox(height: 14),
            OutlinedButton.icon(
              onPressed: () => Navigator.of(ctx).pop('__pick__'),
              icon: const Icon(Icons.add_a_photo_outlined),
              label: Text(strings.t('profile_avatar_pick_device')),
            ),
            const SizedBox(height: 16),
            Text(strings.t('profile_avatar_preset'),
                style: const TextStyle(color: ArucadColors.muted, fontSize: 12)),
            const SizedBox(height: 10),
            Wrap(
              spacing: 12,
              runSpacing: 12,
              children: [
                for (final url in _presetAvatars)
                  GestureDetector(
                    onTap: () => Navigator.of(ctx).pop(url),
                    child: ClipOval(
                      child: Image.network(url, width: 56, height: 56, fit: BoxFit.cover),
                    ),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
    if (choice == null || !context.mounted) return;

    if (choice == '__pick__') {
      final bytes = await PhotoPickerService.pick(context,
          imageQuality: 70, maxWidth: 480);
      if (bytes == null) return;
      final dataUri = 'data:image/jpeg;base64,${base64Encode(bytes)}';
      await AppSettingsStore.setAvatarUrl(dataUri);
      if (mounted) setState(() => _avatarOverride = dataUri);
    } else {
      await AppSettingsStore.setAvatarUrl(choice);
      if (mounted) setState(() => _avatarOverride = choice);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = widget.user;
    final strings = AppLocale.of(context);
    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 760),
        child: RefreshIndicator(
          onRefresh: _refreshActivity,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 18, 20, 24),
            children: [
              Text(strings.t('nav_profile'),
                  style: Theme.of(context)
                      .textTheme
                      .headlineSmall
                      ?.copyWith(fontWeight: FontWeight.w900)),
              const SizedBox(height: 14),
              Row(children: [
                GestureDetector(
                  onTap: () => _changeAvatar(context),
                  child: Stack(children: [
                    _ProfileAvatar(
                        name: user.name,
                        avatarUrl: _avatarOverride ?? user.avatarUrl),
                    Positioned(
                      right: -2,
                      bottom: -2,
                      child: Container(
                        padding: const EdgeInsets.all(4),
                        decoration: const BoxDecoration(
                            color: ArucadColors.primary, shape: BoxShape.circle),
                        child: const Icon(Icons.edit,
                            size: 12, color: Colors.white),
                      ),
                    ),
                  ]),
                ),
                const SizedBox(width: 14),
                Expanded(
                    child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                      Text(user.name,
                          style: const TextStyle(
                              fontWeight: FontWeight.w900, fontSize: 22)),
                      Row(children: [
                        Text('${user.role} · '),
                        Text('Level ${user.level}',
                            style: TextStyle(
                                color: levelColor(user.level),
                                fontWeight: FontWeight.w800)),
                      ]),
                    ]))
              ]),
              const SizedBox(height: 18),
              // XP/Seviye artık burada değil, "Aktivite" tabında gösteriliyor
              // (yıllık skor kartı + Campus Journey) — bu ekran gerçek bir
              // ayarlar ekranı (dil, konum görünürlüğü, ...), gamification
              // burada tekrar edilmiyor. Places/Events/Memories hâlâ burada:
              // XP değil, "hakkımda" özeti.
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(18),
                  child: Wrap(spacing: 18, runSpacing: 10, children: [
                    Metric(label: 'Places', value: '${user.places}'),
                    Metric(label: 'Events', value: '${user.events}'),
                    Metric(label: 'Memories', value: '${user.memories}'),
                  ]),
                ),
              ),
              const SizedBox(height: 16),
              Card(
                child: ListTile(
                  leading: const Icon(Icons.flag_circle_outlined, color: ArucadColors.primary),
                  title: Text(strings.t('profile_welcome_title'),
                      style: const TextStyle(fontWeight: FontWeight.w800)),
                  subtitle: Text(strings.t('profile_welcome_sub')),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(MaterialPageRoute(
                      builder: (_) => NewStudentScreen(repository: widget.repository))),
                ),
              ),
              const SizedBox(height: 16),
              Text(strings.t('profile_activity'),
                  style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
              const SizedBox(height: 8),
              Card(
                child: FutureBuilder<List<ActivityItem>>(
                  future: _activityFuture,
                  builder: (context, snap) {
                    if (snap.connectionState != ConnectionState.done) {
                      return const Padding(
                        padding: EdgeInsets.symmetric(vertical: 24),
                        child: Center(child: CircularProgressIndicator()),
                      );
                    }
                    final items = snap.data ?? const <ActivityItem>[];
                    if (items.isEmpty) {
                      return Padding(
                        padding: const EdgeInsets.all(18),
                        child: Text(strings.t('profile_activity_empty'),
                            style: const TextStyle(color: ArucadColors.muted)),
                      );
                    }
                    final shown = _visibleActivity.clamp(0, items.length);
                    return Column(
                      children: [
                        for (var i = 0; i < shown; i++) ...[
                          if (i > 0) const Divider(height: 1),
                          _ActivityTile(item: items[i]),
                        ],
                        const Divider(height: 1),
                        LoadMoreButton(
                          shown: shown,
                          total: items.length,
                          itemLabel: 'işlem',
                          onTap: () => setState(() => _visibleActivity += kPageSize),
                        ),
                      ],
                    );
                  },
                ),
              ),
              const SizedBox(height: 16),
              Text(strings.t('profile_settings'),
                  style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
              const SizedBox(height: 8),
              Card(
                child: Column(children: [
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 14, 16, 4),
                    child: Text('Konum görünürlüğüm',
                        style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
                  ),
                  for (final level in CampusVisibility.values)
                    RadioListTile<CampusVisibility>(
                      dense: true,
                      title: Text(level.label),
                      value: level,
                      groupValue: widget.locationVisibility,
                      onChanged: (v) {
                        if (v != null) widget.onLocationVisibility(v);
                      },
                    ),
                  const Divider(height: 1),
                  SwitchListTile(
                      title: Text(strings.t('profile_nearby_toggle')),
                      subtitle: Text(strings.t('profile_nearby_toggle_sub')),
                      value: widget.nearbyDiscoverable,
                      onChanged: widget.onNearbyDiscoverable),
                  const Divider(height: 1),
                  SwitchListTile(
                      title: Text(strings.t('profile_personalized')),
                      subtitle: Text(strings.t('profile_personalized_sub')),
                      value: widget.personalization,
                      onChanged: widget.onPersonalization),
                  const Divider(height: 1),
                  SwitchListTile(
                      title: Text(strings.t('profile_checkin_visibility')),
                      subtitle: Text(strings.t('profile_checkin_visibility_sub')),
                      value: _checkInVisible,
                      onChanged: (v) {
                        setState(() => _checkInVisible = v);
                        AppSettingsStore.setCheckInVisible(v);
                      }),
                  const Divider(height: 1),
                  ListTile(
                    title: Text(strings.t('profile_language')),
                    subtitle: Text(widget.language),
                    trailing: DropdownButton<String>(
                      value: widget.language,
                      underline: const SizedBox(),
                      items: const ['TR', 'EN', 'RU']
                          .map((e) => DropdownMenuItem(value: e, child: Text(e)))
                          .toList(),
                      onChanged: (v) {
                        if (v != null) widget.onLanguage(v);
                      },
                    ),
                  ),
                ]),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _ActivityTile extends StatelessWidget {
  final ActivityItem item;
  const _ActivityTile({required this.item});

  (IconData, Color) get _visual => switch (item.kind) {
        ActivityKind.checkIn => (Icons.verified_outlined, ArucadColors.primary),
        ActivityKind.eventJoin => (Icons.event_available_outlined, ArucadColors.blue),
        ActivityKind.review => (Icons.star_outline, ArucadColors.warning),
        ActivityKind.comment => (Icons.mode_comment_outlined, ArucadColors.ink),
        ActivityKind.like => (Icons.favorite_outline, ArucadColors.primary),
        ActivityKind.report => (Icons.flag_outlined, ArucadColors.warning),
      };

  @override
  Widget build(BuildContext context) {
    final (icon, color) = _visual;
    return ListTile(
      leading: CircleAvatar(
          backgroundColor: color.withValues(alpha: .14),
          child: Icon(icon, color: color, size: 20)),
      title: Text(item.title, style: const TextStyle(fontWeight: FontWeight.w800)),
      subtitle: Text(item.subtitle, maxLines: 2, overflow: TextOverflow.ellipsis),
      trailing: Text(item.meta,
          style: const TextStyle(color: ArucadColors.muted, fontSize: 11)),
    );
  }
}

class _ProfileAvatar extends StatefulWidget {
  final String name;
  final String? avatarUrl;
  const _ProfileAvatar({required this.name, required this.avatarUrl});

  @override
  State<_ProfileAvatar> createState() => _ProfileAvatarState();
}

class _ProfileAvatarState extends State<_ProfileAvatar> {
  bool _failed = false;

  @override
  void didUpdateWidget(covariant _ProfileAvatar oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.avatarUrl != widget.avatarUrl) _failed = false;
  }

  @override
  Widget build(BuildContext context) {
    final initials = widget.name.isEmpty ? '?' : widget.name.substring(0, 1);
    final fallback = CircleAvatar(
      radius: 34,
      backgroundColor: ArucadColors.primary,
      child: Text(initials,
          style: const TextStyle(
              color: Colors.white, fontSize: 26, fontWeight: FontWeight.w900)),
    );
    final url = widget.avatarUrl;
    if (url == null || _failed) return fallback;

    if (url.startsWith('data:')) {
      try {
        final bytes = base64Decode(url.split(',').last);
        return ClipOval(
          child: Image.memory(bytes, width: 68, height: 68, fit: BoxFit.cover),
        );
      } catch (_) {
        return fallback;
      }
    }

    return ClipOval(
      child: Image.network(
        url,
        width: 68,
        height: 68,
        fit: BoxFit.cover,
        errorBuilder: (_, __, ___) {
          WidgetsBinding.instance
              .addPostFrameCallback((_) => setState(() => _failed = true));
          return fallback;
        },
      ),
    );
  }
}

class Metric extends StatelessWidget {
  final String label;
  final String value;
  const Metric({super.key, required this.label, required this.value});

  @override
  Widget build(BuildContext context) => SizedBox(
        width: 110,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(value,
              style:
                  const TextStyle(fontSize: 20, fontWeight: FontWeight.w900)),
          Text(label,
              style: const TextStyle(color: ArucadColors.muted, fontSize: 12)),
        ]),
      );
}
