import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/auth/app_settings_store.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/features/explore/explore_screen.dart';
import 'package:arucad_campus_prototype/features/guide/ask_arucad_screen.dart';
import 'package:arucad_campus_prototype/features/guide/guide_sheet.dart';
import 'package:arucad_campus_prototype/features/home/campus_live_map.dart';
import 'package:arucad_campus_prototype/features/home/home_screen.dart';
import 'package:arucad_campus_prototype/features/profile/profile_screen.dart';
import 'package:arucad_campus_prototype/features/quests/quests_screen.dart';
import 'package:arucad_campus_prototype/features/social/social_shell.dart';

class CampusShell extends StatefulWidget {
  final CampusUser user;
  final CampusRepository repository;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;
  final String initialLanguage;
  final UserRole role;
  final VoidCallback onLogout;

  const CampusShell({
    super.key,
    required this.user,
    required this.repository,
    required this.mapProvider,
    required this.analyticsTracker,
    this.initialLanguage = 'TR',
    this.role = UserRole.student,
    required this.onLogout,
  });

  @override
  State<CampusShell> createState() => _CampusShellState();
}

class _CampusShellState extends State<CampusShell> {
  int _index = 0;
  late String _language = widget.initialLanguage;
  CampusVisibility _visibility = CampusVisibility.ghost;
  bool _nearbyDiscoverable = false;
  bool _personalization = true;

  @override
  void initState() {
    super.initState();
    AppSettingsStore.locationVisibilityName().then((name) {
      if (mounted) {
        setState(() =>
            _visibility = CampusVisibility.values.byName(name));
      }
    });
    AppSettingsStore.nearbyDiscoverable().then((value) {
      if (mounted) setState(() => _nearbyDiscoverable = value);
    });
    AppSettingsStore.personalization().then((value) {
      if (mounted) setState(() => _personalization = value);
    });
  }

  @override
  Widget build(BuildContext context) {
    final lang = languageFromCode(_language);
    final strings = AppStrings(lang);
    return AppLocale(
      language: lang,
      child: Scaffold(
        body: SafeArea(
          child: IndexedStack(
            index: _index,
            children: [
              HomeScreen(
                user: widget.user,
                repository: widget.repository,
                mapProvider: widget.mapProvider,
                analyticsTracker: widget.analyticsTracker,
                onExplore: () => setState(() => _index = 1),
                onQuests: () => setState(() => _index = 4),
                onAI: _openGuide,
                onSocial: () => setState(() => _index = 2),
                onLogout: _confirmLogout,
                initialVisibility: _visibility,
                showForYou: _personalization,
              ),
              ExploreScreen(
                repository: widget.repository,
                mapProvider: widget.mapProvider,
                analyticsTracker: widget.analyticsTracker,
                onAI: _openGuide,
                initialVisibility: _visibility,
              ),
              SocialShell(repository: widget.repository),
              AskArucadScreen(
                repository: widget.repository,
                mapProvider: widget.mapProvider,
                analyticsTracker: widget.analyticsTracker,
              ),
              QuestsScreen(
                repository: widget.repository,
                user: widget.user,
                mapProvider: widget.mapProvider,
                analyticsTracker: widget.analyticsTracker,
                showSuggestions: _personalization,
              ),
              ProfileScreen(
                user: widget.user,
                repository: widget.repository,
                role: widget.role,
                language: _language,
                locationVisibility: _visibility,
                nearbyDiscoverable: _nearbyDiscoverable,
                personalization: _personalization,
                onLanguage: (value) {
                  setState(() => _language = value);
                  AppSettingsStore.setLanguage(value);
                },
                onLocationVisibility: (value) {
                  setState(() => _visibility = value);
                  AppSettingsStore.setLocationVisibilityName(value.name);
                },
                onNearbyDiscoverable: (value) {
                  setState(() => _nearbyDiscoverable = value);
                  AppSettingsStore.setNearbyDiscoverable(value);
                },
                onPersonalization: (value) {
                  setState(() => _personalization = value);
                  AppSettingsStore.setPersonalization(value);
                },
              ),
            ],
          ),
        ),
        bottomNavigationBar: _RootNavigationBar(
          selectedIndex: _index,
          onDestinationSelected: (value) => setState(() => _index = value),
          strings: strings,
        ),
      ),
    );
  }

  Future<void> _confirmLogout() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Çıkış yap'),
        content: const Text('Hesabından çıkış yapmak istediğine emin misin?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Çıkış Yap')),
        ],
      ),
    );
    if (confirmed == true) widget.onLogout();
  }

  void _openGuide() {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (context) => GuideSheet(
        repository: widget.repository,
        mapProvider: widget.mapProvider,
        analyticsTracker: widget.analyticsTracker,
        language: _language,
        onOpenFullChat: () {
          Navigator.of(context).pop();
          setState(() => _index = 3);
        },
      ),
    );
  }
}

/// The app's root 6-item bottom nav, tuned for small phones: a shorter bar
/// than Material's 80dp default, smaller icon/label sizes, and a shortened
/// "Arucad'a Sor" label below ~400px width so that one long label can't
/// force two-line wrapping (and the resulting overflow) while its five
/// short-label siblings stay on one line.
class _RootNavigationBar extends StatelessWidget {
  final int selectedIndex;
  final ValueChanged<int> onDestinationSelected;
  final AppStrings strings;

  const _RootNavigationBar({
    required this.selectedIndex,
    required this.onDestinationSelected,
    required this.strings,
  });

  @override
  Widget build(BuildContext context) {
    final narrow = MediaQuery.sizeOf(context).width < 400;
    return NavigationBarTheme(
      data: NavigationBarThemeData(
        iconTheme: WidgetStateProperty.resolveWith(
            (states) => IconThemeData(size: 22)),
      ),
      child: NavigationBar(
        height: 64,
        selectedIndex: selectedIndex,
        onDestinationSelected: onDestinationSelected,
        labelTextStyle: WidgetStateProperty.resolveWith((states) => TextStyle(
              fontSize: states.contains(WidgetState.selected) ? 11.5 : 11,
              fontWeight:
                  states.contains(WidgetState.selected) ? FontWeight.w700 : FontWeight.w500,
              height: 1.1,
            )),
        destinations: [
          NavigationDestination(
              icon: const Icon(Icons.home_outlined),
              selectedIcon: const Icon(Icons.home),
              label: strings.t('nav_home')),
          NavigationDestination(
              icon: const Icon(Icons.explore_outlined),
              selectedIcon: const Icon(Icons.explore),
              label: strings.t('nav_explore')),
          NavigationDestination(
              icon: const Icon(Icons.groups_outlined),
              selectedIcon: const Icon(Icons.groups),
              label: strings.t('nav_social')),
          NavigationDestination(
              icon: const Icon(Icons.smart_toy_outlined),
              selectedIcon: const Icon(Icons.smart_toy),
              label: strings.t(narrow ? 'nav_ask_short' : 'nav_ask')),
          NavigationDestination(
              icon: const Icon(Icons.emoji_events_outlined),
              selectedIcon: const Icon(Icons.emoji_events),
              label: strings.t('nav_quests')),
          NavigationDestination(
              icon: const Icon(Icons.person_outline),
              selectedIcon: const Icon(Icons.person),
              label: strings.t('nav_profile')),
        ],
      ),
    );
  }
}
