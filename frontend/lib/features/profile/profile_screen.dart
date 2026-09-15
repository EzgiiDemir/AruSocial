import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/auth/app_settings_store.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/photo_picker_service.dart';
import 'package:arucad_campus_prototype/core/services/upload_rules.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/home/campus_live_map.dart';
import 'package:arucad_campus_prototype/features/legal/legal_document_screen.dart';
import 'package:arucad_campus_prototype/features/profile/my_applications_screen.dart';
import 'package:arucad_campus_prototype/features/quests/quests_screen.dart';
import 'package:arucad_campus_prototype/features/services/appointment_booking_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/moderation_notice.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_avatar.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_network_image.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';
import 'package:arucad_campus_prototype/features/widgets/language_toggle.dart';

/// Which category of Settings is showing — see [ProfileScreen]'s doc
/// comment for why this screen is split into categories instead of one
/// long scroll.
enum SettingsSection { info, activity, system }

/// Preset avatars, in alternating feminine/masculine pairs.
///
/// The old set of six skewed masculine, which quietly tells half the campus
/// that the defaults were not drawn for them. Seeds are Turkish names in
/// alternating pairs so the grid reads as balanced at a glance, and each
/// carries its own background colour so the row is not six variations of
/// beige — picking an avatar should feel like a choice, not a formality.
///
/// DiceBear renders deterministically from the seed, so a given name always
/// produces the same face and these stay stable across rebuilds.
const _avatarPalette = [
  'b6e3f4',
  'ffd5dc',
  'c0aede',
  'ffdfbf',
  'd1f4d0',
  'ffe7a3',
];

const _avatarSeeds = [
  // Feminine / masculine alternating, so neither dominates the grid.
  'Zeynep', 'Aslan', 'Elif', 'Poyraz', 'Meltem', 'Kaan',
  'Kiraz', 'Deniz', 'Nehir', 'Bora', 'Yildiz', 'Efe',
  'Derin', 'Alp', 'Ada', 'Cinar', 'Melis', 'Toprak',
];

