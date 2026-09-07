import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/auth/app_settings_store.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/photo_picker_service.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/admin/admin_panel_screen.dart';
import 'package:arucad_campus_prototype/features/home/campus_live_map.dart';
import 'package:arucad_campus_prototype/features/profile/my_applications_screen.dart';
import 'package:arucad_campus_prototype/features/quests/quests_screen.dart';
import 'package:arucad_campus_prototype/features/services/appointment_booking_screen.dart';
import 'package:arucad_campus_prototype/features/trainer/trainer_panel_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_avatar.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_network_image.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';
import 'package:arucad_campus_prototype/features/widgets/language_toggle.dart';

/// Which category of Settings is showing — see [ProfileScreen]'s doc
/// comment for why this screen is split into categories instead of one
/// long scroll.
enum SettingsSection { info, activity, system }

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
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;
  final UserRole role;
  final String language;
  final CampusVisibility locationVisibility;
  final bool nearbyDiscoverable;
  final bool personalization;
  final bool isPrivateProfile;
  final ValueChanged<String> onLanguage;
  final ValueChanged<CampusVisibility> onLocationVisibility;
  final ValueChanged<bool> onNearbyDiscoverable;
  final ValueChanged<bool> onPersonalization;
  final ValueChanged<bool> onPrivateProfile;
  final SettingsSection initialSection;

  const ProfileScreen({
    super.key,
    required this.user,
    required this.repository,
    required this.mapProvider,
    required this.analyticsTracker,
    this.role = UserRole.student,
    required this.language,
    required this.locationVisibility,
    required this.nearbyDiscoverable,
    required this.personalization,
    required this.isPrivateProfile,
    required this.onLanguage,
    required this.onLocationVisibility,
    required this.onNearbyDiscoverable,
    required this.onPersonalization,
    required this.onPrivateProfile,
    this.initialSection = SettingsSection.info,
  });

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  late SettingsSection _section = widget.initialSection;
  String? _avatarOverride;
  bool _checkInVisible = true;
  late Future<List<ActivityItem>> _activityFuture;
  int _visibleActivity = kPageSize;
  final _scaffoldKey = GlobalKey<ScaffoldState>();

  @override
  void didUpdateWidget(covariant ProfileScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    // ProfileScreen stays mounted inside the root IndexedStack, so a fresh
    // "jump to Activity" request (e.g. tapping the XP chip on Home) arrives
    // as a prop change here rather than a new initState.
    if (widget.initialSection != oldWidget.initialSection) {
      setState(() => _section = widget.initialSection);
    }
  }

  @override
  void initState() {
    super.initState();
    _activityFuture = widget.repository.getMyActivity();
    AppSettingsStore.avatarUrl().then((url) {
      if (!mounted) return;
      final local = (url != null && url.isNotEmpty) ? url : null;
      setState(() => _avatarOverride = local ?? widget.user.avatarUrl);
    }, onError: (_) {});
    widget.repository.getUserSettings().then((settings) {
      if (mounted) setState(() => _checkInVisible = settings.checkInVisible);
    }, onError: (_) {});
  }

  Future<void> _refreshInfo() async {
    setState(() => _activityFuture = widget.repository.getMyActivity());
    await _activityFuture;
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
                style:
                    const TextStyle(fontWeight: FontWeight.w900, fontSize: 18)),
            const SizedBox(height: 14),
            OutlinedButton.icon(
              onPressed: () => Navigator.of(ctx).pop('__pick__'),
              icon: const Icon(Icons.add_a_photo_outlined),
              label: Text(strings.t('profile_avatar_pick_device')),
            ),
            const SizedBox(height: 16),
            Text(strings.t('profile_avatar_preset'),
                style:
                    const TextStyle(color: ArucadColors.muted, fontSize: 12)),
            const SizedBox(height: 10),
            Wrap(
              spacing: 12,
              runSpacing: 12,
              children: [
                for (final url in _presetAvatars)
                  GestureDetector(
                    onTap: () => Navigator.of(ctx).pop(url),
                    child: ClipOval(
                      child: CampusNetworkImage(url,
                          width: 56,
                          height: 56,
                          cacheBust: url.hashCode.toString()),
                    ),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
    if (choice == null || !context.mounted) return;

    try {
      late final String persisted;
      if (choice == '__pick__') {
        final bytes = await PhotoPickerService.pick(context,
            imageQuality: 70, maxWidth: 480);
        if (bytes == null) return;
        final item = await widget.repository
            .uploadMyMedia(bytes, fileName: 'avatar.jpg');
        final remote = item.url;
        persisted =
            (remote != null && remote.isNotEmpty && !remote.startsWith('data:'))
                ? remote
                : item.displaySrc;
      } else {
        persisted = choice;
      }
      if (!persisted.startsWith('data:')) {
        await widget.repository.updateProfileBio(avatarUrl: persisted);
      }
      await AppSettingsStore.setAvatarUrl(persisted);
      if (mounted) setState(() => _avatarOverride = persisted);
    } catch (e) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Profil fotoğrafı kaydedilemedi: $e')),
      );
    }
  }

  void _selectSection(SettingsSection section) {
    setState(() => _section = section);
    _scaffoldKey.currentState?.closeDrawer();
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final content = IndexedStack(
      index: _section.index,
      children: [
        _buildInfoSection(context, strings),
        QuestsScreen(
          repository: widget.repository,
          user: widget.user,
          mapProvider: widget.mapProvider,
          analyticsTracker: widget.analyticsTracker,
          showSuggestions: widget.personalization,
        ),
        _buildSystemSection(context, strings),
      ],
    );

    return Scaffold(
      key: _scaffoldKey,
      backgroundColor: Colors.transparent,
      drawer: _SettingsDrawer(
        selected: _section,
        onSelect: _selectSection,
        strings: strings,
      ),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 760),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 18, 20, 12),
                child: LayoutBuilder(builder: (context, constraints) {
                  final wide = constraints.maxWidth >= 560;
                  final title = Text(strings.t('nav_profile'),
                      style: Theme.of(context)
                          .textTheme
                          .headlineSmall
                          ?.copyWith(fontWeight: FontWeight.w900));
                  if (wide) return title;
                  return Row(children: [
                    _MenuButton(
                        onTap: () => _scaffoldKey.currentState?.openDrawer()),
                    const SizedBox(width: 12),
                    title,
                  ]);
                }),
              ),
              Expanded(
                child: LayoutBuilder(builder: (context, constraints) {
                  // A full labeled rail once there's room for the longest
                  // label ("Kullanıcı Bilgileri") without wrapping
                  // awkwardly; below that, the sidebar collapses behind
                  // the ☰ button above (opening the same destinations as a
                  // left drawer) instead of squeezing an icon-only rail
                  // into an already-tight phone width.
                  final wide = constraints.maxWidth >= 560;
                  if (!wide) return content;
                  return Row(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      NavigationRail(
                        backgroundColor: Theme.of(context)
                            .colorScheme
                            .surfaceContainerHighest,
                        selectedIndex: _section.index,
                        onDestinationSelected: (i) =>
                            _selectSection(SettingsSection.values[i]),
                        labelType: NavigationRailLabelType.all,
                        minWidth: 116,
                        useIndicator: true,
                        indicatorColor:
                            ArucadColors.primary.withValues(alpha: .16),
                        unselectedIconTheme: IconThemeData(
                            color: Theme.of(context)
                                .colorScheme
                                .onSurfaceVariant),
                        selectedIconTheme:
                            const IconThemeData(color: ArucadColors.primary),
                        unselectedLabelTextStyle: TextStyle(
                            color: Theme.of(context)
                                .colorScheme
                                .onSurfaceVariant,
                            fontSize: 11),
                        selectedLabelTextStyle: const TextStyle(
                            color: ArucadColors.primary,
                            fontSize: 11,
                            fontWeight: FontWeight.w700),
                        destinations: [
                          NavigationRailDestination(
                              icon: const Icon(Icons.person_outline),
                              selectedIcon: const Icon(Icons.person),
                              label: Text(strings.t('settings_section_info'))),
                          NavigationRailDestination(
                              icon: const Icon(Icons.emoji_events_outlined),
                              selectedIcon: const Icon(Icons.emoji_events),
                              label:
                                  Text(strings.t('settings_section_activity'))),
                          NavigationRailDestination(
                              icon: const Icon(Icons.tune_outlined),
                              selectedIcon: const Icon(Icons.tune),
                              label:
                                  Text(strings.t('settings_section_system'))),
                        ],
                      ),
                      const VerticalDivider(width: 1),
                      Expanded(child: content),
                    ],
                  );
                }),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildInfoSection(BuildContext context, AppStrings strings) {
    final user = widget.user;
    return RefreshIndicator(
      onRefresh: _refreshInfo,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
        children: [
          Row(children: [
            GestureDetector(
              onTap: () => _changeAvatar(context),
              child: Stack(children: [
                CampusAvatar(
                    name: user.name,
                    avatarUrl: _avatarOverride ?? user.avatarUrl,
                    radius: 34),
                Positioned(
                  right: -2,
                  bottom: -2,
                  child: Container(
                    padding: const EdgeInsets.all(4),
                    decoration: const BoxDecoration(
                        color: ArucadColors.primary, shape: BoxShape.circle),
                    child:
                        const Icon(Icons.edit, size: 12, color: Colors.white),
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
          // XP/Seviye/Journey/leaderboard artık burada değil,
          // "Kullanıcı Aktivitesi" kategorisinde (QuestsScreen burada
          // gömülü) gösteriliyor — bu bölüm gerçek bir "hakkımda" özeti
          // (Places/Events/Memories), gamification burada tekrarlanmıyor.
          Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 16),
            decoration: BoxDecoration(
              color: ArucadColors.blue,
              borderRadius: BorderRadius.circular(ArucadRadius.card),
              boxShadow: ArucadShadows.card,
            ),
            child: Row(children: [
              Expanded(
                  child: Metric(
                      label: 'Places',
                      value: '${user.places}',
                      light: true,
                      expand: true)),
              Expanded(
                  child: Metric(
                      label: 'Events',
                      value: '${user.events}',
                      light: true,
                      expand: true)),
              Expanded(
                  child: Metric(
                      label: 'Memories',
                      value: '${user.memories}',
                      light: true,
                      expand: true)),
            ]),
          ),
          const SizedBox(height: 16),
          Card(
            child: ListTile(
              leading: const Icon(Icons.assignment_outlined,
                  color: ArucadColors.primary),
              title: const Text('Başvurularım',
                  style: TextStyle(fontWeight: FontWeight.w800)),
              subtitle:
                  const Text('Kulüp, etkinlik ve hizmet başvurularının durumu'),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => Navigator.of(context).push(MaterialPageRoute(
                  builder: (_) =>
                      MyApplicationsScreen(repository: widget.repository))),
            ),
          ),
          const SizedBox(height: 8),
          Card(
            child: ListTile(
              leading: const Icon(Icons.event_available_outlined,
                  color: ArucadColors.primary),
              title: const Text('Randevularım',
                  style: TextStyle(fontWeight: FontWeight.w800)),
              subtitle: const Text('Personel ile randevu al veya iptal et'),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => Navigator.of(context).push(MaterialPageRoute(
                  builder: (_) =>
                      AppointmentBookingScreen(repository: widget.repository))),
            ),
          ),
          const SizedBox(height: 16),
          Text(strings.t('profile_activity'),
              style:
                  const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
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
                      ActivityTile(item: items[i]),
                    ],
                    const Divider(height: 1),
                    LoadMoreButton(
                      shown: shown,
                      total: items.length,
                      itemLabel: 'işlem',
                      onTap: () =>
                          setState(() => _visibleActivity += kPageSize),
                    ),
                  ],
                );
              },
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildSystemSection(BuildContext context, AppStrings strings) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
      children: [
        Card(
          child: Column(children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 14, 16, 4),
              child: Text('Konum görünürlüğüm',
                  style: Theme.of(context)
                      .textTheme
                      .titleSmall
                      ?.copyWith(fontWeight: FontWeight.w700)),
            ),
            RadioGroup<CampusVisibility>(
              groupValue: widget.locationVisibility,
              onChanged: (v) {
                if (v != null) widget.onLocationVisibility(v);
              },
              child: Column(
                children: [
                  for (final level in CampusVisibility.values)
                    RadioListTile<CampusVisibility>(
                      dense: true,
                      title: Text(level.label),
                      value: level,
                    ),
                ],
              ),
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
                title: Text(strings.t('profile_private')),
                subtitle: Text(strings.t('profile_private_sub')),
                value: widget.isPrivateProfile,
                onChanged: widget.onPrivateProfile),
            const Divider(height: 1),
            SwitchListTile(
                title: Text(strings.t('profile_checkin_visibility')),
                subtitle: Text(strings.t('profile_checkin_visibility_sub')),
                value: _checkInVisible,
                onChanged: (v) {
                  setState(() => _checkInVisible = v);
                  widget.repository.updateUserSettings(checkInVisible: v);
                }),
            const Divider(height: 1),
            ListTile(
              title: Text(strings.t('profile_language')),
              trailing: FittedBox(
                fit: BoxFit.scaleDown,
                child: LanguageToggle(
                  code: widget.language,
                  onChanged: widget.onLanguage,
                ),
              ),
            ),
            if (widget.role.canOpenAdminPanel) ...[
              const Divider(height: 1),
              ListTile(
                leading: const Icon(Icons.admin_panel_settings_outlined),
                title: const Text('Yönetim paneli'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(MaterialPageRoute(
                  builder: (ctx) => AdminPanelScreen(
                    repository: widget.repository,
                    role: widget.role,
                    user: widget.user,
                    onLogout: () => Navigator.of(ctx).pop(),
                  ),
                )),
              ),
            ],
            if (widget.role.canOpenTrainerPanel) ...[
              const Divider(height: 1),
              ListTile(
                leading: const Icon(Icons.school_outlined),
                title: const Text('Eğitmen paneli'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(MaterialPageRoute(
                  builder: (ctx) => TrainerPanelScreen(
                    repository: widget.repository,
                    user: widget.user,
                    role: widget.role,
                    onLogout: () => Navigator.of(ctx).pop(),
                  ),
                )),
              ),
            ],
          ]),
        ),
      ],
    );
  }
}

/// The narrow-screen counterpart to the wide labeled [NavigationRail] above
/// — same three destinations, opened via the ☰ button instead of sitting
/// permanently on screen, so a phone-width Settings page never has to
/// squeeze an icon-only rail into an already-tight layout.
class _SettingsDrawer extends StatelessWidget {
  final SettingsSection selected;
  final ValueChanged<SettingsSection> onSelect;
  final AppStrings strings;
  const _SettingsDrawer(
      {required this.selected, required this.onSelect, required this.strings});

  @override
  Widget build(BuildContext context) {
    final items = [
      (
        SettingsSection.info,
        Icons.person_outline,
        Icons.person,
        strings.t('settings_section_info')
      ),
      (
        SettingsSection.activity,
        Icons.emoji_events_outlined,
        Icons.emoji_events,
        strings.t('settings_section_activity')
      ),
      (
        SettingsSection.system,
        Icons.tune_outlined,
        Icons.tune,
        strings.t('settings_section_system')
      ),
    ];
    final scheme = Theme.of(context).colorScheme;
    return Drawer(
      backgroundColor: scheme.surfaceContainerHighest,
      child: SafeArea(
        child: ListView(
          padding: const EdgeInsets.symmetric(vertical: 12),
          children: [
            for (final (section, icon, selectedIcon, label) in items)
              ListTile(
                leading: Icon(section == selected ? selectedIcon : icon,
                    color: section == selected
                        ? ArucadColors.primary
                        : scheme.onSurfaceVariant),
                title: Text(label,
                    style: TextStyle(
                        color: section == selected
                            ? ArucadColors.primary
                            : scheme.onSurface,
                        fontWeight: section == selected
                            ? FontWeight.w700
                            : FontWeight.w500)),
                selected: section == selected,
                selectedTileColor: ArucadColors.primary.withValues(alpha: .1),
                onTap: () => onSelect(section),
              ),
          ],
        ),
      ),
    );
  }
}

class _MenuButton extends StatelessWidget {
  final VoidCallback onTap;
  const _MenuButton({required this.onTap});

  @override
  Widget build(BuildContext context) {
    return IconButton(
      onPressed: onTap,
      tooltip: 'Menü',
      padding: EdgeInsets.zero,
      constraints: const BoxConstraints(minWidth: 40, minHeight: 40),
      style: IconButton.styleFrom(
        backgroundColor: Colors.transparent,
        foregroundColor: Theme.of(context).colorScheme.onSurface,
        highlightColor: Colors.transparent,
        hoverColor: Colors.transparent,
        splashFactory: NoSplash.splashFactory,
      ),
      icon: const Icon(Icons.menu, size: 26),
    );
  }
}

class Metric extends StatelessWidget {
  final String label;
  final String value;
  final bool light;
  final bool expand;
  const Metric({
    super.key,
    required this.label,
    required this.value,
    this.light = false,
    this.expand = false,
  });

  @override
  Widget build(BuildContext context) {
    final column = Column(
        crossAxisAlignment:
            expand ? CrossAxisAlignment.center : CrossAxisAlignment.start,
        children: [
          Text(value,
              style: TextStyle(
                fontSize: 20,
                fontWeight: FontWeight.w900,
                color: light
                    ? Colors.white
                    : Theme.of(context).colorScheme.onSurface,
              )),
          Text(label,
              textAlign: expand ? TextAlign.center : TextAlign.start,
              style: TextStyle(
                  color: light
                      ? Colors.white.withValues(alpha: .76)
                      : Theme.of(context).colorScheme.onSurfaceVariant,
                  fontSize: 12)),
        ]);
    if (expand) return column;
    return SizedBox(width: 110, child: column);
  }
}
