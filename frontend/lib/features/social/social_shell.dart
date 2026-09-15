import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/social/chat_screen.dart';
import 'package:arucad_campus_prototype/features/social/notifications_screen.dart';
import 'package:arucad_campus_prototype/features/social/people_screen.dart';
import 'package:arucad_campus_prototype/features/social/social_profile_screen.dart';
import 'package:arucad_campus_prototype/features/social/social_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

/// Below this width, [SocialShell] switches from a left-side navigation
/// rail (desktop/tablet) to a ☰ button that opens the same navigation as a
/// left drawer (phone) — a full-height rail on a ~360-430px-wide phone
/// screen eats a large fraction of the already-tight width and squeezes
/// every list/card behind it.
const _kRailBreakpoint = 768.0;

class _NavItem {
  final IconData icon;
  final IconData selectedIcon;
  final String labelKey;
  const _NavItem(this.icon, this.selectedIcon, this.labelKey);
}

const _navItems = [
  _NavItem(Icons.home_outlined, Icons.home, 'nav_home'),
  _NavItem(Icons.chat_bubble_outline, Icons.chat_bubble, 'social_messages'),
  _NavItem(Icons.search, Icons.search, 'social_search'),
  _NavItem(Icons.person_outline, Icons.person, 'nav_profile'),
];

/// The Social tab's own internal shell — Home / Messages / Search / Profile
/// behind a navigation rail on wide screens, or a ☰ button opening the same
/// destinations as a left drawer on phones. Never both at once, and never
/// the wide rail on a narrow screen.
class SocialShell extends StatefulWidget {
  final CampusRepository repository;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;

  /// Opens the app settings tab. Passed straight through to the profile
  /// pane so its gear icon reaches the settings that already exist,
  /// rather than being a second, parallel copy of them.
  final VoidCallback? onOpenSettings;

  const SocialShell({
    super.key,
    required this.repository,
    required this.mapProvider,
    required this.analyticsTracker,
    this.onOpenSettings,
  });

  @override
  State<SocialShell> createState() => _SocialShellState();
}

class _SocialShellState extends State<SocialShell> {
  int _index = 0;
  final Set<int> _visited = {0};
  List<String> _peers = const [];
  int _unreadNotifications = 0;
  final _scaffoldKey = GlobalKey<ScaffoldState>();

  @override
  void initState() {
    super.initState();
    _loadLeaderboardPeers();
    _loadNotificationCount();
  }

  Future<void> _loadNotificationCount() async {
    try {
      final notifications = await widget.repository.getInboxNotifications();
      if (!mounted) return;
      setState(() => _unreadNotifications =
          notifications.where((notification) => !notification.read).length);
    } catch (_) {}
  }

  Future<void> _openNotifications() async {
    await Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => NotificationsScreen(repository: widget.repository)));
    if (mounted) await _loadNotificationCount();
  }

  Future<void> _loadLeaderboardPeers() async {
    try {
      final entries = await widget.repository.getLeaderboard();
      if (!mounted) return;
      setState(() =>
          _peers = entries.where((e) => !e.isMe).map((e) => e.name).toList());
    } catch (_) {}
  }

  void _select(int value) {
    setState(() {
      _visited.add(value);
      _index = value;
    });
    _scaffoldKey.currentState?.closeDrawer();
  }

  Widget _lazyPane(int index, Widget child) {
    if (!_visited.contains(index)) return const SizedBox.shrink();
    return RepaintBoundary(child: child);
  }

  String _titleForIndex(BuildContext context, int index) {
    final s = AppLocale.of(context);
    return switch (index) {
      0 => s.t('social_title'),
      1 => s.t('chat_title'),
      2 => s.t('social_search'),
      _ => s.t('nav_profile'),
    };
  }

  @override
  Widget build(BuildContext context) {
    // RepaintBoundary per tab: an animation/scroll inside the active tab
    // (or a setState higher up) shouldn't force Flutter to reconsider
    // paint for the other three offstage tabs too.
    return LayoutBuilder(builder: (context, constraints) {
      final isWide = constraints.maxWidth >= _kRailBreakpoint;
      final content = IndexedStack(index: _index, children: [
        _lazyPane(
            0,
            SocialScreen(
              repository: widget.repository,
              mapProvider: widget.mapProvider,
              analyticsTracker: widget.analyticsTracker,
              titleInShell: !isWide,
            )),
        _lazyPane(
            1,
            ChatThreadsScreen(
              repository: widget.repository,
              knownPeers: _peers,
              titleInShell: !isWide,
            )),
        _lazyPane(2, PeopleScreen(repository: widget.repository)),
        _lazyPane(
            3,
            SocialProfileScreen(
              repository: widget.repository,
              titleInShell: !isWide,
              onOpenSettings: widget.onOpenSettings,
            )),
      ]);

      if (isWide) {
        return Scaffold(
          backgroundColor: Colors.transparent,
          body: Row(children: [
            NavigationRail(
              backgroundColor: Theme.of(context).colorScheme.surface,
              selectedIndex: _index,
              onDestinationSelected: _select,
              labelType: NavigationRailLabelType.all,
              indicatorColor:
                  Theme.of(context).colorScheme.surfaceContainerHighest,
              unselectedIconTheme:
                  IconThemeData(color: Theme.of(context).colorScheme.onSurface),
              selectedIconTheme:
                  IconThemeData(color: Theme.of(context).colorScheme.onSurface),
              unselectedLabelTextStyle: TextStyle(
                  color: Theme.of(context).colorScheme.onSurface, fontSize: 11),
              selectedLabelTextStyle: TextStyle(
                  color: Theme.of(context).colorScheme.onSurface,
                  fontSize: 11,
                  fontWeight: FontWeight.w700),
              destinations: [
                for (var i = 0; i < _navItems.length; i++)
                  NavigationRailDestination(
                      icon:
                          Icon(_navItems[i].icon, color: socialNavAccentAt(i)),
                      selectedIcon: Icon(_navItems[i].selectedIcon,
                          color: socialNavAccentAt(i)),
                      label:
                          Text(AppLocale.of(context).t(_navItems[i].labelKey))),
              ],
            ),
            Expanded(child: content),
          ]),
        );
      }
      return Scaffold(
        key: _scaffoldKey,
        backgroundColor: ArucadColors.canvas,
        drawer: _NavDrawer(selectedIndex: _index, onSelect: _select),
        body: SafeArea(
          child: Column(
            children: [
              CampusPageHeader(
                title: _titleForIndex(context, _index),
                leading: _MenuButton(
                    onTap: () => _scaffoldKey.currentState?.openDrawer()),
                actions: [
                  if (_index == 0)
                    _MenuButton(
                      icon: Icons.notifications_none_rounded,
                      badgeCount: _unreadNotifications,
                      tooltip: AppLocale.of(context).t('social_notifications'),
                      onTap: _openNotifications,
                    ),
                ],
              ),
              Expanded(child: content),
            ],
          ),
        ),
      );
    });
  }
}