List<String> get _presetAvatars => [
      for (var i = 0; i < _avatarSeeds.length; i++)
        'https://api.dicebear.com/7.x/notionists/png'
            '?seed=${_avatarSeeds[i]}'
            '&size=200'
            '&backgroundColor=${_avatarPalette[i % _avatarPalette.length]}',
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

  /// Signing out from the settings sidebar. Routed through the shell so it
  /// runs the same confirmation and session teardown as every other exit.
  final VoidCallback onLogout;

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
    required this.onLogout,
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
      // Scrollable: the preset grid is three rows on a phone, and on a
      // short screen the bottom row would otherwise be unreachable.
      builder: (ctx) => SafeArea(
        child: SingleChildScrollView(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 20, 20, 30),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(strings.t('profile_change_photo'),
                    style: const TextStyle(
                        fontWeight: FontWeight.w900, fontSize: 18)),
                const SizedBox(height: 14),
                OutlinedButton.icon(
                  onPressed: () => Navigator.of(ctx).pop('__pick__'),
                  icon: const Icon(Icons.add_a_photo_outlined),
                  label: Text(strings.t('profile_avatar_pick_device')),
                ),
                const SizedBox(height: 16),
                Text(strings.t('profile_avatar_preset'),
                    style: const TextStyle(
                        color: ArucadColors.muted, fontSize: 12)),
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

        // Checked before the upload starts so an obviously unusable file
        // fails in a second rather than after a slow transfer.
        final reason = UploadRules.rejectionReason(bytes, 'avatar.jpg');
        if (reason != null) {
          if (!context.mounted) return;
          await showModerationNotice(context, message: reason);

          return;
        }

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
      // A refusal deserves the full explanation, not a one-line snackbar.
      if (await showModerationNoticeFor(context, e)) return;
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
        onLogout: widget.onLogout,
      ),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 760),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 12, 20, 4),
                child: LayoutBuilder(builder: (context, constraints) {
                  final wide = constraints.maxWidth >= 560;
                  if (wide) return const SizedBox.shrink();
                  return Align(
                    alignment: Alignment.centerLeft,
                    child: _MenuButton(
                        onTap: () => _scaffoldKey.currentState?.openDrawer()),
                  );
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
                        // No pill behind the selected destination. The
                        // indicator painted a blue block behind whichever
                        // item was active, which fought the brand-coloured
                        // icons for attention and read as a stuck hover
                        // state. Selection is carried by the icon filling
                        // in and the label going bold instead.
                        useIndicator: false,
                        // Icon colour is set per destination below, so the
                        // rail's own themes must not override it. Only the
                        // label colours are themed here.
                        unselectedLabelTextStyle: TextStyle(
                            color:
                                Theme.of(context).colorScheme.onSurfaceVariant,
                            fontSize: 11),
                        selectedLabelTextStyle: TextStyle(
                            color: Theme.of(context).colorScheme.onSurface,
                            fontSize: 11,
                            fontWeight: FontWeight.w700),
                        destinations: [
                          // One ARUCAD identity colour each — blue, yellow,
                          // green — so a destination is recognisable by its
                          // colour before the label is read. Unselected
                          // items keep the same hue at reduced opacity
                          // rather than turning grey, which is what makes
                          // the rail read as coloured at a glance.
                          _railDestination(
                            context,
                            outlined: Icons.person_outline,
                            filled: Icons.person,
                            color: ArucadColors.primary,
                            label: strings.t('settings_section_info'),
                            selected: _section == SettingsSection.info,
                          ),
                          _railDestination(
                            context,
                            outlined: Icons.emoji_events_outlined,
                            filled: Icons.emoji_events,
                            color: ArucadColors.yellow,
                            label: strings.t('settings_section_activity'),
                            selected: _section == SettingsSection.activity,
                          ),
                          _railDestination(
                            context,
                            outlined: Icons.tune_outlined,
                            filled: Icons.tune,
                            color: ArucadColors.campusGreen,
                            label: strings.t('settings_section_system'),
                            selected: _section == SettingsSection.system,
                          ),
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

  /// A rail destination that keeps its own colour in both states.
  ///
  /// The icon is coloured here rather than through the rail's
  /// `selectedIconTheme` / `unselectedIconTheme`, because those apply one
  /// colour to every destination — which is exactly what made the whole
  /// rail blue.
  NavigationRailDestination _railDestination(
    BuildContext context, {
    required IconData outlined,
    required IconData filled,
    required Color color,
    required String label,
    required bool selected,
  }) {
    return NavigationRailDestination(
      icon: Icon(outlined, color: color.withValues(alpha: .55)),
      selectedIcon: Icon(filled, color: color),
      label: Text(label),
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
              title: Text(AppLocale.of(context).t('pr_my_applications'),
                  style: TextStyle(fontWeight: FontWeight.w800)),
              subtitle: Text(AppLocale.of(context).t('pr_my_applications_sub')),
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
              title: Text(AppLocale.of(context).t('pr_my_appointments'),
                  style: TextStyle(fontWeight: FontWeight.w800)),
              subtitle: Text(AppLocale.of(context).t('pr_my_appointments_sub')),
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
                      showCompleteLabel: false,
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
              child: Text(AppLocale.of(context).t('pr_location_visibility'),
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
          ]),
        ),

        // Safety and legal.
        //
        // These documents existed and `LegalDocumentScreen` existed, and
        // nothing in the app referenced either — so the safety contact
        // was published on the web and unreachable from the product.
        // An app carrying user-generated content has to put the way to
        // report abuse somewhere a person can actually find it, and
        // "reachable" is the whole requirement.
        const SizedBox(height: 12),
        Card(
          child: Column(children: [
            ListTile(
              leading: const Icon(Icons.shield_outlined,
                  color: ArucadColors.primary),
              title: Text(AppLocale.of(context).t('legal_safety')),
              subtitle: Text(AppLocale.of(context).t('legal_safety_sub'),
                  style: const TextStyle(fontSize: 12)),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => Navigator.of(context).push(MaterialPageRoute(
                builder: (_) => LegalDocumentScreen(
                  title: AppLocale.of(context).t('legal_safety'),
                  assetPath: 'assets/legal/safety.md',

                ),
              )),
            ),
            const Divider(height: 1),
            ListTile(
              leading: const Icon(Icons.gavel_outlined),
              title: Text(AppLocale.of(context).t('legal_terms')),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => Navigator.of(context).push(MaterialPageRoute(
                builder: (_) => LegalDocumentScreen(
                  title: AppLocale.of(context).t('legal_terms'),
                  assetPath: 'assets/legal/terms.md',

                ),
              )),
            ),
            const Divider(height: 1),
            ListTile(
              leading: const Icon(Icons.lock_outline),
              title: Text(AppLocale.of(context).t('legal_privacy')),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => Navigator.of(context).push(MaterialPageRoute(
                builder: (_) => LegalDocumentScreen(
                  title: AppLocale.of(context).t('legal_privacy'),
                  assetPath: 'assets/legal/privacy.md',

                ),
              )),
            ),
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
  final VoidCallback onLogout;
  const _SettingsDrawer({
    required this.selected,
    required this.onSelect,
    required this.strings,
    required this.onLogout,
  });

  @override
  Widget build(BuildContext context) {
    // Same three identity colours as the wide-layout rail, so the menu
    // looks like the same menu whichever width it is shown at.
    final items = [
      (
        SettingsSection.info,
        Icons.person_outline,
        Icons.person,
        ArucadColors.primary,
        strings.t('settings_section_info')
      ),
      (
        SettingsSection.activity,
        Icons.emoji_events_outlined,
        Icons.emoji_events,
        ArucadColors.yellow,
        strings.t('settings_section_activity')
      ),
      (
        SettingsSection.system,
        Icons.tune_outlined,
        Icons.tune,
        ArucadColors.campusGreen,
        strings.t('settings_section_system')
      ),
    ];
    final scheme = Theme.of(context).colorScheme;
    return Drawer(
      backgroundColor: scheme.surfaceContainerHighest,
      child: SafeArea(
        child: Column(children: [
          Expanded(
            child: ListView(
              padding: const EdgeInsets.symmetric(vertical: 12),
              children: [
                for (final (section, icon, selectedIcon, color, label) in items)
                  ListTile(
                    leading: Icon(
                      section == selected ? selectedIcon : icon,
                      color: section == selected
                          ? color
                          : color.withValues(alpha: .55),
                    ),
                    title: Text(label,
                        style: TextStyle(
                            color: scheme.onSurface,
                            fontWeight: section == selected
                                ? FontWeight.w700
                                : FontWeight.w500)),
                    selected: section == selected,
                    // No tinted block behind the active row. The filled
                    // icon and bold label already say which one it is,
                    // and the tint read as a hover state that had stuck.
                    selectedTileColor: Colors.transparent,
                    onTap: () => onSelect(section),
                  ),
              ],
            ),
          ),
          // Pinned to the bottom, separated from the sections above: it is
          // not another place to navigate to, and putting it in the same
          // list is how people tap it by accident.
          const Divider(height: 1),
          ListTile(
            leading: const Icon(Icons.logout_rounded, color: ArucadColors.red),
            title: Text(strings.t('common_logout'),
                style: const TextStyle(
                    color: ArucadColors.red, fontWeight: FontWeight.w700)),
            onTap: onLogout,
          ),
          const SizedBox(height: 8),
        ]),
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
      tooltip: AppLocale.of(context).t('pr_menu'),
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