class _NavDrawer extends StatelessWidget {
  final int selectedIndex;
  final ValueChanged<int> onSelect;
  const _NavDrawer({required this.selectedIndex, required this.onSelect});

  @override
  Widget build(BuildContext context) {
    return Drawer(
      backgroundColor: ArucadColors.paper,
      child: SafeArea(
        child: ListView(
          padding: const EdgeInsets.symmetric(vertical: 12),
          children: [
            for (var i = 0; i < _navItems.length; i++)
              ListTile(
                leading: Icon(
                  i == selectedIndex
                      ? _navItems[i].selectedIcon
                      : _navItems[i].icon,
                  color: socialNavAccentAt(i),
                ),
                title: Text(AppLocale.of(context).t(_navItems[i].labelKey),
                    style: TextStyle(
                        color: ArucadColors.ink,
                        fontWeight: i == selectedIndex
                            ? FontWeight.w700
                            : FontWeight.w500)),
                selected: i == selectedIndex,
                selectedTileColor: ArucadColors.primary.withValues(alpha: .08),
                onTap: () => onSelect(i),
              ),
          ],
        ),
      ),
    );
  }
}

/// A header-row icon button — plain icon, no chip/circle chrome. Used for
/// both the ☰ drawer entry point and the feed tab's notifications action,
/// so both share the exact same 40×40 box in the same Row (see the
/// real-fix comment above where this is used for notifications).
class _MenuButton extends StatelessWidget {
  final VoidCallback onTap;
  final IconData icon;
  final String tooltip;
  final int badgeCount;
  const _MenuButton({
    required this.onTap,
    this.icon = Icons.menu,
    this.tooltip = 'Menü',
    this.badgeCount = 0,
  });

  @override
  Widget build(BuildContext context) {
    return IconButton(
      onPressed: onTap,
      tooltip: tooltip,
      padding: EdgeInsets.zero,
      constraints: const BoxConstraints(minWidth: 40, minHeight: 40),
      style: IconButton.styleFrom(
        backgroundColor: Colors.transparent,
        foregroundColor: ArucadColors.ink,
        highlightColor: Colors.transparent,
        hoverColor: Colors.transparent,
        splashFactory: NoSplash.splashFactory,
      ),
      icon: Stack(clipBehavior: Clip.none, children: [
        Icon(icon, size: 27),
        if (badgeCount > 0)
          Positioned(
            right: -5,
            top: -5,
            child: Container(
              constraints: const BoxConstraints(minWidth: 14, minHeight: 14),
              padding: const EdgeInsets.symmetric(horizontal: 3),
              alignment: Alignment.center,
              decoration: const BoxDecoration(
                color: ArucadColors.red,
                shape: BoxShape.circle,
              ),
              child: Text(
                badgeCount > 9 ? '9+' : '$badgeCount',
                style: const TextStyle(
                    color: Colors.white,
                    fontSize: 8,
                    fontWeight: FontWeight.w800),
              ),
            ),
          ),
      ]),
    );
  }
}
